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
 * External function to list open-question responses awaiting correction.
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
use mod_playervideo\local\group_access;
use mod_playervideo\local\question_service;

/**
 * Feeds the grading screen's queue: every response still in `pending_ai`/`pending_review`
 * across every student, with enough context (question text, the student's own answer, the
 * question's weight) to grade it without a second round-trip per row.
 */
class get_pending_corrections extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
        ]);
    }

    /**
     * Lists the pending open-question responses for an instance.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array Pending responses, oldest first.
     */
    public static function execute(int $playervideoid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['playervideoid' => $playervideoid]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:reviewresponses', $context);

        $responses = $DB->get_records_select(
            'playervideo_responses',
            'playervideoid = :playervideoid AND status IN (:pendingai, :pendingreview)',
            [
                'playervideoid' => $params['playervideoid'],
                'pendingai' => 'pending_ai',
                'pendingreview' => 'pending_review',
            ],
            'timecreated ASC'
        );

        $restricteduserids = group_access::restricted_userids($cm, $context);
        if ($restricteduserids !== null) {
            $responses = array_filter(
                $responses,
                static fn($record): bool => in_array((int) $record->userid, $restricteduserids, true)
            );
        }

        if (empty($responses)) {
            return ['responses' => []];
        }

        $interactionids = array_values(array_unique(array_map(
            static fn($record): int => (int) $record->interactionid,
            $responses
        )));
        [$insql, $inparams] = $DB->get_in_or_equal($interactionids);
        $interactions = $DB->get_records_select('playervideo_interactions', "id $insql", $inparams);

        $questionids = array_values(array_unique(array_map(
            static fn($record): int => (int) $record->questionid,
            $interactions
        )));
        $questiontexts = question_service::get_question_texts($questionids, $context);

        $userids = array_values(array_unique(array_map(
            static fn($record): int => (int) $record->userid,
            $responses
        )));
        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname, firstnamephonetic, '
            . 'lastnamephonetic, middlename, alternatename');

        $result = [];
        foreach ($responses as $response) {
            $interaction = $interactions[(int) $response->interactionid] ?? null;
            if ($interaction === null) {
                continue;
            }
            $user = $users[(int) $response->userid] ?? null;

            $result[] = [
                'responseid' => (int) $response->id,
                'interactionid' => (int) $interaction->id,
                'timestamp' => (float) $interaction->timestamp,
                'userid' => (int) $response->userid,
                'fullname' => $user !== null ? fullname($user) : '',
                'questiontext' => $questiontexts[(int) $interaction->questionid] ?? '',
                'responsetext' => (string) $response->responsetext,
                'aigrade' => $response->aigrade !== null ? (float) $response->aigrade : null,
                'aifeedback' => (string) ($response->aifeedback ?? ''),
                'maxgrade' => (float) $interaction->weight,
                'status' => $response->status,
            ];
        }

        return ['responses' => $result];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'responses' => new external_multiple_structure(
                new external_single_structure([
                    'responseid' => new external_value(PARAM_INT, 'playervideo_responses id'),
                    'interactionid' => new external_value(PARAM_INT, 'Interaction id'),
                    'timestamp' => new external_value(PARAM_FLOAT, 'Video second where the interaction fires'),
                    'userid' => new external_value(PARAM_INT, 'Student id'),
                    'fullname' => new external_value(PARAM_RAW, 'Student full name'),
                    'questiontext' => new external_value(PARAM_RAW, 'Formatted question text'),
                    'responsetext' => new external_value(PARAM_RAW, 'The student\'s free-text answer'),
                    'aigrade' => new external_value(
                        PARAM_FLOAT,
                        'AI-suggested grade, null if not generated yet',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'aifeedback' => new external_value(PARAM_RAW, 'AI-suggested feedback, empty if not generated yet'),
                    'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade for this question (its weight)'),
                    'status' => new external_value(PARAM_ALPHANUMEXT, 'pending_ai | pending_review'),
                ]),
                'Pending open-question responses, oldest first'
            ),
        ]);
    }
}
