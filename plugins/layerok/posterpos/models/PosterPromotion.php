<?php

namespace Layerok\PosterPos\Models;

use Layerok\PosterPos\Classes\Promotions\CategoryGiftRule;
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
    ];

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
    protected $jsonable = ['params', 'gift'];

    protected $casts = [
        'auto_apply' => 'boolean',
        'removed_in_poster' => 'boolean',
        'enabled' => 'boolean',
    ];

    public $belongsTo = [
        'poster_account' => PosterAccount::class,
    ];

    public function rule(): ?CategoryGiftRule
    {
        return CategoryGiftRule::fromParams((array) $this->params);
    }

    public function isImplemented(): bool
    {
        return !$this->removed_in_poster && $this->rule() !== null;
    }

    /**
     * Why the backend cannot apply it, or null when it can.
     */
    public function unsupportedReason(): ?string
    {
        if ($this->removed_in_poster) {
            return 'Removed in Poster';
        }

        return CategoryGiftRule::unsupportedReason((array) $this->params);
    }

    /**
     * Poster's own schedule (dates, week days, periods) and our time window.
     */
    public function isRunningAt(\DateTimeInterface $now): bool
    {
        return PromotionWindow::isOpen($this->active_from, $this->active_to, $now)
            && $this->isScheduledInPosterAt($now);
    }

    /**
     * Poster's schedule alone, without our time window. The apps get promotions
     * on this basis and apply the window themselves, so a gift shows up and goes
     * away on time without reloading.
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

    public function getWindowLabelAttribute(): string
    {
        return $this->hasWindow() ? $this->active_from . ' — ' . $this->active_to : 'весь день';
    }

    public function beforeSave()
    {
        foreach (['active_from', 'active_to'] as $field) {
            $value = trim((string) $this->{$field});
            $this->{$field} = $value === '' ? null : $value;
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
