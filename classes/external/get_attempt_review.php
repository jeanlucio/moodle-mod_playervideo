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

        $questionids = array_values(array_filter(array_map(
            static fn($record) => $record->type === 'question' ? (int) $record->questionid : null,
            $interactions
        )));
        $questionsbyid = question_service::get_questions_for_review($questionids, $context);

        $pollinteractionids = array_values(array_filter(array_map(
            static fn($record) => $record->type === 'poll' ? (int) $record->id : null,
            $interactions
        )));
        $polloptionsbyinteraction = self::get_poll_review_options_bulk($pollinteractionids);

        $result = [];
        foreach ($interactions as $interaction) {
            $response = $responsesbyinteraction[$interaction->id] ?? null;

            $row = [
                'interactionid' => (int) $interaction->id,
                'timestamp' => (float) $interaction->timestamp,
                'type' => $interaction->type,
                'notetext' => $interaction->type !== 'question' ? format_text(
                    $interaction->notetext ?? '',
                    $interaction->notetextformat,
                    ['context' => $context]
                ) : '',
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
                $question = $questionsbyid[(int) $interaction->questionid] ?? null;
                if ($question !== null) {
                    $row['questiontext'] = $question['text'];
                    $row['qtype'] = $question['type'];
                    $row['options'] = array_map(static fn(array $option): array => [
                        'id' => $option['id'],
                        'text' => $option['text'],
                        'correct' => $option['correct'],
                        'selected' => $response !== null && (int) $response->answerid === $option['id'],
                        'votes' => 0,
                        'percent' => 0.0,
                    ], $question['options']);
                }
            } else if ($interaction->type === 'poll') {
                $polloptions = $polloptionsbyinteraction[(int) $interaction->id] ?? [];
                $row['options'] = array_map(static fn(array $option): array => [
                    'id' => $option['id'],
                    'text' => $option['text'],
                    'correct' => false,
                    'selected' => $response !== null && (int) $response->polloptionid === $option['id'],
                    'votes' => $option['votes'],
                    'percent' => $option['percent'],
                ], $polloptions);
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
     * Builds the poll options review data for several poll interactions at once, grouped by
     * interaction id: the full vote distribution (same aggregate any voter would see, see
     * get_poll_results) for each — avoids one poll_options query + one vote-count query per poll
     * interaction in a loop. Never includes 'selected', since that depends on which attempt is
     * being reviewed, not on the interaction alone — the caller applies it per row.
     *
     * @param int[] $interactionids Poll interaction ids.
     * @return array<int, array> Interaction id => list of {id, text, votes, percent}.
     */
    private static function get_poll_review_options_bulk(array $interactionids): array {
        global $DB;

        if (empty($interactionids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($interactionids, SQL_PARAMS_NAMED, 'iid');
        $options = $DB->get_records_select(
            'playervideo_poll_options',
            "interactionid $insql",
            $inparams,
            'interactionid ASC, sortorder ASC'
        );

        // A recordset, not get_records_sql(), because the grouping key (interactionid +
        // polloptionid) is not the single first column — get_records_sql() would key the array
        // by interactionid alone and silently drop every option but the last one.
        $votecountsbyinteraction = [];
        $totalvotesbyinteraction = [];
        $sql = "SELECT interactionid, polloptionid, COUNT(id) AS votes
                  FROM {playervideo_responses}
                 WHERE interactionid $insql AND status = :status
              GROUP BY interactionid, polloptionid";
        $voterows = $DB->get_recordset_sql($sql, array_merge($inparams, ['status' => 'voted']));
        foreach ($voterows as $row) {
            $iid = (int) $row->interactionid;
            $votecountsbyinteraction[$iid][(int) $row->polloptionid] = (int) $row->votes;
            $totalvotesbyinteraction[$iid] = ($totalvotesbyinteraction[$iid] ?? 0) + (int) $row->votes;
        }
        $voterows->close();

        $result = [];
        foreach ($options as $option) {
            $iid = (int) $option->interactionid;
            $votes = $votecountsbyinteraction[$iid][(int) $option->id] ?? 0;
            $totalvotes = $totalvotesbyinteraction[$iid] ?? 0;
            $result[$iid][] = [
                'id' => (int) $option->id,
                'text' => $option->optiontext,
                'votes' => $votes,
                'percent' => $totalvotes > 0 ? round(($votes / $totalvotes) * 100, 1) : 0.0,
            ];
        }

        return $result;
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
                    'type' => new external_value(PARAM_ALPHA, 'question | note | poll'),
                    'notetext' => new external_value(PARAM_RAW, 'Note content, or poll prompt text (empty when type is question)'),
                    'questiontext' => new external_value(PARAM_RAW, 'Formatted question text (empty unless type is question)'),
                    'qtype' => new external_value(PARAM_ALPHANUMEXT, 'Question type (empty unless type is question)'),
                    'options' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Answer or poll option id'),
                            'text' => new external_value(PARAM_RAW, 'Formatted option text'),
                            'correct' => new external_value(PARAM_BOOL, 'Correct answer (always false for a poll)'),
                            'selected' => new external_value(PARAM_BOOL, 'Whether the student chose this option'),
                            'votes' => new external_value(PARAM_INT, 'Vote count (poll only, 0 otherwise)'),
                            'percent' => new external_value(PARAM_FLOAT, 'Vote percentage (poll only, 0 otherwise)'),
                        ]),
                        'Answer/poll options (empty for open questions and notes)'
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
                        'answered | viewed | voted | pending_ai | pending_review | graded | notreached'
                    ),
                ]),
                'Interactions, in timeline order'
            ),
        ]);
    }
}
