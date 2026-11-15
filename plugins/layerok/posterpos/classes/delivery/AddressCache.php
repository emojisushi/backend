<?php

namespace Layerok\PosterPos\Classes\Delivery;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 5-minute cache of a city's delivery streets for /api/addresses: every street
 * matched against every delivery area polygon, which the site asks for on each
 * checkout. Only that geometry is cached; what changes during the day (stop
 * lists, sold-out limited products, the kitchen's wait time) is attached live by
 * the controller.
 *
 * Saving or deleting an Address or Area drops it at once (Plugin::boot, and the
 * bulk queries in the Addresses controller); anything else shows up within TTL.
 *
 * The version lives inside the entry, so a flush overwrites the entries instead
 * of leaving stale cache files behind. A cache failure never fails the request:
 * the list is built uncached, and not logged (error logs also go to Telegram).
 */
final class AddressCache
{
    public const TTL = 300; // seconds

    private const PREFIX = 'layerok.posterpos.addresses.';
    private const VERSION_KEY = self::PREFIX . 'version';

    /** "HIT" or "MISS" for the last remember() of this request, for X-Address-Cache */
    private static ?string $status = null;

    public static function status(): ?string
    {
        return self::$status;
    }

    /**
     * @param Closure $build returns plain arrays, no models
     */
    public static function remember(int $cityId, Closure $build): array
    {
        $key = self::PREFIX . 'city.' . $cityId;
        $version = self::version();

        $entry = rescue(fn () => Cache::get($key), null, false);
        if (is_array($entry) && ($entry['version'] ?? null) === $version) {
            self::$status = 'HIT';
            return $entry['value'];
        }

        self::$status = 'MISS';
        $value = $build();

        rescue(fn () => Cache::put($key, ['version' => $version, 'value' => $value], self::TTL), null, false);

        return $value;
    }

    public static function flush(): void
    {
        rescue(fn () => Cache::forever(self::VERSION_KEY, uniqid('', true)), null, false);
    }

    private static function version(): string
    {
        return (string) rescue(fn () => Cache::get(self::VERSION_KEY, ''), '', false);
    }
}
