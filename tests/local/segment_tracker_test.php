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
 * Unit tests for the watched-segment validation/merge utility.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for segment_tracker.
 *
 * @covers \mod_playervideo\local\segment_tracker
 */
final class segment_tracker_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that a well-formed interval is accepted unchanged.
     *
     * @return void
     */
    public function test_validate_interval_accepts_a_well_formed_pair(): void {
        $this->assertSame([10.0, 20.0], segment_tracker::validate_interval([10, 20]));
    }

    /**
     * Tests that malformed candidates are rejected: not an array, wrong arity, non-numeric
     * bounds, a negative start, or an end that does not come after the start.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function malformed_interval_provider(): array {
        return [
            'not an array' => ['nope'],
            'single element' => [[5]],
            'three elements' => [[5, 10, 15]],
            'non-numeric start' => [['a', 10]],
            'non-numeric end' => [[5, 'b']],
            'negative start' => [[-5, 10]],
            'end equal to start' => [[10, 10]],
            'end before start' => [[10, 5]],
            'infinite end' => [[0, INF]],
        ];
    }

    /**
     * Tests that a malformed candidate is rejected.
     *
     * @dataProvider malformed_interval_provider
     * @param mixed $interval The malformed candidate under test.
     * @return void
     */
    public function test_validate_interval_rejects_malformed_input(mixed $interval): void {
        $this->assertNull(segment_tracker::validate_interval($interval));
    }

    /**
     * Tests that an interval starting past the known duration is rejected outright, and one only
     * partially past it is clamped instead of dropped.
     *
     * @return void
     */
    public function test_validate_interval_clamps_to_duration(): void {
        $this->assertNull(segment_tracker::validate_interval([120, 130], 100.0));
        $this->assertSame([90.0, 100.0], segment_tracker::validate_interval([90, 130], 100.0));
    }

    /**
     * Tests that overlapping and adjacent-within-tolerance intervals collapse into one, while a
     * genuinely separate interval is kept apart.
     *
     * @return void
     */
    public function test_normalise_merges_overlapping_and_close_intervals(): void {
        $result = segment_tracker::normalise([[10, 20], [15, 25], [25.3, 30], [100, 110]]);

        $this->assertSame([[10.0, 30.0], [100.0, 110.0]], $result);
    }

    /**
     * Tests that unsorted input is sorted before merging, so overlap detection does not depend
     * on the order the client happened to report ranges in.
     *
     * @return void
     */
    public function test_normalise_sorts_before_merging(): void {
        $result = segment_tracker::normalise([[50, 60], [0, 10], [5, 12]]);

        $this->assertSame([[0.0, 12.0], [50.0, 60.0]], $result);
    }

    /**
     * Tests that invalid entries are silently dropped rather than aborting the whole batch.
     *
     * @return void
     */
    public function test_normalise_drops_invalid_entries_without_failing(): void {
        $result = segment_tracker::normalise([[10, 20], 'garbage', [30, 10], [40, 50]]);

        $this->assertSame([[10.0, 20.0], [40.0, 50.0]], $result);
    }

    /**
     * Tests that merging a superset of the existing data is idempotent — the exact shape a
     * heartbeat sends, since the client always reports its whole accumulated tracker, not a
     * delta.
     *
     * @return void
     */
    public function test_merge_is_idempotent_for_a_superset(): void {
        $existing = segment_tracker::normalise([[0, 60]]);
        $result = segment_tracker::merge($existing, [[0, 60], [480, 600]], 600.0);

        $this->assertSame([[0.0, 60.0], [480.0, 600.0]], $result);
    }

    /**
     * Tests that unique_seconds never double-counts an overlap between two segments.
     *
     * @return void
     */
    public function test_unique_seconds_counts_overlap_once(): void {
        $seconds = segment_tracker::unique_seconds([[0, 60], [30, 90]]);

        $this->assertEqualsWithDelta(90.0, $seconds, 0.001);
    }

    /**
     * Tests that unique_seconds is zero for an empty or entirely invalid set.
     *
     * @return void
     */
    public function test_unique_seconds_is_zero_when_nothing_is_valid(): void {
        $this->assertEqualsWithDelta(0.0, segment_tracker::unique_seconds([]), 0.001);
        $this->assertEqualsWithDelta(0.0, segment_tracker::unique_seconds([[10, 5]]), 0.001);
    }

    /**
     * Tests that furthest_position returns the end of the last (sorted) segment, not the order
     * the caller happened to pass segments in.
     *
     * @return void
     */
    public function test_furthest_position_returns_the_greatest_endpoint(): void {
        $this->assertEqualsWithDelta(110.0, segment_tracker::furthest_position([[100, 110], [0, 20]]), 0.001);
    }

    /**
     * Tests that furthest_position is zero when there is nothing valid to report.
     *
     * @return void
     */
    public function test_furthest_position_is_zero_when_empty(): void {
        $this->assertEqualsWithDelta(0.0, segment_tracker::furthest_position([]), 0.001);
    }

    /**
     * Tests that clip_to_window keeps only the portions of each segment inside the window,
     * trimming a segment that straddles the boundary instead of dropping it whole.
     *
     * @return void
     */
    public function test_clip_to_window_trims_straddling_segments(): void {
        $result = segment_tracker::clip_to_window([[0, 20], [50, 60]], 10.0, 55.0);

        $this->assertSame([[10.0, 20.0], [50.0, 55.0]], $result);
    }

    /**
     * Tests that a segment entirely outside the window is dropped, not clamped to an empty range.
     *
     * @return void
     */
    public function test_clip_to_window_drops_segments_entirely_outside(): void {
        $this->assertSame([], segment_tracker::clip_to_window([[0, 5]], 10.0, 20.0));
    }
}
