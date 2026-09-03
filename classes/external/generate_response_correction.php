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
 * External function to generate an AI grading suggestion for an open-question response.
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
 * Suggests a grade + feedback for a student's open-question response, via AI. Never touches
 * `teachergrade`/`teacherfeedback` — only `aigrade`/`aifeedback`, always leaving the response in
 * `pending_review` for a teacher to approve or edit via {@see review_response} (see the plugin
 * SCOPE, "Correção assistida por IA de questões abertas: humano sempre no loop").
 *
 * The AI is asked for a 0.0-1.0 completeness score, never the question's own weight — the weight
 * is an internal grading detail of this plugin, not something the prompt needs to reason about;
 * this method scales the score to the interaction's weight itself before storing it.
 */
class generate_response_correction extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'responseid' => new external_value(PARAM_INT, 'playervideo_responses id'),
        ]);
    }

    /**
     * Generates the AI grading suggestion.
     *
     * @param int $responseid playervideo_responses id.
     * @return array The AI-suggested grade (scaled to the question's weight) and feedback.
     */
    public static function execute(int $responseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['responseid' => $responseid]);

        $response = $DB->get_record('playervideo_responses', ['id' => $params['responseid']], '*', MUST_EXIST);
        $interaction = $DB->get_record('playervideo_interactions', ['id' => $response->interactionid], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $response->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:reviewresponses', $context);

        if ($interaction->type !== 'question' || $interaction->questionid === null) {
            throw new moodle_exception('error_invalidinteractiontype', 'mod_playervideo');
        }
        if ($response->status === 'graded') {
            throw new moodle_exception('error_responsealreadygraded', 'mod_playervideo');
        }

        $question = question_service::get_question_for_review((int) $interaction->questionid, $context);
        if ($question === null || $question['type'] !== 'essay') {
            throw new moodle_exception('error_notanopenquestion', 'mod_playervideo');
        }

        if (!ai_service::has_ai_source($context)) {
            throw new moodle_exception('error_noaisource', 'mod_playervideo');
        }

        $prompt = self::build_prompt($question['text'], (string) $response->responsetext);
        $description = get_string('aiusage_correction', 'mod_playervideo');
        $result = ai_service::generate($prompt, $description, $context);

        if (empty($result['success'])) {
            throw new moodle_exception('error_aigenerate', 'mod_playervideo');
        }

        $parsed = self::parse_response((string) ($result['data'] ?? ''));
        if ($parsed === null) {
            throw new moodle_exception('error_aiinvalidresponse', 'mod_playervideo');
        }

        $weight = (float) $interaction->weight;
        $aigrade = $parsed['score'] * $weight;

        $DB->update_record('playervideo_responses', (object) [
            'id' => $response->id,
            'aigrade' => $aigrade,
            'aifeedback' => $parsed['feedback'],
            'status' => 'pending_review',
            'timemodified' => time(),
        ]);

        return [
            'responseid' => (int) $response->id,
            'aigrade' => $aigrade,
            'aifeedback' => $parsed['feedback'],
            'maxgrade' => $weight,
        ];
    }

    /**
     * Builds the grading-suggestion prompt.
     *
     * @param string $questiontext Formatted question text.
     * @param string $responsetext The student's free-text answer.
     * @return string The prompt text.
     */
    private static function build_prompt(string $questiontext, string $responsetext): string {
        return implode("\n", [
            'You are suggesting a grade for a student\'s open-ended answer to a question from an '
                . 'educational video, for a teacher to review — you are not the final grader.',
            'Score how completely and correctly the answer addresses the question, from 0.0 (no '
                . 'relevant content) to 1.0 (fully correct and complete).',
            'Reply ONLY with a valid JSON object in this exact format, no code fences: '
                . '{"score": <0.0-1.0>, "feedback": "..."} — "feedback" is a short comment '
                . 'addressed directly to the student, explaining the score.',
            '--- QUESTION ---',
            strip_tags($questiontext),
            '--- STUDENT ANSWER ---',
            $responsetext,
        ]);
    }

    /**
     * Parses and structurally validates the AI's JSON response.
     *
     * @param string $responsetext Raw text returned by the AI provider.
     * @return array|null {score: float, feedback: string}, or null if the response is malformed.
     */
    private static function parse_response(string $responsetext): ?array {
        $cleaned = preg_replace('/^\x60\x60\x60(?:json)?\s*/im', '', $responsetext);
        $cleaned = preg_replace('/\x60\x60\x60\s*$/m', '', $cleaned);
        $cleaned = trim((string) $cleaned);

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded) || !isset($decoded['score']) || !is_numeric($decoded['score'])) {
            return null;
        }

        return [
            'score' => max(0.0, min(1.0, (float) $decoded['score'])),
            'feedback' => isset($decoded['feedback']) ? (string) $decoded['feedback'] : '',
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'responseid' => new external_value(PARAM_INT, 'playervideo_responses id'),
            'aigrade' => new external_value(PARAM_FLOAT, 'AI-suggested grade, already scaled to the question weight'),
            'aifeedback' => new external_value(PARAM_RAW, 'AI-suggested feedback comment'),
            'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade for this question (its weight)'),
        ]);
    }
}
