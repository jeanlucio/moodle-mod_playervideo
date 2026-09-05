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
 * Aggregates watched segments across a class into a class-wide engagement timeline.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Turns every enrolled student's watched segments into a fixed number of equal-width buckets
 * spanning the activity's own playback window, computed fresh on every call — there is no table
 * of its own to keep in sync.
 *
 * The result never exposes a per-student breakdown, only totals per region of the video, the
 * same aggregation-without-identification principle already used by get_poll_results.
 */
class engagement_aggregator {
    /** @var int Number of equal-width regions the playback window is divided into. */
    private const BUCKET_COUNT = 40;

    /**
     * Builds the aggregated engagement view for one instance's playback window.
     *
     * @param array $segmentsbyuser Each student's segments (untrusted shape), keyed by userid;
     *     the keys themselves are never returned, only used to iterate one student at a time.
     * @param float $windowstart Playback window start, in seconds.
     * @param float $windowend Playback window end, in seconds.
     * @return array{
     *     buckets: float[],
     *     windowstart: float,
     *     bucketlength: float,
     *     mostwatchedbucket: int|null,
     *     leastwatchedbucket: int|null,
     *     dropoffbucket: int|null
     * }
     */
    public static function build(array $segmentsbyuser, float $windowstart, float $windowend): array {
        $buckets = array_fill(0, self::BUCKET_COUNT, 0.0);
        $windowlength = $windowend - $windowstart;
        if ($windowlength <= 0) {
            return self::summarise($buckets, $windowstart, 0.0);
        }

        $bucketlength = $windowlength / self::BUCKET_COUNT;
        foreach ($segmentsbyuser as $segments) {
            $clipped = segment_tracker::clip_to_window(
                is_array($segments) ? $segments : [],
                $windowstart,
                $windowend
            );
            foreach ($clipped as [$start, $end]) {
                self::accumulate($buckets, $start - $windowstart, $end - $windowstart, $bucketlength);
            }
        }

        return self::summarise($buckets, $windowstart, $bucketlength);
    }

    /**
     * Adds one watched interval's contribution to every bucket it overlaps.
     *
     * @param float[] $buckets Bucket totals, modified in place.
     * @param float $start Interval start, in seconds relative to the window start.
     * @param float $end Interval end, in seconds relative to the window start.
     * @param float $bucketlength Width of one bucket, in seconds.
     * @return void
     */
    private static function accumulate(array &$buckets, float $start, float $end, float $bucketlength): void {
        $lastindex = count($buckets) - 1;
        $firstbucket = max(0, min((int) floor($start / $bucketlength), $lastindex));
        $lastbucket = max(0, min((int) floor(($end - PHP_FLOAT_EPSILON) / $bucketlength), $lastindex));

        for ($index = $firstbucket; $index <= $lastbucket; $index++) {
            $bucketstart = $index * $bucketlength;
            $overlapstart = max($start, $bucketstart);
            $overlapend = min($end, $bucketstart + $bucketlength);
            if ($overlapend > $overlapstart) {
                $buckets[$index] += $overlapend - $overlapstart;
            }
        }
    }

    /**
     * Identifies the most-watched, least-watched and drop-off regions from the raw totals.
     *
     * @param float[] $buckets Bucket totals, in timeline order.
     * @param float $windowstart Playback window start, in seconds; carried through so the caller
     *     can turn a bucket index back into a real video timestamp.
     * @param float $bucketlength Width of one bucket, in seconds; 0 when the window was invalid.
     * @return array{
     *     buckets: float[],
     *     windowstart: float,
     *     bucketlength: float,
     *     mostwatchedbucket: int|null,
     *     leastwatchedbucket: int|null,
     *     dropoffbucket: int|null
     * }
     */
    private static function summarise(array $buckets, float $windowstart, float $bucketlength): array {
        $haswatcheddata = false;
        foreach ($buckets as $value) {
            if ($value > 0.0) {
                $haswatcheddata = true;
                break;
            }
        }

        return [
            'buckets' => $buckets,
            'windowstart' => $windowstart,
            'bucketlength' => $bucketlength,
            'mostwatchedbucket' => $haswatcheddata ? self::index_of_extreme($buckets, true) : null,
            'leastwatchedbucket' => $haswatcheddata ? self::index_of_extreme($buckets, false) : null,
            'dropoffbucket' => self::index_of_largest_drop($buckets),
        ];
    }

    /**
     * Returns the index of the highest (or lowest) value in a bucket list.
     *
     * @param float[] $buckets Bucket totals, in timeline order.
     * @param bool $highest True for the highest value, false for the lowest.
     * @return int|null The winning index; null when the list is empty.
     */
    private static function index_of_extreme(array $buckets, bool $highest): ?int {
        if ($buckets === []) {
            return null;
        }
        $winner = $highest ? max($buckets) : min($buckets);
        return array_search($winner, $buckets, true);
    }

    /**
     * Finds the region where viewership drops the most compared to the region right before it.
     *
     * @param float[] $buckets Bucket totals, in timeline order.
     * @return int|null Index of the bucket landed on after the largest drop; null when
     *     viewership never decreases from one region to the next.
     */
    private static function index_of_largest_drop(array $buckets): ?int {
        $dropoffindex = null;
        $largestdrop = 0.0;
        for ($index = 1; $index < count($buckets); $index++) {
            $drop = $buckets[$index - 1] - $buckets[$index];
            if ($drop > $largestdrop) {
                $largestdrop = $drop;
                $dropoffindex = $index;
            }
        }
        return $dropoffindex;
    }
}
