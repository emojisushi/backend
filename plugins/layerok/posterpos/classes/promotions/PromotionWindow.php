<?php

namespace Layerok\PosterPos\Classes\Promotions;

/**
 * The time of day the backend applies a promotion in, set per promotion in the
 * backend as active_from / active_to ("HH:MM", account timezone). With either one
 * empty there is no limit of our own; Poster's own periods still apply.
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
     * Whether the window is open at this moment. The end minute is excluded, so
     * 11:00-22:00 applies up to 21:59. A window past midnight (22:00-02:00) works.
     */
    public static function isOpen(?string $from, ?string $to, \DateTimeInterface $at): bool
    {
        if (!self::hasWindow($from, $to)) {
            return true;
        }

        $time = $at->format('H:i');

        return $from < $to
            ? $time >= $from && $time < $to
            : $time >= $from || $time < $to;
    }

    private static function valid(?string $value): bool
    {
        return $value !== null && preg_match(self::TIME_PATTERN, $value) === 1;
    }
}
