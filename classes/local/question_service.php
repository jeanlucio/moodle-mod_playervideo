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
use context_course;
use context_module;
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
        global $CFG, $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            throw new coding_exception('get_or_create_category() requires a CONTEXT_MODULE context.');
        }

        // Both question_get_default_category() and question_get_top_category() are legacy
        // global functions in lib/questionlib.php, not autoloaded — unlike a namespaced class, a
        // real request never loads them unless something else in the same path already did
        // (e.g. the question bank UI). A PHPUnit run can mask this: the core_question test
        // generator loads this file as a side effect, which a live AJAX call never does.
        require_once($CFG->libdir . '/questionlib.php');

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
     * Builds the answer/fraction/feedback arrays qtype_multichoice::save_question_options()
     * expects, from the plugin's own simpler {text, correct} shape.
     *
     * Shared by the "create here" form and the AI generators (point-to-point and batch) so the
     * fraction derivation — the one part of this that must satisfy save_question()'s own sanity
     * check (max fraction == 1 for single, sum of fractions == 1 for multiple) — is written once.
     *
     * @param array $answers The {text, correct} answers, from a WS parameter or an AI response.
     * @param bool $single Single vs multiple correct answer(s).
     * @return stdClass Partial form data: answer, fraction, feedback and the qtype's other
     *      required multichoice-specific fields.
     * @throws \moodle_exception If fewer than two non-empty answers are given, if none is marked
     *      correct, or if more than one is marked correct while $single is true.
     */
    public static function build_multichoice_formdata(array $answers, bool $single): stdClass {
        $nonempty = array_values(array_filter($answers, static fn(array $a): bool => trim($a['text']) !== ''));
        if (count($nonempty) < 2) {
            throw new \moodle_exception('error_notenoughanswers', 'mod_playervideo');
        }

        $correctcount = count(array_filter($nonempty, static fn(array $a): bool => $a['correct']));
        if ($correctcount === 0) {
            throw new \moodle_exception('error_nocorrectanswer', 'mod_playervideo');
        }
        if ($single && $correctcount > 1) {
            throw new \moodle_exception('error_onlyonecorrectanswer', 'mod_playervideo');
        }

        $correctfraction = 1 / $correctcount;

        $formdata = new stdClass();
        $formdata->answer = [];
        $formdata->fraction = [];
        $formdata->feedback = [];
        foreach ($nonempty as $answer) {
            $formdata->answer[] = ['text' => $answer['text'], 'format' => FORMAT_HTML];
            $formdata->fraction[] = $answer['correct'] ? $correctfraction : 0.0;
            $formdata->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
        }

        $formdata->single = $single ? 1 : 0;
        $formdata->shuffleanswers = 1;
        $formdata->answernumbering = 'abc';
        $formdata->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->showstandardinstruction = 0;

        return $formdata;
    }

    /**
     * Builds the minimal form data qtype_essay::save_question_options() expects.
     *
     * Only the AI generators (point-to-point and batch) create essay questions today — the
     * manual "create here" form only supports multichoice/truefalse (an existing question can
     * still be pulled from the bank regardless of qtype via "search_questions"). Field values
     * mirror core's own qtype_essay test helper (question/type/essay/tests/helper.php): a plain
     * editor response box, no attachments, no word limit, empty grader guidance/template.
     *
     * @return stdClass Partial form data for an essay question.
     */
    public static function build_essay_formdata(): stdClass {
        $formdata = new stdClass();
        $formdata->responseformat = 'editor';
        $formdata->responserequired = 1;
        $formdata->responsefieldlines = 10;
        $formdata->minwordlimit = null;
        $formdata->maxwordlimit = null;
        $formdata->attachments = 0;
        $formdata->attachmentsrequired = 0;
        $formdata->graderinfo = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];

        return $formdata;
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
     * Returns the formatted question text for several questions at once, keyed by id.
     *
     * Batch counterpart of get_question_for_frontend() for callers that only need the text
     * (e.g. a timeline listing) — avoids one query per question in a loop.
     *
     * @param int[] $questionids Question ids.
     * @param context $context The context for formatting the HTML text.
     * @return array<int, string> Question id => formatted text; missing ids are simply absent.
     */
    public static function get_question_texts(array $questionids, context $context): array {
        global $DB;

        $questionids = array_values(array_unique(array_map('intval', $questionids)));
        if (empty($questionids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($questionids);
        $records = $DB->get_records_select(
            'question',
            "id $insql",
            $inparams,
            '',
            'id, questiontext, questiontextformat'
        );

        $texts = [];
        foreach ($records as $record) {
            $texts[(int) $record->id] = format_text(
                $record->questiontext,
                $record->questiontextformat,
                ['context' => $context]
            );
        }

        return $texts;
    }

    /**
     * Returns a question formatted for the read-only review screen, revealing correctness and
     * feedback per answer.
     *
     * Distinct from {@see get_question_for_frontend()} on purpose: that method is used while an
     * attempt is still live and must never reveal the correct answer ("Blind JSON"), while this
     * one is only ever called for an attempt that has already finished, where showing the correct
     * answer and its feedback is exactly the point (mod_quiz's own "Review attempt").
     *
     * @param int $questionid The question id.
     * @param context $context The context for formatting the HTML text.
     * @return array|null The formatted question, or null if it no longer exists.
     */
    public static function get_question_for_review(int $questionid, context $context): ?array {
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

            foreach ($answers as $answer) {
                $options[] = [
                    'id' => (int) $answer->id,
                    'text' => format_text($answer->answer, $answer->answerformat, ['context' => $context]),
                    'correct' => (float) $answer->fraction >= 1.0,
                    'feedback' => format_text($answer->feedback, $answer->feedbackformat, ['context' => $context]),
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
     * Returns the ids of question categories' contexts the current user may reuse a question
     * from, for a "pull from bank" picker scoped to the given course module: the course context,
     * its parents, and every sibling activity's module context in the same course, filtered to
     * those where the user actually holds moodle/question:useall or moodle/question:usemine —
     * mirroring the category resolution already used by mod_playerpuzzle's mod_form.php.
     *
     * Shared by {@see \mod_playervideo\external\search_questions} (to build the picker's own
     * results) and {@see question_belongs_to_reusable_category()} (to reject, server-side, a
     * questionid whose category the caller cannot reach — closing the gap a raw web service call
     * could otherwise use to bypass the picker's own filtering).
     *
     * @param stdClass $cm Course module record for the PlayerVideo instance.
     * @return int[] Valid context ids, empty if the user may not reuse any question here.
     */
    public static function get_reusable_question_context_ids(stdClass $cm): array {
        $coursecontext = context_course::instance($cm->course);
        $contextstocheck = [];
        foreach ($coursecontext->get_parent_contexts(true) as $ctx) {
            $contextstocheck[$ctx->id] = $ctx;
        }

        $modinfo = get_fast_modinfo($cm->course);
        foreach ($modinfo->cms as $othercm) {
            $othercontext = context_module::instance($othercm->id);
            $contextstocheck[$othercontext->id] = $othercontext;
        }

        $validcontextids = [];
        foreach ($contextstocheck as $ctx) {
            if (has_capability('moodle/question:useall', $ctx) || has_capability('moodle/question:usemine', $ctx)) {
                $validcontextids[] = $ctx->id;
            }
        }

        return $validcontextids;
    }

    /**
     * Checks whether a question sits in a category the current user is allowed to reuse it from,
     * for the given course module.
     *
     * Existing (record_exists('question', ...)) is not enough on its own: any question id on the
     * whole site would pass that check, including one from a private category in a course the
     * caller has no access to. This is the server-side re-validation of the same rule the "pull
     * from bank" picker already applies to what it *offers* — a raw call to the web service that
     * persists the choice must enforce it too, not just the picker that suggests it.
     *
     * @param int $questionid The question id to check.
     * @param stdClass $cm Course module record for the PlayerVideo instance.
     * @return bool True if the question exists in a category among the reusable contexts.
     */
    public static function question_belongs_to_reusable_category(int $questionid, stdClass $cm): bool {
        global $DB;

        $contextids = self::get_reusable_question_context_ids($cm);
        if (empty($contextids)) {
            return false;
        }

        [$contextinsql, $contextparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $contextparams['questionid'] = $questionid;

        $sql = "SELECT 1
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id AND qv.status = 'ready'
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.id = :questionid
                   AND qc.contextid $contextinsql";

        return $DB->record_exists_sql($sql, $contextparams);
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
