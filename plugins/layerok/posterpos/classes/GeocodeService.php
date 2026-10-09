<?php

namespace Layerok\PosterPos\Classes;

use Cache;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Layerok\PosterPos\Models\GeocodedAddress;

/**
 * Resolves a street and house number to a building coordinate via Nominatim.
 *
 * The query is deliberately plain — house number and street, inside a 10x10 km
 * box centred on the street we already know. District names are not sent: ours
 * are informal and rarely match OSM's
 * administrative naming, which turned good matches into misses. Instead several
 * candidates are requested and the one nearest the street we already know wins.
 * A road result counts as a failure: it means the house number was ignored. A
 * winner further than ACCEPT_RADIUS_METRES is a mismatch too, so a
 * same-named street on the other side of the city cannot steal the pin.
 *
 * Every result is cached, misses included. Nominatim allows one request per
 * second and requires an identifying User-Agent, both enforced here:
 * https://operations.osmfoundation.org/policies/nominatim/
 */
class GeocodeService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /** One request per second, with a little headroom. */
    private const MIN_INTERVAL_MICROSECONDS = 1100000;

    /** A cached miss is worth retrying eventually; a street may get mapped later. */
    private const RETRY_AFTER_DAYS = 7;

    /** Candidates to consider before picking the best. Nominatim honours up to 40. */
    private const CANDIDATES = 20;

    /** Half-width of the search box drawn around the street we know: a 10x10 km square. */
    private const BOX_HALF_METRES = 5000;

    /** Beyond this from the known street, a match is not believable. */
    private const ACCEPT_RADIUS_METRES = 10000;

    private const THROTTLE_KEY = 'layerok.geocode.last_request';

    private ?Client $http = null;

    public function enabled(): bool
    {
        return (bool) config('geocode.enabled');
    }

    /**
     * Building coordinates for an address, or null when they are not known.
     *
     * $reference is the coordinate we already hold for the street, used both to
     * choose between candidates and to reject a result that is implausibly far.
     * Without it nothing can be judged, so no lookup is made.
     */
    public function coordinatesFor(?string $street, ?string $house, ?array $reference = null): ?array
    {
        $street = trim((string) $street);
        $house = trim((string) $house);

        if ($street === '' || $house === '' || !$this->enabled()) {
            return null;
        }

        if (!isset($reference['lat'], $reference['lng'])) {
            return null;
        }

        $cached = GeocodedAddress::where('street', $street)->where('house', $house)->first();

        if ($cached && !$this->isStale($cached)) {
            return $cached->found ? $cached->point() : null;
        }

        return $this->lookUp($street, $house, $reference, $cached);
    }

    private function isStale(GeocodedAddress $cached): bool
    {
        if ($cached->found) {
            return false;
        }

        return !$cached->looked_up_at
            || $cached->looked_up_at->lt(now()->subDays(self::RETRY_AFTER_DAYS));
    }

    private function lookUp(string $street, string $house, array $reference, ?GeocodedAddress $cached): ?array
    {
        set_time_limit(0);

        try {
            $this->throttle();

            $response = $this->client()->get(self::ENDPOINT, [
                'query' => [
                    'street' => $house . ' ' . $this->normaliseStreet($street),
                    'viewbox' => $this->viewboxAround($reference),
                    'bounded' => config('geocode.bounded') ? 1 : 0,
                    'countrycodes' => config('geocode.country_codes'),
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => self::CANDIDATES,
                ],
                'headers' => [
                    // Required by the usage policy; generic clients are refused.
                    'User-Agent' => config('geocode.user_agent'),
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $candidates = json_decode((string) $response->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            Log::warning('Geocoding failed', [
                'street' => $street,
                'house' => $house,
                'error' => $e->getMessage(),
            ]);

            // Not stored: a network blip should not be remembered as a miss.
            return $cached && $cached->found ? $cached->point() : null;
        }

        return $this->remember($street, $house, $cached, $this->nearest($candidates, $reference));
    }

    /**
     * Rewrites a street name into something OSM is likely to hold.
     *
     * Renamed streets are stored carrying the former name in brackets, e.g.
     * "вул. Сім'ї Глодан (вул. Ільфа і Петрова)", which matches nothing — the
     * bracketed part is dropped. Abbreviations are expanded for the same reason:
     * OSM spells the type out. The raw name stays the cache key, so the mapping
     * back to our address book is unaffected.
     */
    private function normaliseStreet(string $street): string
    {
        $street = preg_replace('/\([^)]*\)/u', ' ', $street);

        // Фонтанська дорога is split into stretches by tram stop in the address
        // book ("Фонтанська дорога 1-9 станції"), but OSM holds it as one road.
        // Deliberately narrow: a station reference is part of the real name
        // elsewhere, as in "10-та лінія 6-ї станції Люстдорфської дороги".
        $street = preg_replace(
            '/^(Фонтанська\s+дорога)\s+\d+(?:\s*[-–—]\s*\d+)?\s*станц\w*$/ui',
            '$1',
            $street
        );

        $abbreviations = [
            '/\bвул\.\s*/u' => 'вулиця ',
            '/\bпров\.\s*/u' => 'провулок ',
            '/\bпросп\.\s*/u' => 'проспект ',
            '/\bпл\.\s*/u' => 'площа ',
            '/\bбул\.\s*/u' => 'бульвар ',
            '/\bдор\.\s*/u' => 'дорога ',
        ];

        $street = preg_replace(array_keys($abbreviations), array_values($abbreviations), $street);

        return trim(preg_replace('/\s+/u', ' ', $street));
    }

    /**
     * Result classes that describe a road rather than a place on it. Matching one
     * means the house number was ignored and the whole street came back, which is
     * no better than the coordinate we already hold — so it counts as a failure.
     */
    private const ROAD_CLASSES = ['highway', 'railway', 'waterway', 'route', 'boundary'];

    /** Classes that genuinely denote a building or an address point. */
    private const BUILDING_CLASSES = ['building', 'place', 'addr'];

    /**
     * The best candidate: a building if one is offered, otherwise any other place
     * on the plot — a shop or an amenity still beats the middle of a street.
     * Roads are discarded outright. Within a tier the nearest one wins.
     */
    private function nearest(array $candidates, array $reference): ?array
    {
        $buildings = [];
        $others = [];

        foreach ($candidates as $candidate) {
            if (!isset($candidate['lat'], $candidate['lon'])) {
                continue;
            }

            $class = (string) ($candidate['class'] ?? $candidate['category'] ?? '');

            if (in_array($class, self::ROAD_CLASSES, true)) {
                continue;
            }

            $entry = [
                'candidate' => $candidate,
                'distance' => $this->distanceMetres(
                    (float) $reference['lat'],
                    (float) $reference['lng'],
                    (float) $candidate['lat'],
                    (float) $candidate['lon']
                ),
            ];

            if ($this->isBuilding($candidate, $class)) {
                $buildings[] = $entry;
            } else {
                $others[] = $entry;
            }
        }

        $tier = $buildings ?: $others;

        if (!$tier) {
            return null;
        }

        usort($tier, fn($a, $b) => $a['distance'] <=> $b['distance']);

        return $tier[0];
    }

    private function isBuilding(array $candidate, string $class): bool
    {
        if (in_array($class, self::BUILDING_CLASSES, true)) {
            return true;
        }

        $addressType = (string) ($candidate['addresstype'] ?? '');

        return in_array($addressType, ['building', 'house', 'residential'], true)
            || isset($candidate['address']['house_number']);
    }

    private function remember(string $street, string $house, ?GeocodedAddress $cached, ?array $best): ?array
    {
        $distance = $best ? (int) round($best['distance']) : null;
        $accepted = $best !== null && $best['distance'] <= self::ACCEPT_RADIUS_METRES;
        $candidate = $best['candidate'] ?? null;

        $attributes = [
            'lat' => $accepted ? (float) $candidate['lat'] : null,
            'lng' => $accepted ? (float) $candidate['lon'] : null,
            'display_name' => $candidate ? mb_substr((string) ($candidate['display_name'] ?? ''), 0, 500) : null,
            'result_class' => $candidate ? (string) ($candidate['class'] ?? $candidate['category'] ?? '') : null,
            'distance_m' => $distance,
            'found' => $accepted,
            'looked_up_at' => now(),
        ];

        if ($cached) {
            $cached->fill($attributes)->save();

            return $accepted ? $cached->point() : null;
        }

        $key = ['street' => $street, 'house' => $house];

        try {
            $row = GeocodedAddress::updateOrCreate($key, $attributes);
        } catch (\Throwable $e) {
            // Lookups take about a second each, so two overlapping requests can
            // both miss the cache and both try to insert the same address. The
            // loser of that race just updates the row the winner wrote.
            $row = GeocodedAddress::where($key)->first();

            if (!$row) {
                throw $e;
            }

            $row->fill($attributes)->save();
        }

        return $accepted ? $row->point() : null;
    }

    /**
     * A square search box centred on the street we already know.
     *
     * Nominatim wants left,top,right,bottom. A degree of longitude shrinks with
     * latitude, so the east-west half-width is divided by cos(lat) to keep the
     * box square on the ground rather than on paper.
     */
    private function viewboxAround(array $reference): string
    {
        $lat = (float) $reference['lat'];
        $lng = (float) $reference['lng'];

        $latDelta = self::BOX_HALF_METRES / 111320;
        $lngDelta = self::BOX_HALF_METRES / (111320 * max(cos(deg2rad($lat)), 0.01));

        return implode(',', [
            round($lng - $lngDelta, 6),
            round($lat + $latDelta, 6),
            round($lng + $lngDelta, 6),
            round($lat - $latDelta, 6),
        ]);
    }

    /**
     * Haversine distance in metres.
     */
    private function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Keeps requests a second apart across processes, since several admins may
     * have the map open at once.
     */
    private function throttle(): void
    {
        $last = (int) Cache::get(self::THROTTLE_KEY, 0);
        $elapsed = (int) (microtime(true) * 1000000) - $last;

        if ($last > 0 && $elapsed < self::MIN_INTERVAL_MICROSECONDS) {
            usleep(self::MIN_INTERVAL_MICROSECONDS - $elapsed);
        }

        Cache::put(self::THROTTLE_KEY, (int) (microtime(true) * 1000000), now()->addMinutes(5));
    }

    private function client(): Client
    {
        return $this->http ?: ($this->http = new Client());
    }
}
