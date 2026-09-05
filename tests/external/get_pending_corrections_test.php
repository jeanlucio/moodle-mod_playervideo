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
 * External function tests for get_pending_corrections.
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
 * Tests for the mod_playervideo_get_pending_corrections web service.
 *
 * @covers \mod_playervideo\external\get_pending_corrections
 */
final class get_pending_corrections_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Instance used by every test. */
    private \stdClass $instance;

    /** @var \context_module Context of the instance's course module. */
    private \context_module $context;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $this->instance->id);
        $this->context = \context_module::instance($cm->id);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_get_pending_corrections', $args);
    }

    /**
     * Tests that a pending response is listed with its question/student/AI-suggestion context,
     * and that an already-graded response is excluded.
     *
     * @return void
     */
    public function test_lists_pending_responses_and_excludes_graded_ones(): void {
        global $DB;

        $categoryid = question_service::get_or_create_category($this->context);
        $formdata = (object) [
            'name' => 'Essay',
            'questiontext' => ['text' => 'Explain photosynthesis.', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'penalty' => 0,
        ];
        foreach (get_object_vars(question_service::build_essay_formdata()) as $field => $value) {
            $formdata->$field = $value;
        }
        $questionid = question_service::create_question('essay', $categoryid, $this->context->id, $formdata);

        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Silva']);
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id,
            'timestamp' => 5.0,
            'type' => 'question',
            'weight' => 2.0,
            'questionid' => $questionid,
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $this->instance->id,
            'userid' => $student->id,
            'attemptnumber' => 1,
            'status' => 'pendingcorrection',
            'grade' => null,
            'hudretrycharged' => 0,
            'timestart' => $now,
            'timefinish' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $pendingid = $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $this->instance->id,
            'userid' => $student->id,
            'attemptid' => $attemptid,
            'interactionid' => $interactionid,
            'questionid' => $questionid,
            'responsetext' => 'Plants use sunlight.',
            'aigrade' => 1.5,
            'aifeedback' => 'Mostly complete.',
            'status' => 'pending_review',
            'hudrewarded' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // A second interaction — UNIQUE(interactionid, attemptid) means the already-graded
        // response below cannot share the pending one's interactionid within the same attempt.
        $gradedinteractionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id,
            'timestamp' => 15.0,
            'type' => 'question',
            'weight' => 2.0,
            'questionid' => $questionid,
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $this->instance->id,
            'userid' => $student->id,
            'attemptid' => $attemptid,
            'interactionid' => $gradedinteractionid,
            'questionid' => $questionid,
            'responsetext' => 'Already handled.',
            'teachergrade' => 2.0,
            'status' => 'graded',
            'hudrewarded' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $this->assertCount(1, $result['data']['responses']);
        $row = $result['data']['responses'][0];
        $this->assertSame($pendingid, $row['responseid']);
        $this->assertSame('Ana Silva', $row['fullname']);
        $this->assertSame('Plants use sunlight.', $row['responsetext']);
        $this->assertSame(1.5, $row['aigrade']);
        $this->assertSame(2.0, $row['maxgrade']);
    }

    /**
     * Tests that a student cannot list pending corrections.
     *
     * @return void
     */
    public function test_student_cannot_list_pending_corrections(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }

    /**
     * Regression test for the separate-groups leak: a non-editing
     * teacher restricted to one group, with no moodle/site:accessallgroups, must not see a
     * pending open-question response from a student in a different group.
     *
     * @return void
     */
    public function test_excludes_responses_from_a_student_in_a_different_separate_group(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id);
        $context = \context_module::instance($cm->id);

        $groupone = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $grouptwo = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $studentingrouptwo = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studentingrouptwo->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $grouptwo->id, 'userid' => $studentingrouptwo->id]);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupone->id, 'userid' => $teacher->id]);

        $categoryid = question_service::get_or_create_category($context);
        $formdata = (object) [
            'name' => 'Essay',
            'questiontext' => ['text' => 'Explain.', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 2,
            'penalty' => 0,
        ];
        foreach (get_object_vars(question_service::build_essay_formdata()) as $field => $value) {
            $formdata->$field = $value;
        }
        $questionid = question_service::create_question('essay', $categoryid, $context->id, $formdata);

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 5.0, 'type' => 'question', 'weight' => 2.0,
            'questionid' => $questionid, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => $studentingrouptwo->id, 'attemptnumber' => 1,
            'status' => 'pendingcorrection', 'grade' => null, 'hudretrycharged' => 0,
            'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $instance->id, 'userid' => $studentingrouptwo->id, 'attemptid' => $attemptid,
            'interactionid' => $interactionid, 'questionid' => $questionid, 'responsetext' => 'My answer',
            'status' => 'pending_review', 'hudrewarded' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->setUser($teacher);
        $result = $this->call(['playervideoid' => $instance->id]);

        $this->assertFalse($result['error']);
        $this->assertSame([], $result['data']['responses']);
    }
}
