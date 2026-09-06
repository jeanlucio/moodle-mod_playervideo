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
 * External function tests for save_progress.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;
use mod_playervideo\local\segment_tracker;

/**
 * Tests for the mod_playervideo_save_progress web service.
 *
 * @covers \mod_playervideo\external\save_progress
 */
final class save_progress_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Student, enrolled in $course. */
    private \stdClass $student;

    /** @var \stdClass PlayerVideo instance used by every test. */
    private \stdClass $instance;

    /** @var int Open attempt id for $this->student on $this->instance. */
    private int $attemptid;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);

        $this->setUser($this->student);
        $_POST['sesskey'] = sesskey();
        $started = external_api::call_external_function('mod_playervideo_start_attempt', [
            'playervideoid' => $this->instance->id,
        ]);
        $this->attemptid = $started['data']['attemptid'];
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_save_progress', array_merge([
            'attemptid' => $this->attemptid,
            'segments' => '[]',
            'ended' => false,
        ], $args));
    }

    /**
     * Tests that a first heartbeat creates the progress row.
     *
     * @return void
     */
    public function test_creates_the_progress_row(): void {
        global $DB;

        $result = $this->call(['lastposition' => 42.5, 'segments' => '[[0,42.5]]', 'duration' => 100]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['ok']);

        $progress = $DB->get_record('playervideo_progress', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ], '*', MUST_EXIST);
        $this->assertSame(42.5, (float) $progress->lastposition);
        $this->assertEquals([[0.0, 42.5]], json_decode($progress->segments, true));
        $this->assertSame(0, (int) $progress->watchedtoend);
    }

    /**
     * Tests that a later heartbeat updates the same row instead of inserting a second one.
     *
     * @return void
     */
    public function test_updates_the_existing_progress_row(): void {
        global $DB;

        $this->call(['lastposition' => 10]);
        $this->call(['lastposition' => 20]);

        $this->assertSame(1, $DB->count_records('playervideo_progress', ['playervideoid' => $this->instance->id]));
        $this->assertSame(20.0, (float) $DB->get_field('playervideo_progress', 'lastposition', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ]));
    }

    /**
     * Tests that the ended flag flips watchedtoend to 1 (the "watched to the end" completion
     * rule) when corroborated by real merged coverage of the playback window.
     *
     * @return void
     */
    public function test_ended_flag_sets_watchedtoend_when_corroborated_by_coverage(): void {
        global $DB;

        $DB->set_field('playervideo', 'duration', 120, ['id' => $this->instance->id]);

        $this->call(['lastposition' => 120, 'segments' => '[[0,120]]', 'ended' => true]);

        $this->assertSame(1, (int) $DB->get_field('playervideo_progress', 'watchedtoend', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ]));
    }

    /**
     * Regression test for the confirmed low-severity finding: a heartbeat claiming ended=true
     * while contributing little or no real segment coverage (e.g. segments: '[]', the "cold
     * start" completion forgery) must not be honoured — the native ended event only ever fires
     * after real playback, which would always leave a high watchedpct behind it.
     *
     * @return void
     */
    public function test_ended_flag_is_ignored_without_corroborating_coverage(): void {
        global $DB;

        $DB->set_field('playervideo', 'duration', 600, ['id' => $this->instance->id]);

        $result = $this->call(['lastposition' => 0, 'segments' => '[]', 'ended' => true]);

        $this->assertFalse($result['error']);
        $this->assertEqualsWithDelta(0.0, $result['data']['watchedpct'], 0.01);
        $this->assertSame(0, (int) $DB->get_field('playervideo_progress', 'watchedtoend', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ]));
    }

    /**
     * Tests that ended=true is only honoured once accumulated coverage across heartbeats
     * actually crosses the corroboration floor — a partial-coverage heartbeat claiming ended
     * must not flip watchedtoend prematurely.
     *
     * @return void
     */
    public function test_ended_flag_is_ignored_below_the_corroboration_floor(): void {
        global $DB;

        $DB->set_field('playervideo', 'duration', 100, ['id' => $this->instance->id]);

        // Only half the window covered — well under the corroboration floor.
        $result = $this->call(['lastposition' => 50, 'segments' => '[[0,50]]', 'ended' => true]);

        $this->assertFalse($result['error']);
        $this->assertEqualsWithDelta(50.0, $result['data']['watchedpct'], 0.01);
        $this->assertSame(0, (int) $DB->get_field('playervideo_progress', 'watchedtoend', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ]));
    }

    /**
     * Tests that watchedpct reflects only the unique seconds actually watched, merged across
     * heartbeats — not the raw client segments and not the last reported position (regression
     * test for a real gap where watchedpct was never written).
     *
     * @return void
     */
    public function test_calculates_watchedpct_from_merged_segments(): void {
        global $DB;

        // Duration is set by the teacher (via save_trim), not by the heartbeat.
        $DB->set_field('playervideo', 'duration', 600, ['id' => $this->instance->id]);

        $this->call(['lastposition' => 60, 'segments' => '[[0,60]]']);
        $result = $this->call(['lastposition' => 600, 'segments' => '[[0,60],[480,600]]']);

        $this->assertFalse($result['error']);
        $this->assertEqualsWithDelta(30.0, $result['data']['watchedpct'], 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $DB->get_field('playervideo_progress', 'watchedpct', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ]), 0.01);
    }

    /**
     * Tests that the student heartbeat never writes playervideo.duration — not to establish it
     * on a fresh activity, and not to ratchet an already-set value. Only a teacher (save_trim)
     * owns that shared, class-wide column.
     *
     * @return void
     */
    public function test_save_progress_never_writes_the_instance_duration(): void {
        global $DB;

        // Fresh activity, no teacher value yet: a huge reported duration must not establish it.
        $this->call(['lastposition' => 1, 'segments' => '[[0,1]]', 'duration' => 999999999]);
        $this->assertNull($DB->get_field('playervideo', 'duration', ['id' => $this->instance->id]));

        // Established value: repeated heartbeats (large jump and slow ratchet) must not move it.
        $DB->set_field('playervideo', 'duration', 600, ['id' => $this->instance->id]);
        $this->call(['lastposition' => 90, 'segments' => '[]', 'duration' => 90000]);
        for ($i = 0; $i < 10; $i++) {
            $this->call(['lastposition' => 90, 'segments' => '[]', 'duration' => 660 + $i * 90]);
        }
        $this->assertEqualsWithDelta(600.0, (float) $DB->get_field('playervideo', 'duration', [
            'id' => $this->instance->id,
        ]), 0.01);
    }

    /**
     * Tests that the stored resume position is clamped to the (teacher-set) video duration — a
     * heartbeat cannot park lastposition at an absurd value.
     *
     * @return void
     */
    public function test_lastposition_is_clamped_to_duration(): void {
        global $DB;

        $DB->set_field('playervideo', 'duration', 600, ['id' => $this->instance->id]);

        $this->call(['lastposition' => 60, 'segments' => '[[0,60]]']);
        $this->call(['lastposition' => 999999]);

        $this->assertEqualsWithDelta(600.0, (float) $DB->get_field('playervideo_progress', 'lastposition', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ]), 0.01);
    }

    /**
     * Tests that watchedpct is relative to the activity's own trim window, not the raw video
     * duration — a video cut to end before its real length must not require watching the
     * discarded tail to reach 100%.
     *
     * @return void
     */
    public function test_watchedpct_respects_trim_window(): void {
        global $DB;

        $DB->set_field('playervideo', 'trimstart', 100, ['id' => $this->instance->id]);
        $DB->set_field('playervideo', 'trimend', 500, ['id' => $this->instance->id]);

        $result = $this->call(['lastposition' => 600, 'segments' => '[[0,600]]', 'duration' => 600]);

        $this->assertEqualsWithDelta(100.0, $result['data']['watchedpct'], 0.01);
    }

    /**
     * Tests that a malformed interval (end before start) is silently dropped, never persisted
     * and never inflates watchedpct — the server no longer trusts client-reported ranges as-is.
     *
     * @return void
     */
    public function test_invalid_interval_is_dropped(): void {
        $result = $this->call(['lastposition' => 5, 'segments' => '[[50,10]]', 'duration' => 600]);

        $this->assertFalse($result['error']);
        $this->assertEqualsWithDelta(0.0, $result['data']['watchedpct'], 0.01);
    }

    /**
     * Tests that a heartbeat carrying an absurd number of intervals is rejected outright, so a
     * crafted payload cannot bloat playervideo_progress.segments into a multi-megabyte blob.
     *
     * @return void
     */
    public function test_rejects_a_heartbeat_with_too_many_segments(): void {
        $segments = [];
        for ($i = 0; $i <= segment_tracker::MAX_INTERVALS; $i++) {
            $segments[] = [$i * 2, $i * 2 + 1];
        }

        $result = $this->call(['lastposition' => 5, 'segments' => json_encode($segments)]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_toomanysegments', $result['exception']->errorcode);
    }

    /**
     * Tests that malformed JSON in segments is rejected.
     *
     * @return void
     */
    public function test_rejects_invalid_segments_json(): void {
        $result = $this->call(['lastposition' => 5, 'segments' => 'not-json{']);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidsegments', $result['exception']->errorcode);
    }

    /**
     * Tests that an attempt belonging to a different student is refused.
     *
     * @return void
     */
    public function test_rejects_someone_elses_attempt(): void {
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $this->course->id, 'student');
        $this->setUser($otherstudent);

        $result = $this->call(['lastposition' => 5]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_notyourattempt', $result['exception']->errorcode);
    }
}
