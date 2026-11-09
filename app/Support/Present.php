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

    /**
     * A day-by-day breakdown with the empty days left out.
     *
     * The add-on lists every day, quiet ones included, which over a year is hundreds of rows
     * saying nothing. A tool that uses this says in its notes that a missing day had none.
     *
     * @param  array<mixed>  $days
     * @return list<mixed>
     */
    public static function busyDays(array $days): array
    {
        return array_values(array_filter($days, static fn (mixed $day): bool => ! is_array($day) || (int) ($day['count'] ?? 0) !== 0));
    }

    /**
     * What to say when a day-by-day breakdown stops short of the window, or null if it does not.
     *
     * The add-on reports at most 366 days. From 1.0.0a9 it says so in `by_day_complete`;
     * before that, the days adding up to less than `total` is the only sign.
     *
     * @param  array<mixed>  $data
     */
    public static function byDayCut(array $data): ?string
    {
        $days = is_array($data['by_day'] ?? null) ? array_values($data['by_day']) : [];
        $total = (int) ($data['total'] ?? 0);
        $counted = array_sum(array_map(static fn (mixed $day): int => is_array($day) ? (int) ($day['count'] ?? 0) : 0, $days));

        $cut = is_bool($data['by_day_complete'] ?? null) ? ! $data['by_day_complete'] : ($days !== [] && $counted < $total);

        if (! $cut || $days === []) {
            return null;
        }

        $older = $total - $counted;
        $first = is_array($days[0]) ? (string) ($days[0]['day'] ?? '?') : '?';

        if ($older <= 0) {
            return "`by_day` starts at {$first}: the add-on reports at most 366 days, so earlier days are not shown.";
        }

        return sprintf(
            '`by_day` starts at %s: the add-on reports at most 366 days. %s older than that and %s counted in '
            .'`total` but on no day.',
            $first,
            $older === $total
                ? sprintf('%s in this window %s', match ($total) {
                    1 => 'The one entry',
                    2 => 'Both entries',
                    default => "All {$total} entries",
                }, $total === 1 ? 'is' : 'are')
                : sprintf('%d of the %d entries in this window %s', $older, $total, $older === 1 ? 'is' : 'are'),
            $older === 1 ? 'is' : 'are',
        );
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

    /** the same, for a secret that turned out to hold nothing: withheld all the same */
    public const WITHHELD_EMPTY = '[withheld: not set]';

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
     * What each redaction reason in these entries means, once per answer rather than on every
     * redaction: 27 of 35 redactions on one forum carried the same sentence, and an answer of
     * 47 options was a third explanation (2026-11-09).
     *
     * @param  array<mixed>  $entries  entries already through disclosures()
     * @return array<string, string> reason => a clause finishing "withheld because ..."
     */
    public static function reasonLegend(array $entries): array
    {
        $legend = [];

        $walk = static function (array $entries) use (&$walk, &$legend): void {
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                foreach ((array) ($entry['redactions'] ?? []) as $redaction) {
                    $reason = is_array($redaction) ? (string) ($redaction['reason'] ?? '') : '';
                    $legend[$reason] = self::REASONS[$reason] ?? 'the forum gave a reason this tool does not know';
                }

                if (is_array($entry['members'] ?? null)) {
                    $walk($entry['members']);
                }
            }
        };

        $walk($entries);
        ksort($legend);

        return $legend;
    }

    /**
     * Add `redaction_reasons` to an answer when anything in it was withheld, just before the
     * list it explains so a reader meets the legend first.
     *
     * @param  array<mixed>  $data
     * @param  array<mixed>  $entries  the list in $data, already through disclosures()
     * @return array<mixed>
     */
    public static function withReasons(array $data, array $entries): array
    {
        $legend = self::reasonLegend($entries);

        if ($legend === []) {
            return $data;
        }

        $out = [];

        foreach ($data as $key => $value) {
            if ($value === $entries && ! array_key_exists('redaction_reasons', $out)) {
                $out['redaction_reasons'] = $legend;
            }

            $out[$key] = $value;
        }

        return $out + ['redaction_reasons' => $legend];
    }

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
     * from inside a value becomes a marker, which also says when the secret held nothing;
     * and each redaction's `reason` is explained in the answer's `redaction_reasons`, built by
     * reasonLegend(). So a null that survives is a null.
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
                $reason = (string) ($redaction['reason'] ?? '');
                $segments = self::segments($redaction);

                // the reason is explained once per answer, in reasonLegend(); `segments` is only
                // for placing the marker below, and repeated on every redaction it was most of
                // a large answer
                unset($entry['redactions'][$index]['segments']);

                // a URL is cut down, not removed: what is left is the value to show
                if ($reason !== 'url' && $segments === []) {
                    $state = 'withheld';

                    continue;
                }

                $state = $state === 'withheld' ? $state : 'partly_withheld';

                if ($reason !== 'url' && is_array($entry['value'])) {
                    // the add-on withholds a secret whether or not it holds anything, and
                    // says which; "no password" is an answer and must not read as one hidden
                    self::mark(
                        $entry['value'],
                        $segments,
                        ($redaction['set'] ?? true) === false ? self::WITHHELD_EMPTY : self::WITHHELD,
                    );
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
     * Where in a value a redaction applies, one key per entry; [] for the whole value.
     *
     * From `segments`, never by splitting `path`: a key can itself contain a dot - a
     * hostname, a template name - so the dotted path cannot be taken apart again. An
     * add-on too old to send `segments` gets its path treated as one key, which is right
     * for the only shape that version produced unambiguously.
     *
     * @param  array<mixed>  $redaction
     * @return list<string>
     */
    private static function segments(array $redaction): array
    {
        if (is_array($redaction['segments'] ?? null)) {
            return array_values(array_map(strval(...), $redaction['segments']));
        }

        $path = (string) ($redaction['path'] ?? '');

        return $path === '' ? [] : [$path];
    }

    /**
     * Put a marker at a place in a value, if that place exists. Never creates
     * one: a path this tool cannot follow is left to `redactions` to describe.
     *
     * @param  array<mixed>  $value
     * @param  list<string>  $segments
     */
    private static function mark(array &$value, array $segments, string $marker): void
    {
        $at = &$value;

        foreach ($segments as $position => $segment) {
            if (! is_array($at) || ! array_key_exists($segment, $at)) {
                return;
            }

            if ($position === count($segments) - 1) {
                $at[$segment] = $marker;

                return;
            }

            $at = &$at[$segment];
        }
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
            // usually most of them, and not because anything is secret: say so, or the
            // reader takes a forum full of undeclared add-ons for a forum full of secrets
            $undeclared = count(array_filter($entries, static fn (array $entry): bool => in_array(
                'untrusted_add_on',
                array_column((array) ($entry['redactions'] ?? []), 'reason'),
                true,
            )));

            return sprintf(
                'Values are shown, with secrets removed: %d of the %d %s listed %s something withheld, each with its reason.%s',
                $incomplete,
                $listed,
                $noun,
                $incomplete === 1 ? 'has' : 'have',
                $undeclared === 0 ? '' : sprintf(
                    ' For %d of those it is only that the add-on has not declared its secrets, so its text is hidden as a precaution.',
                    $undeclared,
                ),
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
     * "3 days ago", "in 20 seconds" - the distance between two instants, in one unit. Past 60 days
     * in months and past two years in years to one place, since "2377 days" is slow to take in;
     * the exact instant is always beside it in an `_iso` field.
     */
    public static function distance(int $from, int $to): string
    {
        $seconds = abs($to - $from);

        [$count, $unit] = match (true) {
            $seconds < 120 => [$seconds, 'second'],
            $seconds < 7200 => [intdiv($seconds, 60), 'minute'],
            $seconds < 172800 => [intdiv($seconds, 3600), 'hour'],
            $seconds < 60 * 86400 => [intdiv($seconds, 86400), 'day'],
            $seconds < 730 * 86400 => [intdiv($seconds, 2629800), 'month'],
            default => [floor($seconds / 3155760) / 10, 'year'],
        };

        $text = $count.' '.$unit.($count == 1 ? '' : 's');

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
