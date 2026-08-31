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
 * External function to create a multichoice/truefalse question via the official Question
 * Bank save path, without leaving the timeline editor ("criar aqui").
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playervideo\local\question_service;
use moodle_exception;
use stdClass;

/**
 * Creates a question via question_service::create_question() (save_question(), never a raw
 * INSERT), in the activity's own default category.
 *
 * Answer correctness is expressed as a simple per-answer boolean rather than raw fractions —
 * the fraction math (single: 1.0 on the one correct answer; multiple: 1/N split across every
 * correct answer) is derived here so it always satisfies qtype_multichoice's own sanity check
 * (max fraction == 1 for single, sum of fractions == 1 for multiple) by construction, instead
 * of letting a malformed fraction set reach save_question() and trip its unsupported
 * $result->noticeyesno path (which the base save_question() turns into a raw coding_exception).
 */
class create_question extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'qtype' => new external_value(PARAM_ALPHA, 'multichoice | truefalse'),
            'questiontext' => new external_value(PARAM_RAW, 'Question text'),
            'name' => new external_value(PARAM_TEXT, 'Question name (auto-generated if empty)', VALUE_DEFAULT, ''),
            'single' => new external_value(
                PARAM_BOOL,
                'multichoice only: single vs multiple correct answer(s)',
                VALUE_DEFAULT,
                true
            ),
            'correctanswer' => new external_value(
                PARAM_BOOL,
                'truefalse only: whether True is the correct answer',
                VALUE_DEFAULT,
                true
            ),
            'answers' => new external_multiple_structure(
                new external_single_structure([
                    'text' => new external_value(PARAM_RAW, 'Answer text'),
                    'correct' => new external_value(PARAM_BOOL, 'Whether this answer is correct'),
                ]),
                'multichoice only: the answer options',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Creates the question.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $qtype 'multichoice' | 'truefalse'.
     * @param string $questiontext Question text.
     * @param string $name Question name (auto-generated if empty).
     * @param bool $single multichoice only: single vs multiple correct answer(s).
     * @param bool $correctanswer truefalse only: whether True is the correct answer.
     * @param array $answers multichoice only: the answer options.
     * @return array The created question id.
     */
    public static function execute(
        int $playervideoid,
        string $qtype,
        string $questiontext,
        string $name,
        bool $single,
        bool $correctanswer,
        array $answers
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'qtype' => $qtype,
            'questiontext' => $questiontext,
            'name' => $name,
            'single' => $single,
            'correctanswer' => $correctanswer,
            'answers' => $answers,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:manage', $context);
        require_capability('moodle/question:add', $context);

        if ($params['qtype'] !== 'multichoice' && $params['qtype'] !== 'truefalse') {
            throw new moodle_exception('error_invalidqtype', 'mod_playervideo');
        }
        if (trim($params['questiontext']) === '') {
            throw new moodle_exception('error_questiontextrequired', 'mod_playervideo');
        }

        $categoryid = question_service::get_or_create_category($context);

        $name = trim($params['name']) !== '' ? $params['name'] : shorten_text(strip_tags($params['questiontext']), 60);

        $formdata = new stdClass();
        $formdata->name = $name;
        $formdata->questiontext = ['text' => $params['questiontext'], 'format' => FORMAT_HTML];
        $formdata->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->defaultmark = 1;
        $formdata->penalty = 0;

        if ($params['qtype'] === 'truefalse') {
            $formdata->correctanswer = $params['correctanswer'] ? 1 : 0;
            $formdata->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
            $formdata->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
        } else {
            self::apply_multichoice_answers($formdata, $params['answers'], $params['single']);
        }

        $questionid = question_service::create_question($params['qtype'], $categoryid, $context->id, $formdata);

        return ['questionid' => $questionid];
    }

    /**
     * Builds the answer/fraction/feedback arrays qtype_multichoice::save_question_options()
     * expects, from the plugin's own simpler {text, correct} shape.
     *
     * @param stdClass $formdata Form data being assembled, modified by reference.
     * @param array $answers The {text, correct} answers from the WS parameters.
     * @param bool $single Single vs multiple correct answer(s).
     * @return void
     */
    private static function apply_multichoice_answers(stdClass $formdata, array $answers, bool $single): void {
        $nonempty = array_values(array_filter($answers, static fn(array $a): bool => trim($a['text']) !== ''));
        if (count($nonempty) < 2) {
            throw new moodle_exception('error_notenoughanswers', 'mod_playervideo');
        }

        $correctcount = count(array_filter($nonempty, static fn(array $a): bool => $a['correct']));
        if ($correctcount === 0) {
            throw new moodle_exception('error_nocorrectanswer', 'mod_playervideo');
        }
        if ($single && $correctcount > 1) {
            throw new moodle_exception('error_onlyonecorrectanswer', 'mod_playervideo');
        }

        $correctfraction = 1 / $correctcount;

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
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionid' => new external_value(PARAM_INT, 'Created question id'),
        ]);
    }
}
