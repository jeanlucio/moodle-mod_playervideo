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
 * External function tests for start_attempt.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_start_attempt web service.
 *
 * @covers \mod_playervideo\external\start_attempt
 */
final class start_attempt_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Student, enrolled in $course. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->setUser($this->student);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(int $playervideoid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_start_attempt', [
            'playervideoid' => $playervideoid,
        ]);
    }

    /**
     * Creates a playervideo instance in $this->course.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        return $generator->create_instance(array_merge(['course' => $this->course->id], $overrides));
    }

    /**
     * Tests that a first attempt is created with no interactions yet treated.
     *
     * @return void
     */
    public function test_starts_a_first_attempt(): void {
        global $DB;

        $instance = $this->make_instance();

        $result = $this->call($instance->id);

        $this->assertFalse($result['error']);
        $this->assertSame([], $result['data']['treatedinteractionids']);
        $this->assertSame(1, $result['data']['attemptnumber']);

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $result['data']['attemptid']], '*', MUST_EXIST);
        $this->assertSame(1, (int) $attempt->attemptnumber);
        $this->assertSame('inprogress', $attempt->status);
        $this->assertSame((int) $this->student->id, (int) $attempt->userid);
    }

    /**
     * Tests that calling the service again while an attempt is open resumes it instead of
     * creating a second one.
     *
     * @return void
     */
    public function test_resumes_the_open_attempt(): void {
        global $DB;

        $instance = $this->make_instance();

        $first = $this->call($instance->id);
        $second = $this->call($instance->id);

        $this->assertSame($first['data']['attemptid'], $second['data']['attemptid']);
        $this->assertSame(1, $DB->count_records('playervideo_attempts', ['playervideoid' => $instance->id]));
    }

    /**
     * Tests that the already-treated interactions of the resumed attempt are reported back,
     * so the player never pauses on them again (see the plugin SCOPE).
     *
     * @return void
     */
    public function test_reports_already_treated_interactions(): void {
        global $DB;

        $instance = $this->make_instance();
        $started = $this->call($instance->id);
        $attemptid = $started['data']['attemptid'];

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 5, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'A note', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $instance->id, 'userid' => $this->student->id, 'attemptid' => $attemptid,
            'interactionid' => $interactionid, 'questionid' => null, 'answerid' => null,
            'responsetext' => null, 'iscorrect' => null, 'hudrewarded' => 0, 'aigrade' => null,
            'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'viewed', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $resumed = $this->call($instance->id);

        $this->assertSame([$interactionid], $resumed['data']['treatedinteractionids']);
    }

    /**
     * Tests that maxattempts is enforced against the total number of attempts, regardless of
     * their status.
     *
     * @return void
     */
    public function test_no_attempts_left_is_rejected(): void {
        global $DB;

        $instance = $this->make_instance(['maxattempts' => 1]);

        $now = time();
        $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => $this->student->id, 'attemptnumber' => 1,
            'status' => 'finished', 'grade' => 100, 'hudretrycharged' => 0,
            'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $result = $this->call($instance->id);

        $this->assertTrue($result['error']);
        $this->assertSame('error_noattemptsleft', $result['exception']->errorcode);
    }

    /**
     * Tests that a pendingcorrection attempt is never resumed for playback — there is nothing
     * left to play, only the grade is withheld — a fresh attempt starts instead when
     * maxattempts still allows one.
     *
     * @return void
     */
    public function test_pendingcorrection_attempt_is_not_resumed(): void {
        global $DB;

        $instance = $this->make_instance(['maxattempts' => 0]);

        $now = time();
        $pendingid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => $this->student->id, 'attemptnumber' => 1,
            'status' => 'pendingcorrection', 'grade' => null, 'hudretrycharged' => 0,
            'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $result = $this->call($instance->id);

        $this->assertFalse($result['error']);
        $this->assertNotSame($pendingid, $result['data']['attemptid']);
        $attempt = $DB->get_record('playervideo_attempts', ['id' => $result['data']['attemptid']], '*', MUST_EXIST);
        $this->assertSame(2, (int) $attempt->attemptnumber);
        $this->assertSame('inprogress', $attempt->status);
    }

    /**
     * Tests that a second attempt does not attempt an unconfigured PlayerHUD retry charge —
     * hudretrycharged must stay 0 when the instance never set a retry cost item.
     *
     * @return void
     */
    public function test_second_attempt_without_retry_cost_is_never_charged(): void {
        global $DB;

        $instance = $this->make_instance(['maxattempts' => 0, 'hudretrycostitem' => 0]);

        $now = time();
        $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => $this->student->id, 'attemptnumber' => 1,
            'status' => 'finished', 'grade' => 100, 'hudretrycharged' => 0,
            'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $result = $this->call($instance->id);

        $this->assertFalse($result['error']);
        $attempt = $DB->get_record('playervideo_attempts', ['id' => $result['data']['attemptid']], '*', MUST_EXIST);
        $this->assertSame(2, (int) $attempt->attemptnumber);
        $this->assertSame(0, (int) $attempt->hudretrycharged);
    }
}
