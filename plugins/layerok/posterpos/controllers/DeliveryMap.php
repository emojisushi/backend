<?php namespace Layerok\PosterPos\Controllers;

use BackendMenu;
use Backend\Classes\Controller;
use Layerok\PosterPos\Classes\GeocodeService;
use Illuminate\Support\Facades\Log;
use Layerok\PosterPos\Models\Address;
use Layerok\PosterPos\Models\Area;
use Layerok\PosterPos\Models\Courier;
use Layerok\PosterPos\Models\OnlineOrder;
use Layerok\PosterPos\Models\Spot;
use poster\src\PosterApi;

/**
 * Live delivery map. Open delivery receipts are read from Poster; the zones are
 * the same polygons the checkout uses to decide which spot serves an address.
 */
class DeliveryMap extends Controller
{
    /**
     * Show online orders the register has not accepted yet, alongside the open
     * receipts. Off for now; the whole path is kept so it is one flag to revive.
     *
     * Those orders are not transactions, so they come from
     * incomingOrders.getIncomingOrders instead of dash.getTransactions and carry
     * neither a total nor coordinates — see pendingOrders() for the detail.
     */
    private const SHOW_PENDING_ACCEPT = false;

    private ?GeocodeService $geocoder = null;

    public $requiredPermissions = ['layerok.posterpos.delivery_map'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Layerok.PosterPos', 'delivery', 'delivery-map');
    }

