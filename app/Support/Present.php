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
 * - a withheld value and a real null both arrive as null, in a value or inside one;
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
                // an option's own value is data: nothing inside it is one of the forum's times
                $out[$key] = $key === 'value' ? $value : self::timestamps($value, $timezone);

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

    /** what stands in for a part of a value the add-on removed */
    public const WITHHELD = '[withheld]';

    /** the add-on's closed list of reasons, as a clause that finishes "withheld because ..." */
    private const REASONS = [
        'override' => 'this forum\'s config.php says to withhold it',
        'known_secret' => 'it is on the list of known secrets',
        'name' => 'its name reads like a credential',
        'untrusted_add_on' => 'its add-on has not declared which of its settings are secrets, so none of its text is shown',
        'value' => 'the value looks like a credential',
        'url' => 'it is a URL carrying a login or a token; only its scheme and host are shown',
        'unrepresentable' => 'the value cannot be represented in JSON',
    ];

    /**
     * Every option or setting in a list, through disclosure().
     *
     * @param  array<mixed>  $entries
     * @return list<array<mixed>>
     */
    public static function disclosures(array $entries): array
    {
        return array_values(array_map(
            static fn (mixed $entry): array => self::disclosure(is_array($entry) ? $entry : []),
            $entries,
        ));
    }

    /**
     * Say what was withheld from one option or setting, so that no null has to be guessed at.
     *
     * The add-on answers in one of two modes and a null is ambiguous in both. Without the
     * values scope a withheld value and a real null are both `value: null`, told apart only
     * by `disclosed`. With it, the value is real and each withheld part is a null INSIDE
     * it, told apart from a real null only by `redactions` naming its path.
     *
     * Here an entry gains `disclosure` - `shown`, `partly_withheld` or `withheld` - in
     * place of `disclosed`. A value withheld whole loses its `value` key; a part withheld
     * from inside a value becomes the WITHHELD marker; and each redaction gains `because`.
     * So a null that survives is a null.
     *
     * @param  array<mixed>  $entry
     * @return array<mixed>
     */
    public static function disclosure(array $entry): array
    {
        if (! array_key_exists('value', $entry)) {
            return $entry;
        }

        $redactions = $entry['redactions'] ?? null;
        $state = 'shown';

        if (is_array($redactions)) {
            foreach ($redactions as $index => $redaction) {
                $path = (string) ($redaction['path'] ?? '');
                $reason = (string) ($redaction['reason'] ?? '');

                $entry['redactions'][$index]['because'] = self::REASONS[$reason] ?? 'the forum gave a reason this tool does not know';

                // a URL is cut down, not removed: what is left is the value to show
                if ($reason === 'url') {
                    $state = $state === 'withheld' ? $state : 'partly_withheld';
                } elseif ($path === '') {
                    $state = 'withheld';
                } else {
                    $state = $state === 'withheld' ? $state : 'partly_withheld';

                    if (is_array($entry['value'])) {
                        data_set($entry['value'], $path, self::WITHHELD);
                    }
                }
            }
        } elseif (($entry['disclosed'] ?? true) === false) {
            $state = 'withheld';
        }

        if ($state === 'withheld') {
            unset($entry['value']);
        }

        if (is_array($entry['members'] ?? null)) {
            $entry['members'] = array_map(
                static fn (mixed $member): mixed => is_array($member) ? self::disclosure($member) : $member,
                $entry['members'],
            );
        }

        // absent in the other mode, and a null of either reads as something to interpret
        foreach (['redactions', 'members'] as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] === null) {
                unset($entry[$key]);
            }
        }

        unset($entry['disclosed']);

        return ['disclosure' => $state] + $entry;
    }

    /**
     * Which mode the add-on answered in, as the first thing the reader is told.
     *
     * @param  array<mixed>  $data  the options or configuration response
     * @param  list<array<mixed>>  $entries  its entries, after disclosures()
     */
    public static function modeHeadline(array $data, array $entries, string $noun): string
    {
        $listed = count($entries);
        $incomplete = count(array_filter($entries, static fn (array $entry): bool => ($entry['disclosure'] ?? 'shown') !== 'shown'));

        if (($data['mode'] ?? 'shape') === 'values') {
            return sprintf(
                'Values are shown, with secrets removed: %d of the %d %s listed %s something withheld, each with its reason.',
                $incomplete,
                $listed,
                $noun,
                $incomplete === 1 ? 'has' : 'have',
            );
        }

        // the add-on's own sentence: a missing scope and a missing admin permission differ
        $reason = is_string($data['mode_reason'] ?? null) && $data['mode_reason'] !== ''
            ? $data['mode_reason']
            : 'the forum did not say why';

        return sprintf(
            'Values are not shown, only whether each is set, because %s: %d of the %d %s listed are withheld.',
            $reason,
            $incomplete,
            $listed,
            $noun,
        );
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
