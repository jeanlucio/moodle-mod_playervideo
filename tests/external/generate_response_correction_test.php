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
 * External function tests for generate_response_correction.
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
 * Tests for the mod_playervideo_generate_response_correction web service.
 *
 * A real generation call is never exercised here — the test environment has no AI source
 * configured, mirroring the same choice already made for generate_question_ai_test/
 * generate_di_summary_test.
 *
 * @covers \mod_playervideo\external\generate_response_correction
 */
final class generate_response_correction_test extends \advanced_testcase {
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
     * Creates a real essay question and returns its id.
     *
     * @return int The new question id.
     */
    private function create_essay_question(): int {
        $categoryid = question_service::get_or_create_category($this->context);

        $formdata = (object) [
            'name' => 'Explain photosynthesis',
            'questiontext' => ['text' => 'Explain photosynthesis in your own words.', 'format' => FORMAT_HTML],
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
     * Creates a question interaction + attempt + pending open-question response, and returns
     * the response id.
     *
     * @param int $questionid The question id to reference.
     * @param float $weight The interaction's grading weight.
     * @param string $status Initial response status.
     * @return int The new response id.
     */
    private function create_pending_response(int $questionid, float $weight = 1.0, string $status = 'pending_ai'): int {
        global $DB, $USER;

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
            'userid' => $USER->id,
            'attemptnumber' => 1,
            'status' => 'pendingcorrection',
            'grade' => null,
            'hudretrycharged' => 0,
            'timestart' => $now,
            'timefinish' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $this->instance->id,
            'userid' => $USER->id,
            'attemptid' => $attemptid,
            'interactionid' => $interactionid,
            'questionid' => $questionid,
            'responsetext' => 'Plants use sunlight to make food.',
            'status' => $status,
            'hudrewarded' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_generate_response_correction', $args);
    }

    /**
     * Tests that, with no AI source configured, the call fails with a clear "no AI source"
     * error rather than a raw exception from further down.
     *
     * @return void
     */
    public function test_fails_clearly_with_no_ai_source_configured(): void {
        $questionid = $this->create_essay_question();
        $responseid = $this->create_pending_response($questionid);

        $result = $this->call(['responseid' => $responseid]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_noaisource', $result['exception']->errorcode);
    }

    /**
     * Tests that generating a correction for a non-open-question interaction (multichoice) is
     * rejected before ever reaching the AI-source check.
     *
     * @return void
     */
    public function test_rejects_a_non_open_question_interaction(): void {
        global $DB, $USER;

        $categoryid = question_service::get_or_create_category($this->context);
        $formdata = (object) [
            'name' => 'MC',
            'questiontext' => ['text' => 'Pick one.', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'penalty' => 0,
        ];
        $qtypedata = question_service::build_multichoice_formdata(
            [['text' => 'A', 'correct' => true], ['text' => 'B', 'correct' => false]],
            true
        );
        foreach (get_object_vars($qtypedata) as $field => $value) {
            $formdata->$field = $value;
        }
        $questionid = question_service::create_question('multichoice', $categoryid, $this->context->id, $formdata);
        $responseid = $this->create_pending_response($questionid);
        // A multichoice response never carries responsetext, but the interaction type/qtype
        // check happens before that would matter anyway.
        $DB->set_field('playervideo_responses', 'answerid', null, ['id' => $responseid]);

        $result = $this->call(['responseid' => $responseid]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_notanopenquestion', $result['exception']->errorcode);
    }

    /**
     * Tests that a response already graded by the teacher cannot be regenerated.
     *
     * @return void
     */
    public function test_rejects_an_already_graded_response(): void {
        global $DB;

        $questionid = $this->create_essay_question();
        $responseid = $this->create_pending_response($questionid, 1.0, 'graded');
        $DB->set_field('playervideo_responses', 'teachergrade', 0.5, ['id' => $responseid]);

        $result = $this->call(['responseid' => $responseid]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_responsealreadygraded', $result['exception']->errorcode);
    }

    /**
     * Tests that a student cannot generate a correction suggestion — must fail on the
     * capability check.
     *
     * @return void
     */
    public function test_student_cannot_generate_a_correction(): void {
        $questionid = $this->create_essay_question();
        $responseid = $this->create_pending_response($questionid);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['responseid' => $responseid]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }

    /**
     * Tests parse_response() accepts a well-formed response and clamps an out-of-range score.
     *
     * @return void
     */
    public function test_parse_response_accepts_a_wellformed_response_and_clamps_score(): void {
        $method = new \ReflectionMethod(generate_response_correction::class, 'parse_response');
        $method->setAccessible(true);

        $decoded = $method->invoke(null, '{"score": 0.75, "feedback": "Good answer."}');
        $this->assertSame(0.75, $decoded['score']);
        $this->assertSame('Good answer.', $decoded['feedback']);

        $clamped = $method->invoke(null, '{"score": 1.5, "feedback": ""}');
        $this->assertSame(1.0, $clamped['score']);
    }

    /**
     * Tests parse_response() returns null for a response missing the required score key.
     *
     * @return void
     */
    public function test_parse_response_rejects_missing_score(): void {
        $method = new \ReflectionMethod(generate_response_correction::class, 'parse_response');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, '{"feedback": "no score here"}'));
        $this->assertNull($method->invoke(null, 'not even json'));
    }
}
