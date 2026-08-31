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
 * External function tests for create_question.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_create_question web service.
 *
 * @covers \mod_playervideo\external\create_question
 */
final class create_question_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Instance used by every test. */
    private \stdClass $instance;

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
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_create_question', array_merge([
            'name' => '',
            'single' => true,
            'correctanswer' => true,
            'answers' => [],
        ], $args));
    }

    /**
     * Tests that a single-answer multichoice question is created with the correct fraction
     * split (100% on the one correct answer, 0% on the rest).
     *
     * @return void
     */
    public function test_creates_a_single_answer_multichoice_question(): void {
        global $DB;

        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'qtype' => 'multichoice',
            'questiontext' => 'What is the capital of France?',
            'single' => true,
            'answers' => [
                ['text' => 'Paris', 'correct' => true],
                ['text' => 'Berlin', 'correct' => false],
                ['text' => 'Madrid', 'correct' => false],
            ],
        ]);

        $this->assertFalse($result['error']);
        $questionid = $result['data']['questionid'];

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('multichoice', $question->qtype);

        $answers = $DB->get_records('question_answers', ['question' => $questionid]);
        $paris = current(array_filter($answers, static fn($a) => $a->answer === 'Paris'));
        $this->assertSame(1.0, (float) $paris->fraction);
    }

    /**
     * Tests that a multiple-correct-answer multichoice question splits the fraction evenly
     * across every correct answer.
     *
     * @return void
     */
    public function test_creates_a_multi_answer_multichoice_question(): void {
        global $DB;

        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'qtype' => 'multichoice',
            'questiontext' => 'Which are primary colours?',
            'single' => false,
            'answers' => [
                ['text' => 'Red', 'correct' => true],
                ['text' => 'Green', 'correct' => false],
                ['text' => 'Blue', 'correct' => true],
            ],
        ]);

        $this->assertFalse($result['error']);
        $questionid = $result['data']['questionid'];

        $answers = $DB->get_records('question_answers', ['question' => $questionid]);
        $red = current(array_filter($answers, static fn($a) => $a->answer === 'Red'));
        $blue = current(array_filter($answers, static fn($a) => $a->answer === 'Blue'));
        $green = current(array_filter($answers, static fn($a) => $a->answer === 'Green'));
        $this->assertEqualsWithDelta(0.5, (float) $red->fraction, 0.001);
        $this->assertEqualsWithDelta(0.5, (float) $blue->fraction, 0.001);
        $this->assertSame(0.0, (float) $green->fraction);
    }

    /**
     * Tests that a truefalse question is created with the correct answer flag.
     *
     * @return void
     */
    public function test_creates_a_truefalse_question(): void {
        global $DB;

        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'qtype' => 'truefalse',
            'questiontext' => 'The sky is blue.',
            'correctanswer' => true,
        ]);

        $this->assertFalse($result['error']);
        $questionid = $result['data']['questionid'];

        $answers = $DB->get_records('question_answers', ['question' => $questionid]);
        $trueanswer = current(array_filter($answers, static fn($a) => $a->answer === get_string('true', 'qtype_truefalse')));
        $this->assertSame(1.0, (float) $trueanswer->fraction);
    }

    /**
     * Tests that fewer than two non-empty answers is rejected.
     *
     * @return void
     */
    public function test_not_enough_answers_is_rejected(): void {
        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'qtype' => 'multichoice',
            'questiontext' => 'Q?',
            'answers' => [['text' => 'Only one', 'correct' => true]],
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_notenoughanswers', $result['exception']->errorcode);
    }

    /**
     * Tests that no answer marked correct is rejected.
     *
     * @return void
     */
    public function test_no_correct_answer_is_rejected(): void {
        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'qtype' => 'multichoice',
            'questiontext' => 'Q?',
            'answers' => [
                ['text' => 'A', 'correct' => false],
                ['text' => 'B', 'correct' => false],
            ],
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_nocorrectanswer', $result['exception']->errorcode);
    }

    /**
     * Tests that more than one correct answer is rejected when single is true.
     *
     * @return void
     */
    public function test_more_than_one_correct_answer_rejected_when_single(): void {
        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'qtype' => 'multichoice',
            'questiontext' => 'Q?',
            'single' => true,
            'answers' => [
                ['text' => 'A', 'correct' => true],
                ['text' => 'B', 'correct' => true],
            ],
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_onlyonecorrectanswer', $result['exception']->errorcode);
    }

    /**
     * Tests that an empty question text is rejected.
     *
     * @return void
     */
    public function test_empty_questiontext_is_rejected(): void {
        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'qtype' => 'truefalse',
            'questiontext' => '   ',
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_questiontextrequired', $result['exception']->errorcode);
    }
}
