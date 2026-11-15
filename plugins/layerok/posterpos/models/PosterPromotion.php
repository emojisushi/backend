<?php

namespace Layerok\PosterPos\Models;

use Layerok\PosterPos\Classes\Promotions\CategoryGiftRule;
use Layerok\PosterPos\Classes\Promotions\OrderDiscountRule;
use Layerok\PosterPos\Classes\Promotions\PromotionSchedule;
use Layerok\PosterPos\Classes\Promotions\PromotionWindow;
use Model;

/**
 * A Poster loyalty promotion (clients.getPromotions), copied in by the import
 * button. The backend applies it to online orders only once it is enabled, and it
 * can only be enabled when its params have a shape the backend knows how to apply.
 *
 * Not to be confused with Promotion, the marketing promotion shown in the apps.
 *
 * @property int $id
 * @property int $poster_account_id
 * @property int $poster_promotion_id
 * @property string $name
 * @property array $params
 * @property string|null $date_start
 * @property string|null $date_end
 * @property bool $auto_apply
 * @property bool $removed_in_poster
 * @property bool $enabled
 */
class PosterPromotion extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'layerok_posterpos_poster_promotions';

    protected $fillable = [
        'poster_account_id',
        'poster_promotion_id',
        'name',
        'params',
        'date_start',
        'date_end',
        'auto_apply',
        'removed_in_poster',
        'enabled',
        'imported_at',
        'gift',
        'category_name',
        'active_from',
        'active_to',
        'active_days',
        'qualifying',
        'order_types',
        'accrual_bonuses',
    ];

    // ISO week days, for labels and the admin form
    public const DAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    public $rules = [
        'active_from' => ['nullable', 'regex:' . PromotionWindow::TIME_PATTERN, 'required_with:active_to'],
        'active_to' => ['nullable', 'regex:' . PromotionWindow::TIME_PATTERN, 'required_with:active_from'],
    ];

    public $customMessages = [
        'active_from.regex' => 'Время «с» — в формате ЧЧ:ММ, например 11:00',
        'active_to.regex' => 'Время «до» — в формате ЧЧ:ММ, например 22:00',
        'active_from.required_with' => 'Укажите оба времени или оставьте оба пустыми',
        'active_to.required_with' => 'Укажите оба времени или оставьте оба пустыми',
    ];

    // gift: Poster snapshot of the gift product, see PosterPromotionImporter::snapshot()
    // qualifying, in Poster ids, see PosterPromotionImporter:
    //   gift: {category_id, product_ids}; a discount has none (it covers the whole
    //   cart); an old import may have left {nodiscount_*} there, which is unused
    // active_days: ISO week days the promotion applies on, [] for every day
    protected $jsonable = ['params', 'gift', 'qualifying', 'active_days'];

    protected $casts = [
        'auto_apply' => 'boolean',
        'removed_in_poster' => 'boolean',
        'enabled' => 'boolean',
        // Poster's «Начислять бонусы» for the promotion
        'accrual_bonuses' => 'boolean',
    ];

    public $belongsTo = [
        'poster_account' => PosterAccount::class,
    ];

    public const ORDER_TYPES_ALL = 'all';
    public const ORDER_TYPES_TAKEAWAY = 'takeaway';
    public const ORDER_TYPES_COURIER = 'courier';

    /**
     * "N from a category, get a product free", or null when it is not that.
     */
    public function giftRule(): ?CategoryGiftRule
    {
        return CategoryGiftRule::fromParams((array) $this->params);
    }

    /**
     * "N% off the order", or null when it is not that.
     */
    public function discountRule(): ?OrderDiscountRule
    {
        return $this->giftRule() ? null : OrderDiscountRule::fromParams((array) $this->params);
    }

    public function isGift(): bool
    {
        return $this->giftRule() !== null;
    }

    public function isDiscount(): bool
    {
        return $this->discountRule() !== null;
    }

    public function isImplemented(): bool
    {
        return !$this->removed_in_poster && ($this->isGift() || $this->isDiscount());
    }

    /**
     * Why the backend cannot apply it, or null when it can.
     */
    public function unsupportedReason(): ?string
    {
        if ($this->removed_in_poster) {
            return 'Removed in Poster';
        }

        $params = (array) $this->params;

        if ($this->isGift() || $this->isDiscount()) {
            return null;
        }

        // The reason of the kind it looks like.
        return (int) ($params['result_type'] ?? 0) === 3
            ? OrderDiscountRule::unsupportedReason($params)
            : CategoryGiftRule::unsupportedReason($params);
    }

    /**
     * all / takeaway / courier, or null when not chosen. A gift with none chosen
     * applies to all orders; a discount must have it chosen to be enabled, since
     * nothing in Poster says which orders it is for.
     */
    public function orderTypes(): ?string
    {
        $value = (string) $this->order_types;

        if (in_array($value, [self::ORDER_TYPES_ALL, self::ORDER_TYPES_TAKEAWAY, self::ORDER_TYPES_COURIER], true)) {
            return $value;
        }

        return $this->isGift() ? self::ORDER_TYPES_ALL : null;
    }

    /**
     * Whether it applies to an order of this type (takeaway / courier).
     */
    public function appliesToOrderType(string $orderType): bool
    {
        $types = $this->orderTypes();

        return $types === self::ORDER_TYPES_ALL || $types === $orderType;
    }

    public function getOrderTypesOptions(): array
    {
        return [
            '' => '— не выбрано —',
            self::ORDER_TYPES_ALL => 'Все заказы',
            self::ORDER_TYPES_TAKEAWAY => 'Только самовывоз',
            self::ORDER_TYPES_COURIER => 'Только доставка курьером',
        ];
    }

    /**
     * Why it cannot be (or stay) enabled with its current settings, or null.
     *  - a discount needs its order types chosen, since Poster does not say;
     *  - only one discount per order type and account, since there is no telling
     *    which one Poster would pick.
     */
    public function enableProblem(): ?string
    {
        // No points are earned on a promotion order. Ours are held back by the
        // backend (BonusService::accrueWithheld); Poster's own accrual follows this
        // setting of the promotion, so it has to be off there.
        if ($this->accrual_bonuses) {
            return 'В Poster у акции «' . $this->name . '» включено начисление бонусов — выключите его в Poster и нажмите «Импортировать из Poster»: по акционным заказам бонусы не начисляются';
        }

        if (!$this->isDiscount()) {
            return null;
        }

        $types = $this->orderTypes();

        if (!$types) {
            return 'Выберите, к каким заказам применять скидку «' . $this->name . '»: в Poster это не указано';
        }

        $other = self::enabled()
            ->where('poster_account_id', $this->poster_account_id)
            ->where('id', '!=', (int) $this->id)
            ->get()
            ->first(fn(self $promotion) => $promotion->isDiscount()
                && ($promotion->orderTypes() === self::ORDER_TYPES_ALL
                    || $types === self::ORDER_TYPES_ALL
                    || $promotion->orderTypes() === $types));

        return $other
            ? 'Уже включена скидка «' . $other->name . '» для этих заказов: одновременно может действовать только одна скидка'
            : null;
    }

    public function getOrderTypesLabelAttribute(): string
    {
        $types = $this->orderTypes();

        return $types ? $this->getOrderTypesOptions()[$types] : 'не выбрано';
    }

    /**
     * Poster's own schedule (dates, week days, periods) and ours (days and time).
     */
    public function isRunningAt(\DateTimeInterface $now): bool
    {
        return PromotionWindow::isOpen($this->active_from, $this->active_to, $now, $this->activeDays())
            && $this->isScheduledInPosterAt($now);
    }

    /**
     * Poster's schedule alone, without our days and time. The apps get promotions
     * on this basis and apply ours themselves, so a gift shows up and goes away on
     * time without reloading.
     */
    public function isScheduledInPosterAt(\DateTimeInterface $now): bool
    {
        $params = (array) $this->params;

        return PromotionSchedule::isActive(
            $this->date_start ? (string) $this->date_start : null,
            $this->date_end ? (string) $this->date_end : null,
            (array) ($params['week_days'] ?? []),
            (array) ($params['periods'] ?? []),
            $now
        );
    }

    public function hasWindow(): bool
    {
        return PromotionWindow::hasWindow($this->active_from, $this->active_to);
    }

    /**
     * ISO week days it applies on, [] for every day.
     */
    public function activeDays(): array
    {
        return PromotionWindow::normalizeDays($this->active_days);
    }

    /**
     * Whether any of our own limits (days or time) is set.
     */
    public function hasSchedule(): bool
    {
        return $this->hasWindow() || (bool) $this->activeDays();
    }

    /**
     * "Пн, Ср, Пт 11:00 — 22:00", "каждый день 11:00 — 22:00", "Сб, Вс весь день".
     */
    public function getWindowLabelAttribute(): string
    {
        $days = $this->activeDays();
        $dayLabel = $days
            ? implode(', ', array_map(fn($day) => self::DAYS[$day], $days))
            : 'каждый день';

        return $dayLabel . ' ' . ($this->hasWindow() ? $this->active_from . ' — ' . $this->active_to : 'весь день');
    }

    public function getActiveDaysOptions(): array
    {
        return self::DAYS;
    }

    public function beforeSave()
    {
        foreach (['active_from', 'active_to'] as $field) {
            $value = trim((string) $this->{$field});
            $this->{$field} = $value === '' ? null : $value;
        }

        $this->active_days = $this->activeDays();

        if (trim((string) $this->order_types) === '') {
            $this->order_types = null;
        }

        // An enabled discount edited into a state it could not be enabled in.
        if ($this->enabled && !$this->removed_in_poster && ($problem = $this->enableProblem())) {
            throw new \October\Rain\Exception\ValidationException(['order_types' => $problem]);
        }
    }

    /**
     * Poster spot ids (Spot::$poster_id) the promotion is available at.
     */
    public function posterSpotIds(): array
    {
        $spots = (array) (((array) $this->params)['available_for_spots'] ?? []);

        return array_map('intval', array_keys(array_filter($spots, fn($on) => (int) $on === 1)));
    }

    public function isAvailableAtSpot(?Spot $spot): bool
    {
        return $spot && $spot->poster_id && in_array((int) $spot->poster_id, $this->posterSpotIds(), true);
    }

    /**
     * Our spots it is available at. Poster spot ids are per account, so only the
     * spots that send their orders to this promotion's account count — the same
     * spot->tablet->poster_account that order placement uses.
     */
    public function spots()
    {
        $ids = $this->posterSpotIds();

        if (!$ids) {
            return Spot::whereRaw('1 = 0')->get();
        }

        return Spot::whereIn('poster_id', $ids)
            ->whereHas('tablet', fn($query) => $query->where('poster_account_id', $this->poster_account_id))
            ->get();
    }

    /**
     * Poster product ids that count towards the promotion: the products whose
     * Poster category is the promotion's category. Poster gives a product exactly
     * one category, so a combo in «Комбо Набори» never counts as a pizza here,
     * whatever categories it has on our side.
     */
    public function qualifyingPosterProductIds(): array
    {
        return array_values(array_map('intval', (array) (((array) $this->qualifying)['product_ids'] ?? [])));
    }

    /**
     * Whether the import has worked out the gift's qualifying products. A discount
     * covers the whole cart and needs no list, so it always has them.
     */
    public function hasQualifyingProducts(): bool
    {
        return $this->isDiscount() || isset(((array) $this->qualifying)['product_ids']);
    }

    public function giftName(): ?string
    {
        $name = trim((string) (((array) $this->gift)['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * The gift's regular price in kopecks, shown crossed out next to "Gift".
     */
    public function giftPrice(?Spot $spot = null): ?int
    {
        $prices = (array) (((array) $this->gift)['prices'] ?? []);

        if ($spot && $spot->poster_id && isset($prices[(string) $spot->poster_id])) {
            return (int) $prices[(string) $spot->poster_id];
        }

        return $prices ? (int) reset($prices) : null;
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true)->where('removed_in_poster', false);
    }

    public function getPeriodLabelAttribute(): string
    {
        $from = $this->date_start ? date('d.m.Y', strtotime($this->date_start)) : '…';
        $to = $this->date_end ? date('d.m.Y', strtotime($this->date_end)) : 'без конца';

        return $from . ' — ' . $to;
    }

    public function getSpotsLabelAttribute(): string
    {
        $ids = $this->posterSpotIds();

        if (!$ids) {
            return '—';
        }

        $names = $this->spots()->pluck('name')->all();

        return $names ? implode(', ', $names) : 'Poster: ' . implode(', ', $ids);
    }

    public function getImplementedLabelAttribute(): string
    {
        $reason = $this->unsupportedReason();

        return $reason === null ? 'Да' : 'Нет: ' . $reason;
    }
}
