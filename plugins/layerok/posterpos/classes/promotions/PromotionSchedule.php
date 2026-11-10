<?php

namespace Layerok\PosterPos\Classes\Promotions;

/**
 * Whether a Poster promotion runs at a given moment: its date range, week days and
 * time periods. The moment must be in the account's timezone, since that is how
 * Poster stores all of these.
 *
 * Has no framework dependencies so it can be checked without booting October.
 */
final class PromotionSchedule
{
    /**
     * @param string|null $start "Y-m-d H:i:s", null for no start date
     * @param string|null $end   "Y-m-d H:i:s", null for open-ended
     * @param array $weekDays    seven "0"/"1" flags, Monday first
     * @param array $periods     [{start: "HH:MM", end: "HH:MM"}], the end minute included
     */
    public static function isActive(?string $start, ?string $end, array $weekDays, array $periods, \DateTimeInterface $now): bool
    {
        $at = $now->format('Y-m-d H:i:s');

        if ($start !== null && $start !== '' && $at < $start) {
            return false;
        }

        if ($end !== null && $end !== '' && $at > $end) {
            return false;
        }

        if (count($weekDays) === 7 && (string) $weekDays[(int) $now->format('N') - 1] !== '1') {
            return false;
        }

        if (!$periods) {
            return true;
        }

        $time = $now->format('H:i');

        foreach ($periods as $period) {
            $from = (string) ($period['start'] ?? '00:00');
            $to = (string) ($period['end'] ?? '23:59');

            $inside = $from <= $to
                ? $time >= $from && $time <= $to
                // A period past midnight, e.g. 22:00-02:00.
                : $time >= $from || $time <= $to;

            if ($inside) {
                return true;
            }
        }

        return false;
    }

    /**
     * Poster sends "0000-00-00 00:00:00" for a promotion with no end date.
     */
    public static function normalizeDate($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000-00-00') ? null : $value;
    }
}
