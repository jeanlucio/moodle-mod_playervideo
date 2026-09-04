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
 * External function tests for get_attempt_review.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_get_attempt_review web service.
 *
 * @covers \mod_playervideo\external\get_attempt_review
 */
final class get_attempt_review_test extends \advanced_testcase {
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
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_get_attempt_review', [
            'attemptid' => $this->attemptid,
        ]);
    }

    /**
     * Tests that a note, a correctly-answered question and a never-reached interaction are
     * each reported with the right status.
     *
     * @return void
     */
    public function test_reports_notes_answers_and_unreached_interactions(): void {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'correctanswer' => true,
        ]);
        $correctanswerid = (int) $DB->get_field_select(
            'question_answers',
            'id',
            'question = :question AND fraction = :fraction',
            ['question' => $question->id, 'fraction' => 1.0]
        );

        $now = time();
        $noteid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 5, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Watch this', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $questioninteractionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 1,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $unreachedid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 999, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Never reached', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $_POST['sesskey'] = sesskey();
        external_api::call_external_function('mod_playervideo_submit_answer', [
            'attemptid' => $this->attemptid, 'interactionid' => $noteid, 'answerid' => 0, 'responsetext' => '',
        ]);
        external_api::call_external_function('mod_playervideo_submit_answer', [
            'attemptid' => $this->attemptid, 'interactionid' => $questioninteractionid,
            'answerid' => $correctanswerid, 'responsetext' => '',
        ]);

        $result = $this->call();

        $this->assertFalse($result['error']);
        $rows = $result['data']['interactions'];
        $this->assertCount(3, $rows);

        // Ordered by timestamp: note (5), question (10), unreached note (999).
        $this->assertSame('viewed', $rows[0]['status']);
        $this->assertSame('answered', $rows[1]['status']);
        $this->assertTrue($rows[1]['iscorrect']);
        $correctoption = current(array_filter($rows[1]['options'], static fn(array $o): bool => $o['correct']));
        $this->assertTrue($correctoption['selected']);
        $this->assertSame('notreached', $rows[2]['status']);
    }

    /**
     * Tests that a poll vote is reported with the full vote distribution, and the option this
     * attempt chose flagged as selected — with never a "correct" one.
     *
     * @return void
     */
    public function test_reports_a_poll_vote_with_distribution(): void {
        global $DB;

        $now = time();
        $pollid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 20, 'type' => 'poll', 'weight' => 1,
            'questionid' => null, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $redid = $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $pollid, 'optiontext' => 'Red', 'sortorder' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $blueid = $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $pollid, 'optiontext' => 'Blue', 'sortorder' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $_POST['sesskey'] = sesskey();
        external_api::call_external_function('mod_playervideo_submit_answer', [
            'attemptid' => $this->attemptid, 'interactionid' => $pollid, 'polloptionid' => $blueid,
        ]);

        $result = $this->call();

        $this->assertFalse($result['error']);
        $row = $result['data']['interactions'][0];
        $this->assertSame('poll', $row['type']);
        $this->assertSame('voted', $row['status']);
        $this->assertCount(2, $row['options']);
        foreach ($row['options'] as $option) {
            $this->assertFalse($option['correct']);
            $wasvoted = $option['id'] === (int) $blueid;
            $this->assertSame($wasvoted, $option['selected']);
            $this->assertSame($wasvoted ? 1 : 0, $option['votes']);
        }
    }

    /**
     * Tests that a note's HTML is sanitized through format_text(), matching the live playback
     * path (view.php) and the transcript path (transcript_service), instead of being returned
     * raw. A raw PARAM_RAW passthrough here is a stored XSS: save_interaction accepts arbitrary
     * HTML in notetext, and the review template renders it with triple-mustache.
     *
     * @return void
     */
    public function test_note_html_is_sanitized_by_format_text(): void {
        global $DB;

        $now = time();
        $noteid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 5, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => '<img src=x onerror="alert(1)">Hello',
            'notetextformat' => FORMAT_HTML, 'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $_POST['sesskey'] = sesskey();
        external_api::call_external_function('mod_playervideo_submit_answer', [
            'attemptid' => $this->attemptid, 'interactionid' => $noteid, 'answerid' => 0, 'responsetext' => '',
        ]);

        $result = $this->call();

        $this->assertFalse($result['error']);
        $notetext = $result['data']['interactions'][0]['notetext'];
        $this->assertStringNotContainsString('onerror', $notetext);
        $this->assertStringContainsString('Hello', $notetext);
    }

    /**
     * Tests that another student cannot review this attempt without the reviewresponses
     * capability.
     *
     * @return void
     */
    public function test_another_student_cannot_review_without_capability(): void {
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $this->course->id, 'student');
        $this->setUser($otherstudent);

        $this->expectException(\required_capability_exception::class);
        get_attempt_review::execute($this->attemptid);
    }

    /**
     * Tests that a teacher with reviewresponses can review any student's attempt.
     *
     * @return void
     */
    public function test_teacher_can_review_with_capability(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = get_attempt_review::execute($this->attemptid);

        $this->assertSame([], $result['interactions']);
    }
}
