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
 * External function to generate a question by AI for one timestamp of the timeline.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playervideo\local\ai_service;
use mod_playervideo\local\question_service;
use moodle_exception;

/**
 * Generates a single question for a given point in the video via the shared AI routing
 * ({@see ai_service}), then writes it through the official Question Bank save path
 * ({@see question_service::create_question()}) — the same path "create here" and the batch
 * generator use, never a raw INSERT. The AI response is untrusted output: its JSON shape is
 * validated before any of it is used to build a question, and the multichoice answer/fraction
 * derivation goes through {@see question_service::build_multichoice_formdata()}, so a malformed
 * AI answer set fails with the same clear errors a human typing bad input would get.
 *
 * The question is written but never wired into the timeline as a real interaction — the
 * professor still has to review the preview this returns and call save_interaction() to accept
 * it (same "generated but not yet a real interaction" contract as generate_questions_batch).
 */
class generate_question_ai extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'timestamp' => new external_value(PARAM_INT, 'Video timestamp, in seconds'),
            'context' => new external_value(
                PARAM_TEXT,
                'What is happening in the video around this timestamp, to ground the question',
                VALUE_DEFAULT,
                ''
            ),
            'qtype' => new external_value(PARAM_ALPHA, 'multichoice | essay', VALUE_DEFAULT, 'multichoice'),
        ]);
    }

    /**
     * Generates the question.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param int $timestamp Video timestamp, in seconds.
     * @param string $videocontext What is happening in the video around this timestamp.
     * @param string $qtype 'multichoice' | 'essay'.
     * @return array The created question id and a preview for the teacher's review.
     */
    public static function execute(int $playervideoid, int $timestamp, string $videocontext, string $qtype): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'timestamp' => $timestamp,
            'context' => $videocontext,
            'qtype' => $qtype,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $modulecontext = context_module::instance($cm->id);
        self::validate_context($modulecontext);
        require_capability('mod/playervideo:manage', $modulecontext);
        require_capability('moodle/question:add', $modulecontext);

        if ($params['qtype'] !== 'multichoice' && $params['qtype'] !== 'essay') {
            throw new moodle_exception('error_invalidqtype', 'mod_playervideo');
        }

        if (!ai_service::has_ai_source($modulecontext)) {
            throw new moodle_exception('error_noaisource', 'mod_playervideo');
        }

        $prompt = self::build_prompt($params['qtype'], $params['timestamp'], $params['context']);
        $description = get_string('aiusage_question', 'mod_playervideo');
        $result = ai_service::generate($prompt, $description, $modulecontext);

        if (empty($result['success'])) {
            throw new moodle_exception('error_aigenerate', 'mod_playervideo');
        }

        $decoded = self::parse_response((string) ($result['data'] ?? ''));
        if ($decoded === null) {
            throw new moodle_exception('error_aiinvalidresponse', 'mod_playervideo');
        }

        $categoryid = question_service::get_or_create_category($modulecontext);
        $questionid = self::save_generated_question($params['qtype'], $decoded, $categoryid, $modulecontext->id);

        $preview = question_service::get_question_for_review($questionid, $modulecontext);

        return [
            'questionid' => $questionid,
            'questiontext' => $preview['text'] ?? '',
            'answers' => array_map(
                static fn(array $a): array => ['text' => $a['text'], 'correct' => $a['correct']],
                $preview['options'] ?? []
            ),
        ];
    }

    /**
     * Builds the AI prompt for one question, grounded on the optional video context text.
     *
     * @param string $qtype 'multichoice' | 'essay'.
     * @param int $timestamp Video timestamp, in seconds — included for the AI's own reference,
     *      never used as ground truth for anything the server later validates.
     * @param string $videocontext What is happening in the video around this timestamp.
     * @return string The prompt text.
     */
    private static function build_prompt(string $qtype, int $timestamp, string $videocontext): string {
        $parts = [
            'You are an instructional designer creating one comprehension question for a point '
                . 'in an educational video (at approximately ' . $timestamp . ' seconds in).',
        ];

        if (trim($videocontext) !== '') {
            $parts[] = 'What is happening in the video around this moment: "' . $videocontext . '"';
        } else {
            $parts[] = 'No specific context was given for this moment — write a general '
                . 'comprehension-check question suitable for a video lesson.';
        }

        if ($qtype === 'essay') {
            $parts[] = 'Write ONE open-ended question that requires a short written answer '
                . '(a sentence or two), not a yes/no or single-word answer.';
            $parts[] = 'Reply ONLY with a valid JSON object in this exact format, no code fences: '
                . '{"questiontext": "..."}';
        } else {
            $parts[] = 'Write ONE multiple-choice question with exactly 4 answer options, only '
                . 'one of them correct. The distractors should be plausible, not obviously wrong.';
            $parts[] = 'Reply ONLY with a valid JSON object in this exact format, no code fences: '
                . '{"questiontext": "...", "answers": [{"text": "...", "correct": true}, '
                . '{"text": "...", "correct": false}]}';
        }

        return implode("\n", $parts);
    }

    /**
     * Parses and structurally validates the AI's JSON response.
     *
     * The AI response is untrusted output — this only checks the shape (required keys present,
     * expected types); the actual answer-set validity (at least one correct, single-correct
     * constraint) is enforced downstream by
     * {@see \mod_playervideo\local\question_service::build_multichoice_formdata()}.
     *
     * @param string $responsetext Raw text returned by the AI provider.
     * @return array|null Decoded ['questiontext' => string, 'answers' => array] (answers empty
     *      for essay), or null if the response does not match the expected shape.
     */
    private static function parse_response(string $responsetext): ?array {
        $cleaned = preg_replace('/^\x60\x60\x60(?:json)?\s*/im', '', $responsetext);
        $cleaned = preg_replace('/\x60\x60\x60\s*$/m', '', $cleaned);
        $cleaned = trim((string) $cleaned);

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded) || !isset($decoded['questiontext']) || trim((string) $decoded['questiontext']) === '') {
            return null;
        }

        $answers = [];
        if (isset($decoded['answers']) && is_array($decoded['answers'])) {
            foreach ($decoded['answers'] as $answer) {
                if (!is_array($answer) || !isset($answer['text'])) {
                    continue;
                }
                $answers[] = [
                    'text' => (string) $answer['text'],
                    'correct' => !empty($answer['correct']),
                ];
            }
        }

        return [
            'questiontext' => (string) $decoded['questiontext'],
            'answers' => $answers,
        ];
    }

    /**
     * Writes the AI-generated question through the official save path.
     *
     * @param string $qtype 'multichoice' | 'essay'.
     * @param array $decoded Parsed AI response, see {@see parse_response()}.
     * @param int $categoryid Destination question category id.
     * @param int $categorycontextid Context id that owns that category.
     * @return int The created question id.
     */
    private static function save_generated_question(
        string $qtype,
        array $decoded,
        int $categoryid,
        int $categorycontextid
    ): int {
        $formdata = new \stdClass();
        $formdata->name = shorten_text(strip_tags($decoded['questiontext']), 60);
        $formdata->questiontext = ['text' => $decoded['questiontext'], 'format' => FORMAT_HTML];
        $formdata->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->defaultmark = 1;
        $formdata->penalty = 0;

        if ($qtype === 'essay') {
            $qtypedata = question_service::build_essay_formdata();
        } else {
            $qtypedata = question_service::build_multichoice_formdata($decoded['answers'], true);
        }
        foreach (get_object_vars($qtypedata) as $field => $value) {
            $formdata->$field = $value;
        }

        return question_service::create_question($qtype, $categoryid, $categorycontextid, $formdata);
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionid' => new external_value(PARAM_INT, 'Created question id'),
            'questiontext' => new external_value(PARAM_RAW, 'Formatted question text, for the review preview'),
            'answers' => new \core_external\external_multiple_structure(
                new external_single_structure([
                    'text' => new external_value(PARAM_RAW, 'Answer text'),
                    'correct' => new external_value(PARAM_BOOL, 'Whether this answer is correct'),
                ]),
                'Answer options, empty for essay questions'
            ),
        ]);
    }
}
