<?php namespace Layerok\PosterPos\Controllers;

use Backend;
use BackendMenu;
use Backend\Classes\Controller;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Layerok\PosterPos\Classes\Couriers\CourierOrders;
use Layerok\PosterPos\Classes\Delivery\OpenDeliveries;
use Layerok\PosterPos\Models\City;
use Layerok\PosterPos\Models\DeliveryEvent;
use Layerok\PosterPos\Models\Spot;

/**
 * Operator overview: every spot on one screen — open delivery receipts, the
 * late ones, online orders nobody has accepted, and what happened recently.
 *
 * The counts are read live from Poster on each refresh. The event feed is the
 * only stored part; it is written by the Poster webhook (DeliveryEventRecorder).
 */
class Operators extends Controller
{
    /**
     * A receipt counts as late once its promised time is this many minutes gone.
     */
    private const LATE_AFTER_MINUTES = 1;

    // today's latest; the feed scrolls inside its card (operators.css)
    private const EVENTS_LIMIT = 150;

    /**
     * Poster's processing_status for a delivery receipt.
     * Not confirmed against live data yet — an unknown code is shown as its number.
     */
    private const PROCESSING_LABELS = [
        10 => 'открыт',
        20 => 'готовится',
        30 => 'готов к выдаче',
        40 => 'в пути',
        50 => 'доставлен',
        60 => 'закрыт',
        70 => 'удалён',
    ];

    public $requiredPermissions = ['layerok.posterpos.operator'];

