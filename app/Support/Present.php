<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Makes a forum's answer readable by something that cannot ask a follow-up question.
 *
 * The add-on returns facts and stays stable; everything about how they are laid out for a
 * reader lives here, where it can change without an upgrade on any forum. Each rule below
 * exists because an agent given the raw response got something wrong:
 *
 * - every time is Unix seconds in UTC, and was read as local time;
 * - a withheld value and a real null both arrive as `value: null`;
 * - the default error log window is 24 hours, and a quiet day looked like an empty log.
 */
class Present
{
    /** keys whose integer value is a Unix timestamp, besides anything ending in `_date` */
    private const TIMESTAMP_KEYS = ['now', 'since', 'until', 'next_run'];

    /** no forum this talks to was logging errors before 2001, and no count is this large */
    private const EARLIEST_TIMESTAMP = 1_000_000_000;

    /**
     * Add an ISO 8601 rendering beside every timestamp, at any depth.
     *
     * `<key>_iso` is always UTC. `<key>_local` is added when a display zone other than UTC
     * is configured, and carries its offset. The original integer stays, because it is what
     * a follow-up call takes.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function timestamps(array $data, ?string $timezone = null): array
    {
        $zone = self::zone($timezone);
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::timestamps($value, $timezone);

                continue;
            }

            $out[$key] = $value;

            if (is_string($key) && self::isTimestamp($key, $value)) {
                $out[$key.'_iso'] = self::iso($value);

                if ($zone !== null) {
                    $out[$key.'_local'] = CarbonImmutable::createFromTimestampUTC($value)->setTimezone($zone)->toIso8601String();
                }
            }
        }

        return $out;
    }

    public static function iso(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * What the timestamps in an answer mean, said once at the top of it.
     *
     * @return array<string, string>
     */
    public static function timeNote(?string $timezone = null): array
    {
        $note = [
            'unix' => 'Every bare integer time is Unix seconds, UTC.',
            'iso' => 'Each `*_iso` field is the same instant in UTC.',
        ];

        if (($zone = self::zone($timezone)) !== null) {
            $note['local'] = 'Each `*_local` field is the same instant in '.$zone->getName().', with its offset.';
        }

        return $note;
    }

    /**
     * Say "withheld" where the add-on withheld a value, at any depth.
     *
     * The options and configuration endpoints return `value: null` both for a value they
     * will not disclose and for one that really is null, and only `disclosed` tells them
     * apart. Here a withheld entry loses its `value` key and gains `withheld: true`, so
     * the absence cannot be read as an empty setting.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function withheld(array $data): array
    {
        if (array_key_exists('disclosed', $data) && array_key_exists('value', $data)) {
            $data['withheld'] = $data['disclosed'] === false;

            if ($data['withheld']) {
                unset($data['value']);
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withheld($value);
            }
        }

        return $data;
    }

    /**
     * The given keys first, in that order, then everything else as it came.
     *
     * @param  array<mixed>  $data
     * @param  list<string>  $first
     * @return array<mixed>
     */
    public static function lead(array $data, array $first): array
    {
        $out = [];

        foreach ($first as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $out + $data;
    }

    /**
     * "3 days ago", "in 20 seconds" - the distance between two instants, in one unit.
     */
    public static function distance(int $from, int $to): string
    {
        $seconds = abs($to - $from);

        [$count, $unit] = match (true) {
            $seconds < 120 => [$seconds, 'second'],
            $seconds < 7200 => [intdiv($seconds, 60), 'minute'],
            $seconds < 172800 => [intdiv($seconds, 3600), 'hour'],
            default => [intdiv($seconds, 86400), 'day'],
        };

        $text = $count.' '.$unit.($count === 1 ? '' : 's');

        return $to >= $from ? $text.' ago' : 'in '.$text;
    }

    private static function isTimestamp(string $key, mixed $value): bool
    {
        return is_int($value)
            && $value >= self::EARLIEST_TIMESTAMP
            && (in_array($key, self::TIMESTAMP_KEYS, true) || str_ends_with($key, '_date'));
    }

    private static function zone(?string $timezone): ?DateTimeZone
    {
        $timezone ??= (string) config('xfmcp.timezone', 'UTC');

        if ($timezone === '' || strtoupper($timezone) === 'UTC') {
            return null;
        }

        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            // an unusable zone is reported by app:validate; an answer still goes out in UTC
            return null;
        }
    }
}
