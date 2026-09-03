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
 * External function tests for review_response.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;
use mod_playervideo\local\question_service;

/**
 * Tests for the mod_playervideo_review_response web service.
 *
 * @covers \mod_playervideo\external\review_response
 */
final class review_response_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Instance used by every test. */
    private \stdClass $instance;

    /** @var \context_module Context of the instance's course module. */
    private \context_module $context;

    /** @var int The student whose responses are graded in these tests. */
    private int $studentid;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->studentid = (int) $student->id;

        $this->setUser($teacher);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $this->instance->id);
        $this->context = \context_module::instance($cm->id);
    }

    /**
     * Creates a real essay question and returns its id.
     *
     * @return int The new question id.
     */
    private function create_essay_question(): int {
        $categoryid = question_service::get_or_create_category($this->context);

        $formdata = (object) [
            'name' => 'Explain photosynthesis',
            'questiontext' => ['text' => 'Explain photosynthesis.', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'penalty' => 0,
        ];
        foreach (get_object_vars(question_service::build_essay_formdata()) as $field => $value) {
            $formdata->$field = $value;
        }

        return question_service::create_question('essay', $categoryid, $this->context->id, $formdata);
    }

    /**
     * Creates a question interaction, an attempt already marked pendingcorrection, and a
     * pending_review response for the student — returns [responseid, attemptid].
     *
     * @param int $questionid The question id to reference.
     * @param float $weight The interaction's grading weight.
     * @return array{0: int, 1: int} [responseid, attemptid].
     */
    private function create_pending_response(int $questionid, float $weight = 1.0): array {
        global $DB;

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id,
            'timestamp' => 5.0,
            'type' => 'question',
            'weight' => $weight,
            'questionid' => $questionid,
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $this->instance->id,
            'userid' => $this->studentid,
            'attemptnumber' => 1,
            'status' => 'pendingcorrection',
            'grade' => null,
            'hudretrycharged' => 0,
            'timestart' => $now,
            'timefinish' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $responseid = $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $this->instance->id,
            'userid' => $this->studentid,
            'attemptid' => $attemptid,
            'interactionid' => $interactionid,
            'questionid' => $questionid,
            'responsetext' => 'Plants use sunlight to make food.',
            'status' => 'pending_review',
            'hudrewarded' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return [$responseid, $attemptid];
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_review_response', $args);
    }

    /**
     * Tests the full happy path: grading the last pending response finishes the attempt with
     * the expected grade, and persists the teacher's grade/feedback on the response itself.
     *
     * @return void
     */
    public function test_teacher_grading_the_last_pending_response_finishes_the_attempt(): void {
        global $DB;

        $questionid = $this->create_essay_question();
        [$responseid, $attemptid] = $this->create_pending_response($questionid, 2.0);

        $result = $this->call([
            'responseid' => $responseid,
            'teachergrade' => 1.5,
            'teacherfeedback' => 'Mostly correct.',
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame('finished', $result['data']['attemptstatus']);
        $this->assertSame(75.0, $result['data']['grade']);

        $response = $DB->get_record('playervideo_responses', ['id' => $responseid]);
        $this->assertSame('graded', $response->status);
        $this->assertSame(1.5, (float) $response->teachergrade);
        $this->assertSame('Mostly correct.', $response->teacherfeedback);

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $attemptid]);
        $this->assertSame('finished', $attempt->status);
    }

    /**
     * Tests that a grade outside the question's weight is rejected.
     *
     * @return void
     */
    public function test_rejects_a_grade_above_the_question_weight(): void {
        $questionid = $this->create_essay_question();
        [$responseid] = $this->create_pending_response($questionid, 1.0);

        $result = $this->call(['responseid' => $responseid, 'teachergrade' => 1.5]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidgrade', $result['exception']->errorcode);
    }

    /**
     * Tests that the attempt stays pendingcorrection while another response is still pending.
     *
     * @return void
     */
    public function test_attempt_stays_pending_with_another_response_outstanding(): void {
        $questionid = $this->create_essay_question();
        [$firstresponseid, $attemptid] = $this->create_pending_response($questionid, 1.0);

        // A second pending response on the same attempt.
        global $DB;
        $secondinteractionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id,
            'timestamp' => 15.0,
            'type' => 'question',
            'weight' => 1.0,
            'questionid' => $questionid,
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $this->instance->id,
            'userid' => $this->studentid,
            'attemptid' => $attemptid,
            'interactionid' => $secondinteractionid,
            'questionid' => $questionid,
            'responsetext' => 'Another answer.',
            'status' => 'pending_review',
            'hudrewarded' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = $this->call(['responseid' => $firstresponseid, 'teachergrade' => 1.0]);

        $this->assertFalse($result['error']);
        $this->assertSame('pendingcorrection', $result['data']['attemptstatus']);
        $this->assertNull($result['data']['grade']);
    }

    /**
     * Tests that a student cannot review a response — must fail on the capability check.
     *
     * @return void
     */
    public function test_student_cannot_review_a_response(): void {
        $questionid = $this->create_essay_question();
        [$responseid] = $this->create_pending_response($questionid);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['responseid' => $responseid, 'teachergrade' => 0.5]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }
}
