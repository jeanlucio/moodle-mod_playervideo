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
 * External function to read back a finished attempt for the review screen and attempt summary.
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

/**
 * Read-only view of one attempt: the response given, the correct answer and feedback per
 * interaction, in timeline order. Reused for both the review-mode overlay and the compact
 * attempt summary shown right after finishing.
 */
class get_attempt_review extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
        ]);
    }

    /**
     * Builds the review data for one attempt.
     *
     * @param int $attemptid Attempt id.
     * @return array Per-interaction review rows, in timeline order.
     */
    public static function execute(int $attemptid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $attempt->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        if ((int) $attempt->userid === (int) $USER->id) {
            require_capability('mod/playervideo:attempt', $context);
        } else {
            require_capability('mod/playervideo:reviewresponses', $context);
        }

        $interactions = $DB->get_records(
            'playervideo_interactions',
            ['playervideoid' => $attempt->playervideoid],
            'timestamp ASC'
        );

        $responses = $DB->get_records('playervideo_responses', ['attemptid' => $attempt->id]);
        $responsesbyinteraction = [];
        foreach ($responses as $response) {
            $responsesbyinteraction[$response->interactionid] = $response;
        }

        $result = [];
        foreach ($interactions as $interaction) {
            $response = $responsesbyinteraction[$interaction->id] ?? null;

            $row = [
                'interactionid' => (int) $interaction->id,
                'timestamp' => (float) $interaction->timestamp,
                'type' => $interaction->type,
                'notetext' => $interaction->type === 'note' ? ($interaction->notetext ?? '') : '',
                'questiontext' => '',
                'qtype' => '',
                'options' => [],
                'responsetext' => '',
                'iscorrect' => null,
                'teachergrade' => null,
                'teacherfeedback' => '',
                'status' => $response !== null ? $response->status : 'notreached',
            ];

            if ($interaction->type === 'question' && $interaction->questionid !== null) {
                $question = question_service::get_question_for_review((int) $interaction->questionid, $context);
                if ($question !== null) {
                    $row['questiontext'] = $question['text'];
                    $row['qtype'] = $question['type'];
                    $row['options'] = array_map(static fn(array $option): array => [
                        'id' => $option['id'],
                        'text' => $option['text'],
                        'correct' => $option['correct'],
                        'selected' => $response !== null && (int) $response->answerid === $option['id'],
                    ], $question['options']);
                }
            }

            if ($response !== null) {
                $row['responsetext'] = $response->responsetext ?? '';
                $row['iscorrect'] = $response->iscorrect !== null ? (bool) $response->iscorrect : null;
                $row['teachergrade'] = $response->teachergrade !== null ? (float) $response->teachergrade : null;
                $row['teacherfeedback'] = $response->teacherfeedback ?? '';
            }

            $result[] = $row;
        }

        return ['interactions' => $result];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'interactions' => new external_multiple_structure(
                new external_single_structure([
                    'interactionid' => new external_value(PARAM_INT, 'Interaction id'),
                    'timestamp' => new external_value(PARAM_FLOAT, 'Video second where the interaction fires'),
                    'type' => new external_value(PARAM_ALPHA, 'question | note'),
                    'notetext' => new external_value(PARAM_RAW, 'Note content (empty when type is question)'),
                    'questiontext' => new external_value(PARAM_RAW, 'Formatted question text (empty when type is note)'),
                    'qtype' => new external_value(PARAM_ALPHANUMEXT, 'Question type (empty when type is note)'),
                    'options' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Answer id'),
                            'text' => new external_value(PARAM_RAW, 'Formatted answer text'),
                            'correct' => new external_value(PARAM_BOOL, 'Whether this is the correct answer'),
                            'selected' => new external_value(PARAM_BOOL, 'Whether the student chose this answer'),
                        ]),
                        'Answer options (empty for open questions and notes)'
                    ),
                    'responsetext' => new external_value(PARAM_RAW, 'Free-text response given, when applicable'),
                    'iscorrect' => new external_value(
                        PARAM_BOOL,
                        'True/false for multichoice-type answers, null otherwise',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'teachergrade' => new external_value(
                        PARAM_FLOAT,
                        'Teacher-confirmed grade, for an open question',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'teacherfeedback' => new external_value(PARAM_RAW, 'Teacher feedback, for an open question'),
                    'status' => new external_value(
                        PARAM_ALPHANUMEXT,
                        'answered | viewed | pending_review | graded | notreached'
                    ),
                ]),
                'Interactions, in timeline order'
            ),
        ]);
    }
}