    public function index()
    {
        $this->pageTitle = 'Карта заказов';

        $this->addJs('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
        $this->addCss('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        $this->addJs($this->asset('js/delivery-map.js'), ['defer' => true]);
        $this->addCss($this->asset('css/delivery-map.css'));

        // Unpublished spots are not serving customers, so they are not worth filtering by.
        $this->vars['spots'] = Spot::where('published', true)->orderBy('name')->get();
        $this->vars['today'] = date('Y-m-d');
        $this->vars['showPendingAccept'] = self::SHOW_PENDING_ACCEPT;
    }

    /**
     * Delivery zones for the map overlay. The zones cover the whole city rather
     * than one spot, so they are not filtered — only the orders are.
     */
    /**
     * Asset URL stamped with the file's modified time, so an edited stylesheet is
     * never served from the browser cache after a deploy.
     *
     * These live in the plugin's own assets directory on purpose: october:mirror
     * only symlinks plugins/[vendor]/[plugin]/assets into the public folder, so anything
     * under controllers/[name]/assets is a 404 on a mirrored deployment.
     */
    private function asset(string $relative): string
    {
        $path = '/plugins/layerok/posterpos/assets/' . $relative;
        $file = base_path(ltrim($path, '/'));

        return $path . (file_exists($file) ? '?v=' . filemtime($file) : '');
    }

    public function onLoadAreas()
    {
        return [
            'areas' => Area::all()->map(fn(Area $area) => [
                'id' => $area->id,
                'name' => $area->name,
                'color' => $area->color,
                'coords' => $area->coords,
            ])->values(),
        ];
    }

    /**
     * Open delivery receipts for one spot and date, straight from Poster.
     *
     * Only receipts still open are returned, so a date in the past shows what was
     * left hanging rather than everything that was ever delivered.
     */
    public function onLoadOrders()
    {
        $spot = Spot::find(post('spot_id'));
        $date = post('date') ?: date('Y-m-d');
        $ymd = date('Ymd', strtotime($date));

        $params = [
            'dateFrom' => $ymd,
            'dateTo' => $ymd,
            'status' => 1,                 // open receipts only
            'service_mode' => 3,           // delivery
            'include_delivery' => 'true',
        ];

        if ($spot && $spot->poster_id) {
            $params['type'] = 'spots';
            $params['id'] = $spot->poster_id;
        }

        try {
            $transactions = collect($this->posterRequest(fn() => PosterApi::dash()->getTransactions($params)) ?: []);
            $pending = self::SHOW_PENDING_ACCEPT
                ? $this->pendingOrders($spot, $date)
                : collect();
            $couriers = $this->courierNames();
        } catch (\Throwable $e) {
            Log::error('Delivery map failed to load orders', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage(), 'orders' => []];
        }

        $streets = $transactions
            ->map(fn($transaction) => $this->streetOf((object) (((object) $transaction)->delivery ?? null)))
            ->merge($pending->map(fn($order) => $this->streetFromLine(((object) $order)->address ?? '')));

        $coords = $this->streetCoordinates($streets);

        $orders = $transactions
            ->map(fn($transaction) => $this->presentOrder((object) $transaction, $couriers, $coords))
            ->merge($pending->map(fn($order) => $this->presentPendingOrder((object) $order, $coords)))
            ->values();

        return [
            'orders' => $orders,
            'couriers' => Courier::active()->whereNotNull('poster_user_id')->orderBy('name')
                ->get(['poster_user_id', 'name'])
                ->map(fn(Courier $c) => ['id' => (int) $c->poster_user_id, 'name' => $c->name])
                ->values(),
        ];
    }

    /**
     * Online orders the register has not accepted yet. They are not transactions,
     * so dash.getTransactions cannot see them at all.
     */
    private function pendingOrders(?Spot $spot, string $date)
    {
        $rows = collect($this->posterRequest(fn() => PosterApi::incomingOrders()->getIncomingOrders([
            'status' => 0,                 // new, not accepted
            'date_from' => $date . ' 00:00:00',
            'date_to' => $date . ' 23:59:59',
        ])) ?: []);

        return $rows->filter(function ($order) use ($spot) {
            $order = (object) $order;

            if ((int) ($order->service_mode ?? 0) !== 3) {
                return false;
            }

            return !$spot || !$spot->poster_id || (int) ($order->spot_id ?? 0) === (int) $spot->poster_id;
        })->values();
    }

    /**
     * An unaccepted order carries no delivery record and no total, so the amount
     * is taken from our own copy of the order where we have one.
     */
    private function presentPendingOrder(object $order, array $coords): array
    {
        $line = (string) ($order->address ?? '');
        $street = $this->streetFromLine($line);
        // No delivery record on an unaccepted order, so only the street is known.
        $point = $street !== null && isset($coords[$street])
            ? $coords[$street] + ['source' => 'street']
            : ['lat' => null, 'lng' => null, 'source' => null];

        $client = collect([$order->first_name ?? null, $order->last_name ?? null])
            ->filter(fn($part) => trim((string) $part) !== '')
            ->join(' ');

        $local = OnlineOrder::where('poster_id', $order->incoming_order_id ?? null)->first();

        return [
            'id' => (string) ($order->incoming_order_id ?? ''),
            'key' => 'incoming:' . ($order->incoming_order_id ?? ''),
            'pending_accept' => true,
            'client' => $client ?: '—',
            'phone' => $order->phone ?? '',
            'amount' => $local ? (int) round(((int) $local->total) / 100) : null,
            'address' => $line,
            'place' => '',
            'comment' => trim((string) ($order->comment ?? '')),
            'due' => !empty($order->created_at) ? date('H:i', strtotime($order->created_at)) : '',
            'lat' => $point['lat'],
            'location_source' => $point['source'] ?? null,
            'lng' => $point['lng'],
            'courier_id' => null,
            'courier' => null,
            'processing_status' => 0,
        ];
    }

    private function streetFromLine(string $line): ?string
    {
        $street = trim(explode(',', trim($line))[0]);

        return $street !== '' ? $street : null;
    }

    private function presentOrder(object $transaction, array $couriers, array $coords = []): array
    {
        $delivery = (object) ($transaction->delivery ?? []);
        $courierId = isset($delivery->courier_id) ? (int) $delivery->courier_id : 0;

        $address = collect([$delivery->address1 ?? null, $delivery->address2 ?? null])
            ->filter(fn($part) => trim((string) $part) !== '')
            ->join(', ');

        $client = collect([$transaction->client_firstname ?? null, $transaction->client_lastname ?? null])
            ->filter(fn($part) => trim((string) $part) !== '')
            ->join(' ');

        $point = $this->resolvePoint($delivery, $coords);

        return [
            'id' => (string) ($transaction->transaction_id ?? ''),
            'key' => 'transaction:' . ($transaction->transaction_id ?? ''),
            'pending_accept' => false,
            'client' => $client ?: '—',
            'phone' => $transaction->client_phone ?? '',
            'amount' => (int) round(((int) ($transaction->sum ?? 0)) / 100),
            'address' => $address ?: ($delivery->comment ?? ''),
            'place' => $delivery->comment ?? '',
            'comment' => trim((string) ($transaction->transaction_comment ?? '')),
            'due' => !empty($delivery->delivery_time) ? date('H:i', strtotime($delivery->delivery_time)) : '',
            'lat' => $point['lat'],
            'location_source' => $point['source'] ?? null,
            'lng' => $point['lng'],
            'courier_id' => $courierId ?: null,
            'courier' => $courierId ? ($couriers[$courierId] ?? ('ID ' . $courierId)) : null,
            'processing_status' => (int) ($transaction->processing_status ?? 0),
        ];
    }
    /**
     * Poster does not always return coordinates for a delivery, so the street is
     * matched back against our own address book, which is where the coordinates
     * came from in the first place. Street level, not house level.
     */
    /**
     * Coordinates for the streets these orders are on.
     *
     * Several streets share a name across the city, so every record is indexed
     * both by "street|suburb" and by street alone. The caller tries the specific
     * key first; the bare street is only a last resort and may be the wrong one
     * of a set of namesakes.
     */
    private function streetCoordinates($streets): array
    {
        $streets = collect($streets)->filter()->unique()->values();

        if ($streets->isEmpty()) {
            return [];
        }

        $coords = [];

        foreach (Address::whereIn('name_ua', $streets->all())->get(['name_ua', 'suburb_ua', 'lat', 'lon']) as $address) {
            $point = [
                'lat' => $address->lat !== null ? (float) $address->lat : null,
                'lng' => $address->lon !== null ? (float) $address->lon : null,
            ];

            $coords[$this->coordKey($address->name_ua, $address->suburb_ua)] = $point;

            if (!isset($coords[$address->name_ua])) {
                $coords[$address->name_ua] = $point;
            }
        }

        return $coords;
    }

    private function coordKey(?string $street, ?string $suburb): string
    {
        return trim((string) $street) . '|' . trim((string) $suburb);
    }

    /**
     * The district, which buildClientAddress writes into the address comment as
     * "<city>, <suburb>" and Poster returns verbatim.
     */
    private function suburbOf($delivery): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) ($delivery->comment ?? ''))));

        return $parts ? (string) end($parts) : null;
    }

    /**
     * The street part of a delivery address, i.e. everything before the house
     * number that buildClientAddress appended.
     */
    private function streetOf($delivery): ?string
    {
        $line = trim((string) ($delivery->address1 ?? ''));

        if ($line === '') {
            return null;
        }

        $street = trim(explode(',', $line)[0]);

        return $street !== '' ? $street : null;
    }

    /**
     * Best point available, in descending order of precision: what Poster stored,
     * the geocoded building, then the street the address book knows.
     */
    private function resolvePoint($delivery, array $coords): array
    {
        if (isset($delivery->lat, $delivery->lng) && $delivery->lat !== null && $delivery->lng !== null) {
            return ['lat' => (float) $delivery->lat, 'lng' => (float) $delivery->lng, 'source' => 'building'];
        }

        $street = $this->streetOf($delivery);
        $suburb = $this->suburbOf($delivery);

        if ($street === null) {
            return ['lat' => null, 'lng' => null, 'source' => null];
        }

        // The street in its own district first — a bare street name can match the
        // wrong one of several namesakes.
        $specific = $this->coordKey($street, $suburb);
        $known = ($suburb !== null && isset($coords[$specific]))
            ? $coords[$specific]
            : ($coords[$street] ?? ['lat' => null, 'lng' => null]);

        // The street we know is both the fallback and the yardstick the geocoder
        // uses to judge its candidates.
        $building = $this->geocoder()->coordinatesFor($street, $this->houseOf($delivery), $known);

        if ($building) {
            return $building + ['source' => 'building'];
        }

        return $known + ['source' => $known['lat'] !== null ? 'street' : null];
    }

    /**
     * The house number, which buildClientAddress appends after the street name.
     */
    private function houseOf($delivery): ?string
    {
        $parts = array_map('trim', explode(',', trim((string) ($delivery->address1 ?? ''))));

        return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
    }

    private function geocoder(): GeocodeService
    {
        return $this->geocoder ?: ($this->geocoder = new GeocodeService());
    }

    /**
     * Couriers come from our own registry rather than Poster, because Poster has
     * no way to say which employees are couriers. An id with no registry entry is
     * shown as a bare id, which is the signal that a mapping is missing.
     */
    private function courierNames(): array
    {
        return Courier::whereNotNull('poster_user_id')
            ->get(['poster_user_id', 'name'])
            ->mapWithKeys(fn(Courier $courier) => [(int) $courier->poster_user_id => $courier->name])
            ->all();
    }

    private function posterRequest(callable $request)
    {
        PosterApi::init(config('poster'));

        $result = (object) $request();

        if (isset($result->error)) {
            throw new \RuntimeException('Poster: ' . ($result->message ?? $result->error));
        }

        return $result->response ?? [];
    }
}
