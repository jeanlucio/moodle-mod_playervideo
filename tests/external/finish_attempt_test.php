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
 * External function tests for finish_attempt.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_finish_attempt web service.
 *
 * @covers \mod_playervideo\external\finish_attempt
 */
final class finish_attempt_test extends \advanced_testcase {
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
        $this->instance = $generator->create_instance(['course' => $this->course->id, 'grade' => 100]);

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
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_finish_attempt', [
            'attemptid' => $this->attemptid,
        ]);
    }

    /**
     * Answers a true/false question interaction, correctly or not.
     *
     * @param bool $correct Whether to submit the correct answer.
     * @return void
     */
    private function answer_a_truefalse_question(bool $correct): void {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'correctanswer' => true,
        ]);

        $wanted = $correct ? 1.0 : 0.0;
        $answerid = (int) $DB->get_field_select(
            'question_answers',
            'id',
            'question = :question AND fraction = :fraction',
            ['question' => $question->id, 'fraction' => $wanted]
        );

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 1,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $_POST['sesskey'] = sesskey();
        external_api::call_external_function('mod_playervideo_submit_answer', [
            'attemptid' => $this->attemptid,
            'interactionid' => $interactionid,
            'answerid' => $answerid,
            'responsetext' => '',
        ]);
    }

    /**
     * Tests that finishing an attempt with only correct answers grades it at 100% and sends
     * the grade to the Gradebook.
     *
     * @return void
     */
    public function test_finishes_with_full_grade_and_updates_gradebook(): void {
        global $DB;

        $this->answer_a_truefalse_question(true);

        $result = $this->call();

        $this->assertFalse($result['error']);
        $this->assertSame('finished', $result['data']['status']);
        $this->assertSame(100.0, $result['data']['grade']);

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'playervideo',
            'iteminstance' => $this->instance->id, 'itemnumber' => 0, 'courseid' => $this->course->id,
        ]);
        $grades = \grade_grade::fetch_users_grades($gradeitem, [$this->student->id]);
        $this->assertSame(100.0, (float) $grades[$this->student->id]->finalgrade);
    }

    /**
     * Tests that an attempt with a pending open-question response is withheld from the
     * Gradebook — mirroring mod_quiz's own behaviour (see the plugin SCOPE).
     *
     * @return void
     */
    public function test_withholds_grade_while_pending_correction(): void {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $questiongenerator->create_question('essay', null, ['category' => $category->id]);

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 1,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $_POST['sesskey'] = sesskey();
        external_api::call_external_function('mod_playervideo_submit_answer', [
            'attemptid' => $this->attemptid,
            'interactionid' => $interactionid,
            'answerid' => 0,
            'responsetext' => 'My open answer',
        ]);

        $result = $this->call();

        $this->assertFalse($result['error']);
        $this->assertSame('pendingcorrection', $result['data']['status']);
        $this->assertNull($result['data']['grade']);
        $this->assertSame('pendingcorrection', $DB->get_field('playervideo_attempts', 'status', ['id' => $this->attemptid]));
    }

    /**
     * Tests that finishing an already-finished attempt is refused.
     *
     * @return void
     */
    public function test_rejects_finishing_twice(): void {
        $this->call();
        $result = $this->call();

        $this->assertTrue($result['error']);
        $this->assertSame('error_attemptnotinprogress', $result['exception']->errorcode);
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

        $result = $this->call();

        $this->assertTrue($result['error']);
        $this->assertSame('error_notyourattempt', $result['exception']->errorcode);
    }
}