    private ?OpenDeliveries $deliveries = null;

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Layerok.PosterPos', 'delivery', 'delivery-operators');
    }

    public function index()
    {
        $this->pageTitle = 'Операторы';

        $this->addJs($this->asset('js/operators.js'), ['defer' => true]);
        $this->addCss($this->asset('css/operators.css'));

        $cityIds = $this->spots()->pluck('city_id')->filter()->unique();

        $this->vars['cities'] = City::whereIn('id', $cityIds)->orderBy('name')->get();
        $this->vars['mapUrl'] = Backend::url('layerok/posterpos/deliverymap');
    }

    public function onLoadOverview()
    {
        $spots = $this->spots(post('city_id') ?: null);
        $spotIds = $spots->pluck('poster_id')->map(fn($id) => (int) $id)->all();

        $now = OpenDeliveries::now();
        // A receipt opened before midnight and still not delivered is the most
        // overdue of all, and a today-only query would never show it.
        $from = $now->copy()->subDay()->toDateString();
        $to = $now->toDateString();

        $error = null;

        try {
            $transactions = $this->deliveries()->openTransactions($from, $to)
                ->filter(fn($t) => in_array((int) ($t->spot_id ?? 0), $spotIds, true))
                ->values();
            // Today's closed receipts only: Poster files them under the close date.
            $closed = $this->deliveries()->closedTransactions($to, $to)
                ->filter(fn($t) => in_array((int) ($t->spot_id ?? 0), $spotIds, true))
                ->values();
            $unaccepted = $this->deliveries()->unacceptedOrders($from, $to)
                ->filter(fn($o) => in_array((int) ($o->spot_id ?? 0), $spotIds, true))
                ->values();
            $couriers = $this->deliveries()->courierNames();
        } catch (\Throwable $e) {
            Log::error('Operator overview failed to load from Poster', ['error' => $e->getMessage()]);

            $error = $e->getMessage();
            $transactions = $closed = $unaccepted = collect();
            $couriers = [];
        }

        $late = $this->lateOrders($transactions, $spots, $couriers, $now);
        $lateIds = $late->pluck('id')->all();

        return [
            'error' => $error,
            'updated_at' => $now->format('H:i'),
            'late_after_minutes' => self::LATE_AFTER_MINUTES,
            'spots' => $spots->map(fn(Spot $spot) => $this->spotCard(
                $spot,
                $transactions,
                $unaccepted,
                $lateIds,
                $now
            ))->values(),
            'late' => $late,
            'unaccepted' => $this->unacceptedList($unaccepted, $spots, $now),
            'summary' => [
                'open' => $transactions->count(),
                'no_courier' => $transactions->filter(fn($t) => !OpenDeliveries::courierIdOf($t))->count(),
                'with_courier' => $transactions->filter(fn($t) => OpenDeliveries::courierIdOf($t))->count(),
                'late' => count($lateIds),
                'unaccepted' => $unaccepted->count(),
                'unaccepted_longest' => $this->longestWait($unaccepted, $now),
            ],
            'events' => $this->events($spots, $couriers, $now),
            'receipts' => $this->receiptsBySpot($transactions, $closed, $spots, $couriers, $now),
        ];
    }

    /**
     * The day's delivery receipts grouped by spot, for the "Чеки доставки" card:
     * open ones (from yesterday too, while still open) and the ones closed today.
     * Each has when it was accepted (opened on the register) and closed, and a
     * status the screen filters by: open, on_way (Poster's "в пути") or closed.
     */
    private function receiptsBySpot(Collection $open, Collection $closed, Collection $spots, array $couriers, Carbon $now): Collection
    {
        // A receipt closed between the two Poster calls comes in both; closed wins.
        $receipts = collect()
            ->merge($open->map(fn($t) => $this->receiptRow($t, false, $couriers, $now)))
            ->merge($closed->map(fn($t) => $this->receiptRow($t, true, $couriers, $now)))
            ->keyBy('id')
            ->sortByDesc('accepted_sort')
            ->values();

        return $spots
            ->map(fn(Spot $spot) => [
                'id' => $spot->id,
                'name' => $spot->name,
                'receipts' => $receipts
                    ->where('spot_poster_id', (int) $spot->poster_id)
                    ->map(fn($row) => collect($row)->except(['accepted_sort', 'spot_poster_id'])->all())
                    ->values(),
            ])
            ->values();
    }

    private function receiptRow(object $t, bool $isClosed, array $couriers, Carbon $now): array
    {
        $processing = (int) ($t->processing_status ?? 0);
        $acceptedAt = OpenDeliveries::posterTime($t->date_start ?? null);
        $closedAt = $isClosed
            ? (OpenDeliveries::posterTime($t->date_close_date ?? null) ?? OpenDeliveries::posterTime($t->date_close ?? null))
            : null;

        if ($isClosed) {
            $status = 'closed';
            $label = 'закрыт';
        } else {
            $status = $processing === CourierOrders::IN_DELIVERY ? 'on_way' : 'open';
            $label = self::PROCESSING_LABELS[$processing] ?? 'открыт';
        }

        return [
            'id' => (string) $t->transaction_id,
            'spot_poster_id' => (int) ($t->spot_id ?? 0),
            'status' => $status,
            'status_label' => $label,
            'accepted' => $this->clock($acceptedAt, $now),
            'closed' => $this->clock($closedAt, $now),
            'accepted_sort' => $acceptedAt ? $acceptedAt->getTimestamp() : 0,
            'address' => OpenDeliveries::addressOf($t),
            'client' => OpenDeliveries::clientName($t->client_firstname ?? null, $t->client_lastname ?? null),
            'courier' => OpenDeliveries::courierLabel(OpenDeliveries::courierIdOf($t), $couriers),
            'amount' => OpenDeliveries::amountOf($t),
        ];
    }

    /**
     * "18:05" today, "13.11 23:40" for another day, null without a time.
     */
    private function clock(?Carbon $time, Carbon $now): ?string
    {
        if (!$time) {
            return null;
        }

        return $time->isSameDay($now) ? $time->format('H:i') : $time->format('d.m H:i');
    }

    private function spotCard(Spot $spot, Collection $transactions, Collection $unaccepted, array $lateIds, Carbon $now): array
    {
        $own = $transactions->filter(fn($t) => (int) ($t->spot_id ?? 0) === (int) $spot->poster_id);
        $waiting = $unaccepted->filter(fn($o) => (int) ($o->spot_id ?? 0) === (int) $spot->poster_id);

        $noCourier = $own->filter(fn($t) => !OpenDeliveries::courierIdOf($t))->count();
        $late = $own->filter(fn($t) => in_array((string) $t->transaction_id, $lateIds, true))->count();

        return [
            'id' => $spot->id,
            'poster_id' => (int) $spot->poster_id,
            'name' => $spot->name,
            'city' => $spot->city->name ?? '',
            'open' => $own->count(),
            'no_courier' => $noCourier,
            'late' => $late,
            'unaccepted' => $waiting->count(),
            'unaccepted_longest' => $this->longestWait($waiting, $now),
            'alert' => $noCourier > 0 || $late > 0 || $waiting->isNotEmpty(),
        ];
    }

    private function lateOrders(Collection $transactions, Collection $spots, array $couriers, Carbon $now): Collection
    {
        $names = $spots->mapWithKeys(fn(Spot $s) => [(int) $s->poster_id => $s->name]);

        return $transactions
            ->map(function ($t) use ($now, $names, $couriers) {
                $due = OpenDeliveries::dueAt($t);

                if (!$due || $now->lt($due->copy()->addMinutes(self::LATE_AFTER_MINUTES))) {
                    return null;
                }

                $courierId = OpenDeliveries::courierIdOf($t);

                return [
                    'id' => (string) $t->transaction_id,
                    'spot' => $names[(int) ($t->spot_id ?? 0)] ?? '',
                    'address' => OpenDeliveries::addressOf($t),
                    'client' => OpenDeliveries::clientName($t->client_firstname ?? null, $t->client_lastname ?? null),
                    'phone' => $t->client_phone ?? '',
                    'amount' => OpenDeliveries::amountOf($t),
                    'due' => $due->isSameDay($now) ? $due->format('H:i') : $due->format('d.m H:i'),
                    'late_minutes' => (int) $due->diffInMinutes($now),
                    'courier' => OpenDeliveries::courierLabel($courierId, $couriers),
                ];
            })
            ->filter()
            ->sortByDesc('late_minutes')
            ->values();
    }

    private function unacceptedList(Collection $orders, Collection $spots, Carbon $now): Collection
    {
        $names = $spots->mapWithKeys(fn(Spot $s) => [(int) $s->poster_id => $s->name]);

        return $orders
            ->map(function ($o) use ($names, $now) {
                $created = OpenDeliveries::localTime($o->created_at ?? null);

                return [
                    'id' => (string) ($o->incoming_order_id ?? ''),
                    'spot' => $names[(int) ($o->spot_id ?? 0)] ?? '',
                    'address' => (string) ($o->address ?? ''),
                    'client' => OpenDeliveries::clientName($o->first_name ?? null, $o->last_name ?? null),
                    'phone' => $o->phone ?? '',
                    'created' => $created ? $created->format('H:i') : '',
                    'waiting_minutes' => $created ? (int) $created->diffInMinutes($now) : null,
                ];
            })
            ->sortByDesc('waiting_minutes')
            ->values();
    }

    /**
     * Minutes the oldest unaccepted order has been waiting, or null if none.
     */
    private function longestWait(Collection $orders, Carbon $now): ?int
    {
        $oldest = $orders
            ->map(fn($o) => OpenDeliveries::localTime($o->created_at ?? null))
            ->filter()
            ->sort()
            ->first();

        return $oldest ? (int) $oldest->diffInMinutes($now) : null;
    }

    /**
     * Today's events only (Kyiv day); happened_at is stored in UTC.
     */
    private function events(Collection $spots, array $couriers, Carbon $now): Collection
    {
        $names = $spots->mapWithKeys(fn(Spot $s) => [(int) $s->poster_id => $s->name]);
        $today = $now;

        return DeliveryEvent::whereIn('spot_poster_id', $names->keys()->all())
            ->where('happened_at', '>=', $now->copy()->startOfDay()->setTimezone('UTC'))
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->limit(self::EVENTS_LIMIT)
            ->get()
            ->map(function (DeliveryEvent $event) use ($names, $couriers, $today) {
                $at = $event->happened_at->copy()->setTimezone(OpenDeliveries::TIMEZONE);

                return [
                    'id' => $event->id,
                    'time' => $at->isSameDay($today) ? $at->format('H:i') : $at->format('d.m H:i'),
                    'spot' => $names[(int) $event->spot_poster_id] ?? '',
                ] + $this->describe($event, $couriers);
            })
            ->values();
    }

    /**
     * Headline, detail line and badge for one event.
     */
    private function describe(DeliveryEvent $event, array $couriers): array
    {
        $receipt = '#' . $event->transaction_id;
        $courier = OpenDeliveries::courierLabel($event->courier_id ? (int) $event->courier_id : null, $couriers);
        $previous = OpenDeliveries::courierLabel($event->previous_courier_id ? (int) $event->previous_courier_id : null, $couriers);
        $address = (string) $event->address;

        switch ($event->type) {
            case DeliveryEvent::TYPE_OPENED:
                return $courier
                    ? $this->line("$receipt — новый чек доставки", "Курьер: $courier · $address", 'Назначен', 'ok')
                    : $this->line("$receipt — новый чек доставки", "Курьер ещё не назначен · $address", 'Требует внимания', 'warn');
            case DeliveryEvent::TYPE_COURIER_ASSIGNED:
                return $this->line("$receipt назначен на $courier", $address, 'Назначен', 'ok');
            case DeliveryEvent::TYPE_COURIER_CHANGED:
                return $this->line("$receipt переназначен: $previous → $courier", $address, 'Переназначен', 'ok');
            case DeliveryEvent::TYPE_COURIER_REMOVED:
                return $this->line("$receipt: курьер $previous снят", $address, 'Без курьера', 'warn');
            case DeliveryEvent::TYPE_STATUS_CHANGED:
                $label = self::PROCESSING_LABELS[(int) $event->processing_status] ?? ('статус ' . $event->processing_status);

                return $this->line("$receipt — $label", $courier ? "Курьер: $courier · $address" : $address, 'Статус', 'info');
            case DeliveryEvent::TYPE_CLOSED:
                return $this->line("$receipt закрыт", $courier ? "Курьер: $courier" : $address, 'Готово', 'ok');
            case DeliveryEvent::TYPE_REMOVED:
                return $this->line("$receipt удалён", $address, 'Удалён', 'muted');
            case DeliveryEvent::TYPE_CANCELED:
                return $this->line("$receipt отменён", $address, 'Отменён', 'muted');
            case DeliveryEvent::TYPE_INCOMING_NEW:
                return $this->line("Онлайн-заказ #{$event->incoming_order_id} ожидает принятия", $address, 'Не принят', 'warn');
            case DeliveryEvent::TYPE_INCOMING_ACCEPTED:
                return $this->line(
                    "Онлайн-заказ #{$event->incoming_order_id} принят" . ($event->transaction_id ? " → $receipt" : ''),
                    $address,
                    'Принят',
                    'ok'
                );
            case DeliveryEvent::TYPE_INCOMING_CANCELED:
                return $this->line("Онлайн-заказ #{$event->incoming_order_id} отменён", $address, 'Отменён', 'muted');
        }

        return $this->line($event->type, $address, '', 'muted');
    }

    private function line(string $title, string $detail, string $badge, string $tone): array
    {
        return [
            'title' => $title,
            'detail' => trim($detail, " ·"),
            'badge' => $badge,
            'tone' => $tone,
        ];
    }

    private function spots($cityId = null): Collection
    {
        return Spot::with('city')
            ->where('published', true)
            ->whereNotNull('poster_id')
            ->when($cityId, fn($q) => $q->where('city_id', $cityId))
            ->orderBy('name')
            ->get();
    }

    private function deliveries(): OpenDeliveries
    {
        return $this->deliveries ?: ($this->deliveries = new OpenDeliveries());
    }

    /**
     * Same cache-busting asset helper as DeliveryMap; see the note there on why
     * these live in the plugin's own assets directory.
     */
    private function asset(string $relative): string
    {
        $path = '/plugins/layerok/posterpos/assets/' . $relative;
        $file = base_path(ltrim($path, '/'));

        return $path . (file_exists($file) ? '?v=' . filemtime($file) : '');
    }
}
