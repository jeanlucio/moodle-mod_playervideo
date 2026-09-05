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
 * Unit tests for the class-wide engagement timeline builder.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for engagement_aggregator.
 *
 * @covers \mod_playervideo\local\engagement_aggregator
 */
final class engagement_aggregator_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that an invalid window (end at or before start) yields an all-zero timeline with no
     * highlighted region, rather than a division-by-zero or a misleading default.
     *
     * @return void
     */
    public function test_invalid_window_returns_all_zero_buckets(): void {
        $result = engagement_aggregator::build([[[0, 100]]], 50.0, 50.0);

        $this->assertNotEmpty($result['buckets']);
        $this->assertSame(0.0, array_sum($result['buckets']));
        $this->assertSame(0.0, $result['bucketlength']);
        $this->assertNull($result['mostwatchedbucket']);
        $this->assertNull($result['leastwatchedbucket']);
        $this->assertNull($result['dropoffbucket']);
    }

    /**
     * Tests that no student data at all yields the same all-zero, no-highlight result.
     *
     * @return void
     */
    public function test_no_students_returns_all_zero_buckets(): void {
        $result = engagement_aggregator::build([], 0.0, 100.0);

        $this->assertSame(0.0, array_sum($result['buckets']));
        $this->assertNull($result['mostwatchedbucket']);
        $this->assertNull($result['leastwatchedbucket']);
        $this->assertNull($result['dropoffbucket']);
    }

    /**
     * Tests that a watched interval contributes exactly its own length, split across the buckets
     * it overlaps — never more, never less.
     *
     * A 40-second window is used so each of the 40 buckets is exactly one second wide, keeping
     * the expected numbers simple to state and verify by hand.
     *
     * @return void
     */
    public function test_a_single_interval_fills_only_the_buckets_it_overlaps(): void {
        $result = engagement_aggregator::build([[[5, 10]]], 0.0, 40.0);

        $this->assertEqualsWithDelta(5.0, array_sum($result['buckets']), 0.001);
        foreach ($result['buckets'] as $index => $seconds) {
            $expected = $index >= 5 && $index < 10 ? 1.0 : 0.0;
            $this->assertEqualsWithDelta($expected, $seconds, 0.001, "bucket $index");
        }
        $this->assertEqualsWithDelta(1.0, $result['bucketlength'], 0.001);
        $this->assertSame(0.0, $result['windowstart']);
    }

    /**
     * Tests that segments are clipped to the window before being bucketed — content outside the
     * activity's own trim window must never count towards the class timeline.
     *
     * @return void
     */
    public function test_intervals_outside_the_window_are_clipped(): void {
        $result = engagement_aggregator::build([[[0, 5], [95, 100]]], 10.0, 30.0);

        $this->assertSame(0.0, array_sum($result['buckets']));
    }

    /**
     * Tests that watched seconds are summed across every student sharing the same region, and
     * that the most-watched region is the one every student actually overlapped.
     *
     * @return void
     */
    public function test_aggregates_across_multiple_students(): void {
        $result = engagement_aggregator::build([
            [[5, 10]],
            [[8, 20]],
        ], 0.0, 40.0);

        $this->assertEqualsWithDelta(2.0, $result['buckets'][8], 0.001);
        $this->assertEqualsWithDelta(2.0, $result['buckets'][9], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['buckets'][5], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['buckets'][19], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['buckets'][0], 0.001);
        // The first bucket reaching the shared maximum wins, ties broken by timeline order.
        $this->assertSame(8, $result['mostwatchedbucket']);
        // Bucket 0 is never watched at all — the least-watched region includes true zeroes.
        $this->assertSame(0, $result['leastwatchedbucket']);
    }

    /**
     * Tests that the drop-off region is the one where viewership falls the most compared to the
     * region right before it, and that ties keep the earliest such region.
     *
     * @return void
     */
    public function test_identifies_the_largest_drop_in_viewership(): void {
        $result = engagement_aggregator::build([
            [[5, 10]],
            [[8, 20]],
        ], 0.0, 40.0);

        $this->assertSame(10, $result['dropoffbucket']);
    }

    /**
     * Tests that a flat timeline (every watched region equally intense) has no drop-off point —
     * a drop must be a real decrease, not merely the boundary of the watched content.
     *
     * @return void
     */
    public function test_flat_engagement_has_no_dropoff(): void {
        $result = engagement_aggregator::build([[[0, 40]]], 0.0, 40.0);

        $this->assertNull($result['dropoffbucket']);
    }

    /**
     * Tests that a malformed per-student entry (not an array) is treated as no data for that
     * student, rather than raising an error and losing every other student's contribution.
     *
     * @return void
     */
    public function test_malformed_student_entry_is_ignored(): void {
        $result = engagement_aggregator::build([
            'not-an-array',
            [[0, 10]],
        ], 0.0, 40.0);

        $this->assertEqualsWithDelta(10.0, array_sum($result['buckets']), 0.001);
    }
}
