<?php

namespace Layerok\Restapi\Classes;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for the public menu endpoints (/api/catalog and alike).
 *
 * Every visitor of the site and the mobile app loads the whole menu, and
 * building it runs dozens of queries, a full scan of the product index among
 * them. At the evening peak that load got the MySQL user throttled by
 * Hostinger (14.11.2026). The menu changes rarely, so it is built at most once
 * per TTL per variant (city, language, …) and shared between visitors.
 *
 * Freshness: saving or deleting a menu model (see Plugin::boot) bumps the
 * version, and an entry built under another version is rebuilt on its next
 * read. Changes that bypass model events (raw queries, some pivot writes) show
 * up after the TTL at the latest.
 *
 * The version is stored inside the entry rather than in its key, so a bump
 * overwrites the existing entries instead of leaving a new set of cache files
 * behind each time.
 *
 * A cache failure never fails the request: the value is built uncached. It is
 * not logged either, since every error log also goes to Telegram.
 */
class CatalogCache
{
    public const TTL = 60; // seconds

    private const PREFIX = 'layerok.restapi.catalog.';
    private const VERSION_KEY = self::PREFIX . 'version';

    /** @var array<string, string> name => "HIT" | "MISS" for this request */
    private static array $statuses = [];

    /**
     * @param string $name what is cached, e.g. "products"
     * @param array $vary everything the value depends on; keep it to a bounded
     *                    set of values (ids, validated locale) — never raw input
     * @param Closure $build builds the value; it must return plain data
     *                       (arrays, scalars), not models
     */
    public static function remember(string $name, array $vary, Closure $build)
    {
        $key = self::PREFIX . $name . '.' . md5(json_encode($vary));
        $version = self::version();

        $entry = rescue(fn () => Cache::get($key), null, false);
        if (is_array($entry) && ($entry['version'] ?? null) === $version) {
            self::$statuses[$name] = 'HIT';
            return $entry['value'];
        }

        self::$statuses[$name] = 'MISS';
        $value = $build();

        rescue(fn () => Cache::put($key, [
            'version' => $version,
            'value' => $value,
        ], self::TTL), null, false);

        return $value;
    }

    /**
     * What remember() did in this request, for the X-Catalog-Cache response
     * header, e.g. "categories=HIT, banners=HIT, products=MISS".
     */
    public static function header(): string
    {
        return implode(', ', array_map(
            fn ($name, $status) => "$name=$status",
            array_keys(self::$statuses),
            self::$statuses
        ));
    }

    /**
     * Makes every cached menu entry stale.
     */
    public static function flush(): void
    {
        rescue(fn () => Cache::forever(self::VERSION_KEY, uniqid('', true)), null, false);
    }

    private static function version(): string
    {
        return (string) rescue(fn () => Cache::get(self::VERSION_KEY, ''), '', false);
    }
}
