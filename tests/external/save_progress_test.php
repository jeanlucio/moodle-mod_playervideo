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

        $result = $this->call(['lastposition' => 42.5, 'segments' => '[[0,42.5]]']);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['ok']);

        $progress = $DB->get_record('playervideo_progress', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ], '*', MUST_EXIST);
        $this->assertSame(42.5, (float) $progress->lastposition);
        $this->assertSame('[[0,42.5]]', $progress->segments);
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
     * Tests that the ended flag flips watchedtoend to 1 (completion rule 2, see the plugin
     * SCOPE).
     *
     * @return void
     */
    public function test_ended_flag_sets_watchedtoend(): void {
        global $DB;

        $this->call(['lastposition' => 120, 'ended' => true]);

        $this->assertSame(1, (int) $DB->get_field('playervideo_progress', 'watchedtoend', [
            'playervideoid' => $this->instance->id,
            'userid' => $this->student->id,
        ]));
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
