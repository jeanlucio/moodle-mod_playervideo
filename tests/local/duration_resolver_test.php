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
 * Unit tests for the video-duration reconciliation utility.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for duration_resolver.
 *
 * @covers \mod_playervideo\local\duration_resolver
 */
final class duration_resolver_test extends \advanced_testcase {
    /** @var \stdClass The playervideo instance under test. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_playervideo')
            ->create_instance(['course' => $course->id]);
    }

    /**
     * Reconciles against the live instance and returns the value now stored.
     *
     * @param float $reported Reported duration.
     * @param array $segments Merged [start, end] pairs for this heartbeat.
     * @return float The stored duration.
     */
    private function reconcile(float $reported, array $segments = []): float {
        global $DB;
        duration_resolver::reconcile($this->instance, $reported, $segments, $DB);
        return (float) ($DB->get_field('playervideo', 'duration', ['id' => $this->instance->id]) ?? 0);
    }

    /**
     * Tests that the first credible report establishes the value.
     *
     * @return void
     */
    public function test_first_credible_report_establishes_the_value(): void {
        $this->assertEqualsWithDelta(612.0, $this->reconcile(612.0), 0.01);
    }

    /**
     * Tests that an out-of-range report never establishes anything.
     *
     * @return void
     */
    public function test_out_of_range_report_is_ignored(): void {
        $this->assertSame(0.0, $this->reconcile(9999999.0));
        $this->assertSame(0.0, $this->reconcile(-1.0));
        $this->assertSame(0.0, $this->reconcile(INF));
    }

    /**
     * Tests that a lower later report does not shrink an established value.
     *
     * @return void
     */
    public function test_lower_report_does_not_shrink(): void {
        $this->reconcile(600.0);
        $this->assertEqualsWithDelta(600.0, $this->reconcile(200.0), 0.01);
    }

    /**
     * Tests that a small upward correction is tracked while the video is still largely unwatched
     * (a buffering estimate settling on the real length).
     *
     * @return void
     */
    public function test_small_growth_is_tracked_while_unwatched(): void {
        $this->reconcile(3500.0);
        $this->assertEqualsWithDelta(3600.0, $this->reconcile(3600.0, [[0.0, 10.0]]), 0.01);
    }

    /**
     * Tests that a large upward jump is rejected as a hostile ratchet.
     *
     * @return void
     */
    public function test_large_growth_is_rejected(): void {
        $this->reconcile(600.0);
        $this->assertEqualsWithDelta(600.0, $this->reconcile(5000.0), 0.01);
    }

    /**
     * Tests that once most of the video has been watched, the duration is frozen against any
     * further growth — a real length is fixed and a late "correction" is an attack.
     *
     * @return void
     */
    public function test_growth_is_frozen_once_confirmed_by_watching(): void {
        $this->reconcile(600.0);
        $this->assertEqualsWithDelta(600.0, $this->reconcile(650.0, [[0.0, 590.0]]), 0.01);
    }

    /**
     * Tests that the proven-watched position raises a value the report undersells, but never
     * bootstraps a duration from a stray second of playback when no report has ever arrived.
     *
     * @return void
     */
    public function test_evidence_raises_a_resolved_value_but_never_bootstraps_one(): void {
        $this->assertSame(0.0, $this->reconcile(0.0, [[0.0, 3.0]]));

        $this->assertEqualsWithDelta(500.0, $this->reconcile(100.0, [[0.0, 500.0]]), 0.01);
    }
}
