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
 * Question Bank integration for PlayerVideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use coding_exception;
use context;
use stdClass;

/**
 * Reads and writes questions through the native Question Bank, never a parallel schema.
 *
 * Both entry paths ("pull from bank" and "create here", including AI generation) converge on
 * this class: writing always goes through the official {@see \question_bank::get_qtype()}
 * {@see save_question()} path, and reading in-game uses direct SQL against the Question Bank
 * core tables ("Blind JSON": text and options only, the correct answer is never sent to the
 * client), mirroring the pattern already in production at
 * {@see \mod_playerpuzzle\local\engine\question_fetcher}.
 */
class question_service {
    /**
     * Returns the id of the default question category for a context, creating it if needed.
     *
     * The optional create-if-not-exists parameter of question_get_default_category() only
     * exists on Moodle 5.x — on 4.5 it is a no-op (the function only takes one argument there),
     * so the create path is replicated here by hand, mirroring core's own 5.x implementation,
     * to behave identically on every supported branch (see the plugin's compatibility range).
     *
     * @param context $context The activity module context; only CONTEXT_MODULE is supported,
     *      matching how this plugin always creates questions in the category of the activity
     *      instance itself.
     * @return int The question category id.
     * @throws coding_exception If $context is not a module context.
     */
    public static function get_or_create_category(context $context): int {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            throw new coding_exception('get_or_create_category() requires a CONTEXT_MODULE context.');
        }

        $category = question_get_default_category($context->id);

        if ($category !== false) {
            return (int) $category->id;
        }

        $topcategory = question_get_top_category($context->id, true);
        $contextname = $context->get_context_name(false, true);

        $newcategory = new stdClass();
        $newcategory->name = shorten_text(get_string('defaultfor', 'question', $contextname), 1333);
        $newcategory->info = get_string('defaultinfofor', 'question', $contextname);
        $newcategory->contextid = $context->id;
        $newcategory->parent = $topcategory->id;
        $newcategory->sortorder = 999;
        $newcategory->stamp = make_unique_id_code();
        $newcategory->id = $DB->insert_record('question_categories', $newcategory);

        return (int) $newcategory->id;
    }

    /**
     * Creates a question in the given category via the official Question Bank save path.
     *
     * The caller (the "create here" form or the AI generator) is responsible for assembling
     * $formdata with the fields the target qtype expects (name, questiontext, defaultmark, and
     * the qtype-specific fields such as answer/fraction/feedback for multichoice) — this method
     * only wires the category and delegates to save_question(), never a raw INSERT.
     *
     * @param string $qtype The question type, e.g. 'multichoice' or 'truefalse'.
     * @param int $categoryid The destination question category id.
     * @param int $categorycontextid The context id that owns that category.
     * @param stdClass $formdata Qtype-specific form data, as expected by save_question().
     * @return int The id of the newly created question.
     */
    public static function create_question(
        string $qtype,
        int $categoryid,
        int $categorycontextid,
        stdClass $formdata
    ): int {
        $formdata->category = $categoryid . ',' . $categorycontextid;

        $question = new stdClass();
        $question->qtype = $qtype;

        $qtypeobj = \question_bank::get_qtype($qtype);
        $savedquestion = $qtypeobj->save_question($question, $formdata);

        return (int) $savedquestion->id;
    }

    /**
     * Returns a question formatted for the frontend, without revealing the correct answer.
     *
     * @param int $questionid The question id.
     * @param context $context The context for formatting the HTML text.
     * @return array|null The formatted question, or null if it no longer exists.
     */
    public static function get_question_for_frontend(int $questionid, context $context): ?array {
        global $DB;

        $question = $DB->get_record(
            'question',
            ['id' => $questionid],
            'id, qtype, questiontext, questiontextformat'
        );

        if (!$question) {
            return null;
        }

        $options = [];

        if ($question->qtype === 'multichoice' || $question->qtype === 'truefalse') {
            $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');
            $answers = array_values($answers);
            shuffle($answers);

            foreach ($answers as $answer) {
                $options[] = [
                    'id' => (int) $answer->id,
                    'text' => format_text($answer->answer, $answer->answerformat, ['context' => $context]),
                ];
            }
        }

        return [
            'id' => (int) $question->id,
            'type' => $question->qtype,
            'text' => format_text($question->questiontext, $question->questiontextformat, ['context' => $context]),
            'options' => $options,
        ];
    }

    /**
     * Checks whether the given answer is correct for the question, on the server side.
     *
     * @param int $questionid The question id.
     * @param int $answerid The answer id chosen by the student.
     * @return bool True if the answer is fully correct (fraction >= 1.0).
     */
    public static function is_answer_correct(int $questionid, int $answerid): bool {
        global $DB;

        $fraction = $DB->get_field('question_answers', 'fraction', [
            'id' => $answerid,
            'question' => $questionid,
        ]);

        return $fraction !== false && (float) $fraction >= 1.0;
    }

    /**
     * Returns the qtype of a question, or null if it no longer exists.
     *
     * @param int $questionid The question id.
     * @return string|null
     */
    public static function get_question_type(int $questionid): ?string {
        global $DB;

        $qtype = $DB->get_field('question', 'qtype', ['id' => $questionid]);

        return $qtype !== false ? (string) $qtype : null;
    }

    /**
     * Checks whether any of the given question ids are referenced by any PlayerVideo instance.
     *
     * Called from the {@see \playervideo_questions_in_use()} plugin callback in lib.php, which
     * core discovers via get_plugins_with_function('questions_in_use') and invokes before
     * letting a teacher delete a question from the bank — this is what protects
     * playervideo_responses from ever pointing at a deleted question, since the plugin does not
     * use the full Question Usage API.
     *
     * @param int[] $questionids Question ids to check.
     * @return bool True if at least one of them is referenced by this plugin.
     */
    public static function has_questions_in_use(array $questionids): bool {
        global $DB;

        if (empty($questionids)) {
            return false;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($questionids);

        if ($DB->record_exists_select('playervideo_interactions', "questionid $insql", $inparams)) {
            return true;
        }

        return $DB->record_exists_select('playervideo_responses', "questionid $insql", $inparams);
    }
}
