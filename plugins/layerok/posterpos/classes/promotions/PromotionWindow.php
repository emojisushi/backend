<?php

namespace Layerok\PosterPos\Classes\Promotions;

/**
 * When the backend applies a promotion, set per promotion in the backend: the time
 * of day (active_from / active_to, "HH:MM") and the week days (active_days, ISO
 * numbers 1 = Monday … 7 = Sunday), both in the account's timezone. Empty means no
 * limit of our own; Poster's own schedule applies on top either way.
 *
 * Has no framework dependencies so it can be checked without booting October.
 */
final class PromotionWindow
{
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function hasWindow(?string $from, ?string $to): bool
    {
        return self::valid($from) && self::valid($to) && $from !== $to;
    }

    /**
     * The ticked days as ISO numbers, or [] for every day (also when all seven
     * are ticked).
     */
    public static function normalizeDays($days): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', is_array($days) ? $days : []),
            fn($day) => $day >= 1 && $day <= 7
        )));
        sort($days);

        return count($days) === 7 ? [] : $days;
    }

    /**
     * Whether the promotion applies at this moment. The end minute is excluded, so
     * 11:00-22:00 applies up to 21:59. In a window past midnight (22:00-02:00) the
     * hours after midnight belong to the day the window started, so "Friday,
     * 22:00-02:00" still applies at 01:00 on Saturday.
     */
    public static function isOpen(?string $from, ?string $to, \DateTimeInterface $at, $days = []): bool
    {
        $days = self::normalizeDays($days);
        $time = $at->format('H:i');
        $day = (int) $at->format('N');

        if (!self::hasWindow($from, $to)) {
            return !$days || in_array($day, $days, true);
        }

        if ($from < $to) {
            $inside = $time >= $from && $time < $to;
        } elseif ($time >= $from) {
            $inside = true;
        } elseif ($time < $to) {
            $inside = true;
            // after midnight: the window opened the day before
            $day = $day === 1 ? 7 : $day - 1;
        } else {
            $inside = false;
        }

        return $inside && (!$days || in_array($day, $days, true));
    }

    private static function valid(?string $value): bool
    {
        return $value !== null && preg_match(self::TIME_PATTERN, $value) === 1;
    }
}
