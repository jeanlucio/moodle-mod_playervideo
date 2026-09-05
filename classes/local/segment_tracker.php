<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Validates and merges watched-second intervals reported by the player.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Turns untrusted client-reported second ranges into a normalised, authoritative list.
 *
 * A "segment" is a two-element array [start, end], both in seconds, representing a stretch of
 * the video the player actually played through. Every public method here treats its input as
 * untrusted: malformed, overlapping, out-of-order or out-of-bounds entries are dropped or
 * clamped rather than trusted as-is.
 */
class segment_tracker {
    /** @var int Decimal places kept when rounding a validated interval boundary. */
    private const PRECISION = 3;

    /** @var float Two intervals within this many seconds of each other are merged into one. */
    private const MERGE_TOLERANCE = 0.5;

    /**
     * Validates and clamps one raw interval against the known video duration.
     *
     * @param mixed $interval Untrusted candidate, expected to be a [start, end] pair.
     * @param float $duration Authoritative video duration in seconds; 0 skips the upper clamp.
     * @return array|null The validated [start, end] pair, or null when the interval is unusable.
     */
    public static function validate_interval(mixed $interval, float $duration = 0.0): ?array {
        if (!is_array($interval) || count($interval) !== 2) {
            return null;
        }
        [$start, $end] = array_values($interval);
        if (!is_numeric($start) || !is_numeric($end)) {
            return null;
        }
        $start = round((float) $start, self::PRECISION);
        $end = round((float) $end, self::PRECISION);
        if (!is_finite($start) || !is_finite($end) || $start < 0 || $end <= $start) {
            return null;
        }
        if ($duration > 0) {
            if ($start >= $duration) {
                return null;
            }
            $end = min($end, $duration);
        }
        return $end > $start ? [$start, $end] : null;
    }

    /**
     * Validates, sorts and merges a collection of intervals into their smallest equivalent form.
     *
     * @param array $segments Collection of untrusted [start, end] candidates.
     * @param float $duration Authoritative video duration in seconds; 0 skips the upper clamp.
     * @return array Sorted, non-overlapping [start, end] pairs.
     */
    public static function normalise(array $segments, float $duration = 0.0): array {
        $valid = [];
        foreach ($segments as $segment) {
            $interval = self::validate_interval($segment, $duration);
            if ($interval !== null) {
                $valid[] = $interval;
            }
        }
        usort($valid, static fn(array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);

        $merged = [];
        foreach ($valid as $interval) {
            $lastindex = count($merged) - 1;
            if ($lastindex < 0 || $interval[0] > $merged[$lastindex][1] + self::MERGE_TOLERANCE) {
                $merged[] = $interval;
                continue;
            }
            $merged[$lastindex][1] = max($merged[$lastindex][1], $interval[1]);
        }
        return $merged;
    }

    /**
     * Merges newly reported intervals into the already-persisted set.
     *
     * @param array $existing Previously normalised [start, end] pairs.
     * @param array $incoming Newly reported, untrusted [start, end] candidates.
     * @param float $duration Authoritative video duration in seconds; 0 skips the upper clamp.
     * @return array Sorted, non-overlapping [start, end] pairs.
     */
    public static function merge(array $existing, array $incoming, float $duration = 0.0): array {
        return self::normalise(array_merge($existing, $incoming), $duration);
    }

    /**
     * Sums the total duration of unique content represented by a set of segments.
     *
     * @param array $segments Collection of [start, end] pairs.
     * @return float Seconds of unique content, never counting an overlap twice.
     */
    public static function unique_seconds(array $segments): float {
        $total = 0.0;
        foreach (self::normalise($segments) as [$start, $end]) {
            $total += $end - $start;
        }
        return round($total, self::PRECISION);
    }

    /**
     * Returns the greatest position ever confirmed as watched.
     *
     * @param array $segments Collection of [start, end] pairs.
     * @return float The furthest watched position, in seconds; 0 when no segment is valid.
     */
    public static function furthest_position(array $segments): float {
        $normalised = self::normalise($segments);
        if ($normalised === []) {
            return 0.0;
        }
        return (float) end($normalised)[1];
    }

    /**
     * Returns only the portions of the given segments that overlap a window.
     *
     * @param array $segments Collection of [start, end] pairs.
     * @param float $windowstart Window start, in seconds.
     * @param float $windowend Window end, in seconds.
     * @return array The overlapping [start, end] pairs, clipped to the window's own bounds.
     */
    public static function clip_to_window(array $segments, float $windowstart, float $windowend): array {
        $clipped = [];
        foreach (self::normalise($segments) as [$start, $end]) {
            $overlapstart = max($start, $windowstart);
            $overlapend = min($end, $windowend);
            if ($overlapend > $overlapstart) {
                $clipped[] = [$overlapstart, $overlapend];
            }
        }
        return $clipped;
    }
}
