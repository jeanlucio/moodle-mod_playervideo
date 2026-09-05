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
 * External function tests for get_report.
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
 * Tests for the mod_playervideo_get_report web service.
 *
 * @covers \mod_playervideo\external\get_report
 */
final class get_report_test extends \advanced_testcase {
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
        return external_api::call_external_function('mod_playervideo_get_report', $args);
    }

    /**
     * Tests the per-question aggregate: a multichoice question answered correctly by one
     * student and incorrectly by another shows 50% correct; the per-student aggregate shows
     * one attempt and the finished grade for each.
     *
     * @return void
     */
    public function test_report_aggregates_multichoice_correctness_and_student_grades(): void {
        global $DB;

        $categoryid = question_service::get_or_create_category($this->context);
        $formdata = (object) [
            'name' => 'MC',
            'questiontext' => ['text' => 'Pick the right one.', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'penalty' => 0,
        ];
        $qtypedata = question_service::build_multichoice_formdata(
            [['text' => 'Right', 'correct' => true], ['text' => 'Wrong', 'correct' => false]],
            true
        );
        foreach (get_object_vars($qtypedata) as $field => $value) {
            $formdata->$field = $value;
        }
        $questionid = question_service::create_question('multichoice', $categoryid, $this->context->id, $formdata);

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id,
            'timestamp' => 5.0,
            'type' => 'question',
            'weight' => 1.0,
            'questionid' => $questionid,
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $studentone = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Silva']);
        $this->getDataGenerator()->enrol_user($studentone->id, $this->course->id, 'student');
        $studenttwo = $this->getDataGenerator()->create_user(['firstname' => 'Bruno', 'lastname' => 'Souza']);
        $this->getDataGenerator()->enrol_user($studenttwo->id, $this->course->id, 'student');

        foreach ([[$studentone, 1, 100.0], [$studenttwo, 0, 0.0]] as [$student, $iscorrect, $expectedgrade]) {
            $attemptid = $DB->insert_record('playervideo_attempts', (object) [
                'playervideoid' => $this->instance->id,
                'userid' => $student->id,
                'attemptnumber' => 1,
                'status' => 'finished',
                'grade' => $expectedgrade,
                'hudretrycharged' => 0,
                'timestart' => $now,
                'timefinish' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $DB->insert_record('playervideo_responses', (object) [
                'playervideoid' => $this->instance->id,
                'userid' => $student->id,
                'attemptid' => $attemptid,
                'interactionid' => $interactionid,
                'questionid' => $questionid,
                'iscorrect' => $iscorrect,
                'status' => 'answered',
                'hudrewarded' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $this->assertCount(1, $result['data']['byquestion']);
        $this->assertSame(2, $result['data']['byquestion'][0]['totalresponses']);
        $this->assertSame(1, $result['data']['byquestion'][0]['correctcount']);
        $this->assertSame(50.0, $result['data']['byquestion'][0]['percentcorrect']);

        $this->assertCount(2, $result['data']['bystudent']);
        $byname = [];
        foreach ($result['data']['bystudent'] as $row) {
            $byname[$row['fullname']] = $row;
        }
        $this->assertSame(1, $byname['Ana Silva']['attemptscount']);
        $this->assertSame(100.0, $byname['Ana Silva']['finalgrade']);
        $this->assertSame(0.0, $byname['Bruno Souza']['finalgrade']);
    }

    /**
     * Tests that a student cannot view the report — must fail on the capability check.
     *
     * @return void
     */
    public function test_student_cannot_view_the_report(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }

    /**
     * Tests that an instance with no questions/students returns empty arrays, not an error.
     *
     * @return void
     */
    public function test_returns_empty_arrays_with_no_data_yet(): void {
        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $this->assertSame([], $result['data']['byquestion']);
        $this->assertSame([], $result['data']['bystudent']);
        $this->assertSame(0.0, array_sum($result['data']['engagement']['buckets']));
        $this->assertNull($result['data']['engagement']['mostwatchedbucket']);
    }

    /**
     * Tests that the class-wide engagement timeline sums watched seconds across every
     * eligible student's already-normalised segments, without exposing a per-student breakdown.
     *
     * @return void
     */
    public function test_engagement_aggregates_watched_seconds_across_students(): void {
        global $DB;

        $DB->set_field('playervideo', 'duration', 40, ['id' => $this->instance->id]);

        $studentone = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studentone->id, $this->course->id, 'student');
        $studenttwo = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studenttwo->id, $this->course->id, 'student');

        $now = time();
        foreach ([[$studentone, '[[5,10]]'], [$studenttwo, '[[8,20]]']] as [$student, $segments]) {
            $DB->insert_record('playervideo_progress', (object) [
                'playervideoid' => $this->instance->id,
                'userid' => $student->id,
                'lastposition' => 0,
                'watchedpct' => 0,
                'watchedtoend' => 0,
                'segments' => $segments,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $engagement = $result['data']['engagement'];
        $this->assertEqualsWithDelta(2.0, $engagement['buckets'][8], 0.001);
        $this->assertEqualsWithDelta(2.0, $engagement['buckets'][9], 0.001);
        $this->assertSame(8, $engagement['mostwatchedbucket']);
        $this->assertSame(0, $engagement['leastwatchedbucket']);
    }

    /**
     * Regression test for the separate-groups leak: a non-editing
     * teacher restricted to one group, with no moodle/site:accessallgroups, must not see a
     * student from a different group in the per-student aggregate — even though
     * mod/playervideo:viewreports itself is not group-scoped.
     *
     * @return void
     */
    public function test_bystudent_excludes_students_from_a_different_separate_group(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);

        $groupone = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $grouptwo = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $studentingroupone = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'GroupOne']);
        $this->getDataGenerator()->enrol_user($studentingroupone->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupone->id, 'userid' => $studentingroupone->id]);

        $studeningrouptwo = $this->getDataGenerator()->create_user(['firstname' => 'Bruno', 'lastname' => 'GroupTwo']);
        $this->getDataGenerator()->enrol_user($studeningrouptwo->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $grouptwo->id, 'userid' => $studeningrouptwo->id]);

        $teacher = $this->getDataGenerator()->create_user();
        // Non-editing 'teacher' archetype: has mod/playervideo:viewreports by default, but not
        // moodle/site:accessallgroups — exactly the gap this regression test exercises.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupone->id, 'userid' => $teacher->id]);

        $now = time();
        foreach ([$studentingroupone, $studeningrouptwo] as $student) {
            $DB->insert_record('playervideo_attempts', (object) [
                'playervideoid' => $instance->id, 'userid' => $student->id, 'attemptnumber' => 1,
                'status' => 'finished', 'grade' => 100.0, 'hudretrycharged' => 0,
                'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        $this->setUser($teacher);
        $result = $this->call(['playervideoid' => $instance->id]);

        $this->assertFalse($result['error']);
        $seenuserids = array_column($result['data']['bystudent'], 'userid');
        $this->assertContains((int) $studentingroupone->id, $seenuserids);
        $this->assertNotContains((int) $studeningrouptwo->id, $seenuserids);
    }

    /**
     * Tests that the engagement timeline respects the same group restriction as the
     * per-student aggregate — a group-restricted teacher must never see another group's watched
     * seconds folded into the class-wide totals.
     *
     * @return void
     */
    public function test_engagement_excludes_students_from_a_different_separate_group(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $DB->set_field('playervideo', 'duration', 40, ['id' => $instance->id]);

        $groupone = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $grouptwo = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $studentingroupone = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studentingroupone->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupone->id, 'userid' => $studentingroupone->id]);

        $studeningrouptwo = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studeningrouptwo->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $grouptwo->id, 'userid' => $studeningrouptwo->id]);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupone->id, 'userid' => $teacher->id]);

        $now = time();
        foreach ([[$studentingroupone, '[[0,10]]'], [$studeningrouptwo, '[[20,30]]']] as [$student, $segments]) {
            $DB->insert_record('playervideo_progress', (object) [
                'playervideoid' => $instance->id, 'userid' => $student->id, 'lastposition' => 0,
                'watchedpct' => 0, 'watchedtoend' => 0, 'segments' => $segments,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        $this->setUser($teacher);
        $result = $this->call(['playervideoid' => $instance->id]);

        $this->assertFalse($result['error']);
        $buckets = $result['data']['engagement']['buckets'];
        $this->assertEqualsWithDelta(10.0, array_sum($buckets), 0.001);
        // Group two's [20,30] segment must contribute nothing to the totals.
        for ($index = 20; $index < 30; $index++) {
            $this->assertEqualsWithDelta(0.0, $buckets[$index], 0.001, "bucket $index");
        }
    }
}
