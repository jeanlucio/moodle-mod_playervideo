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
 * Unit tests for the Question Bank integration service.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for question_service.
 *
 * @covers \mod_playervideo\local\question_service
 */
final class question_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creates a single-correct-answer multichoice question ("One" is correct, fraction
     * 1.0; "Two"/"Three"/"Four" are wrong) in the given category.
     *
     * @param int $categoryid Question category id.
     * @return \stdClass The created question record.
     */
    private function make_multichoice_question(int $categoryid): \stdClass {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        return $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $categoryid]);
    }

    /**
     * Finds the id of the answer with the given text for a question.
     *
     * @param int $questionid Question id.
     * @param string $text Exact answer text to look for.
     * @return int The matching answer id.
     */
    private function find_answer_id(int $questionid, string $text): int {
        global $DB;

        foreach ($DB->get_records('question_answers', ['question' => $questionid]) as $answer) {
            if ($answer->answer === $text) {
                return (int) $answer->id;
            }
        }

        $this->fail("No answer with text '$text' found for question $questionid.");
    }

    /**
     * Tests that the frontend payload never leaks a correctness signal alongside an option —
     * the Blind JSON contract.
     *
     * @return void
     */
    public function test_get_question_for_frontend_never_leaks_correctness(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $this->make_multichoice_question($category->id);

        $formatted = question_service::get_question_for_frontend((int) $question->id, \context_system::instance());

        $this->assertNotNull($formatted);
        $this->assertSame('multichoice', $formatted['type']);
        $this->assertNotEmpty($formatted['options']);
        foreach ($formatted['options'] as $option) {
            $this->assertSame(['id', 'text'], array_keys($option));
        }
    }

    /**
     * Tests that a note-only question id (i.e. one that does not exist) returns null instead of
     * throwing, since the caller renders notes without ever hitting this method.
     *
     * @return void
     */
    public function test_get_question_for_frontend_returns_null_for_missing_question(): void {
        $this->assertNull(question_service::get_question_for_frontend(0, \context_system::instance()));
    }

    /**
     * Tests that is_answer_correct returns true only for the answer with fraction >= 1.0.
     *
     * @return void
     */
    public function test_is_answer_correct(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $this->make_multichoice_question($category->id);

        $correctid = $this->find_answer_id((int) $question->id, 'One');
        $wrongid = $this->find_answer_id((int) $question->id, 'Two');

        $this->assertTrue(question_service::is_answer_correct((int) $question->id, $correctid));
        $this->assertFalse(question_service::is_answer_correct((int) $question->id, $wrongid));
    }

    /**
     * Tests get_question_type() reports the qtype, and null for an unknown id.
     *
     * @return void
     */
    public function test_get_question_type(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $mc = $this->make_multichoice_question($category->id);
        $tf = $questiongenerator->create_question('truefalse', 'true', ['category' => $category->id]);

        $this->assertSame('multichoice', question_service::get_question_type((int) $mc->id));
        $this->assertSame('truefalse', question_service::get_question_type((int) $tf->id));
        $this->assertNull(question_service::get_question_type(0));
    }

    /**
     * Creates a course and a generic activity, returning that activity's module context —
     * question_get_default_category() only supports CONTEXT_MODULE, and this plugin always
     * creates its questions in the category of the activity instance itself.
     *
     * @return \context_module
     */
    private function create_module_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        return \context_module::instance($page->cmid);
    }

    /**
     * Tests that get_or_create_category is idempotent: calling it twice for the same context
     * returns the same category id, never creating a duplicate default category.
     *
     * @return void
     */
    public function test_get_or_create_category_is_idempotent(): void {
        $context = $this->create_module_context();

        $first = question_service::get_or_create_category($context);
        $second = question_service::get_or_create_category($context);

        $this->assertSame($first, $second);
    }

    /**
     * Tests that get_or_create_category() refuses a non-module context (e.g. the system
     * context) instead of silently returning a category id of 0.
     *
     * @return void
     */
    public function test_get_or_create_category_rejects_non_module_context(): void {
        $this->expectException(\coding_exception::class);

        question_service::get_or_create_category(\context_system::instance());
    }

    /**
     * Tests that create_question() writes a real question in the Question Bank, in the
     * requested category, via the official save_question() path — never a raw INSERT.
     *
     * @return void
     */
    public function test_create_question_writes_a_real_truefalse_question(): void {
        global $DB;

        $context = $this->create_module_context();
        $categoryid = question_service::get_or_create_category($context);

        $formdata = (object) [
            'name' => 'Is the sky blue?',
            'questiontext' => ['text' => 'Is the sky blue?', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'penalty' => 0,
            'correctanswer' => 1,
            'feedbacktrue' => ['text' => 'Correct!', 'format' => FORMAT_HTML],
            'feedbackfalse' => ['text' => 'Not quite.', 'format' => FORMAT_HTML],
        ];

        $questionid = question_service::create_question('truefalse', $categoryid, $context->id, $formdata);

        $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
        $this->assertSame('truefalse', $question->qtype);
        $this->assertSame('Is the sky blue?', $question->name);
        $this->assertTrue(question_service::is_answer_correct(
            $questionid,
            $this->find_answer_id($questionid, get_string('true', 'qtype_truefalse'))
        ));

        $bankentry = $DB->get_record_sql(
            'SELECT qbe.questioncategoryid
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              WHERE qv.questionid = :questionid',
            ['questionid' => $questionid],
            MUST_EXIST
        );
        $this->assertEquals($categoryid, $bankentry->questioncategoryid);
    }

    /**
     * Tests has_questions_in_use(): false with no reference at all, true once the question is
     * referenced by an interaction, and false again for an unrelated question id.
     *
     * @return void
     */
    public function test_has_questions_in_use(): void {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $referenced = $this->make_multichoice_question($category->id);
        $unrelated = $this->make_multichoice_question($category->id);

        $this->assertFalse(question_service::has_questions_in_use([(int) $referenced->id]));

        $now = time();
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => 1,
            'timestamp' => 12.5,
            'type' => 'question',
            'weight' => 1,
            'questionid' => $referenced->id,
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $this->assertTrue(question_service::has_questions_in_use([(int) $referenced->id]));
        $this->assertFalse(question_service::has_questions_in_use([(int) $unrelated->id]));
    }
}
