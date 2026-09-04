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
 * External function for a teacher to confirm the final grade of an open-question response.
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
use mod_playervideo\local\attempt_manager;
use mod_playervideo\local\group_access;
use moodle_exception;

/**
 * The only path that ever writes `teachergrade`/`teacherfeedback` — accepting the AI suggestion
 * as-is or overriding it is the same call, just with a different value. If this was the last
 * response still awaiting correction in its attempt, the attempt is recomputed and, once
 * `finished`, its grade is sent to the Gradebook (see the plugin SCOPE, "Gradebook só depois de
 * toda aberta corrigida").
 */
class review_response extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'responseid' => new external_value(PARAM_INT, 'playervideo_responses id'),
            'teachergrade' => new external_value(PARAM_FLOAT, 'Final grade, within the question\'s weight'),
            'teacherfeedback' => new external_value(PARAM_RAW, 'Final feedback comment', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Confirms the final grade for one open-question response.
     *
     * @param int $responseid playervideo_responses id.
     * @param float $teachergrade Final grade, within the question's weight.
     * @param string $teacherfeedback Final feedback comment.
     * @return array The resulting attempt status and, once finished, its grade.
     */
    public static function execute(int $responseid, float $teachergrade, string $teacherfeedback): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'responseid' => $responseid,
            'teachergrade' => $teachergrade,
            'teacherfeedback' => $teacherfeedback,
        ]);

        $response = $DB->get_record('playervideo_responses', ['id' => $params['responseid']], '*', MUST_EXIST);
        $interaction = $DB->get_record('playervideo_interactions', ['id' => $response->interactionid], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $response->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:reviewresponses', $context);

        if (!group_access::can_access_user($cm, $context, (int) $response->userid)) {
            throw new moodle_exception('error_studentnotinyourgroup', 'mod_playervideo');
        }

        if ($interaction->type !== 'question') {
            throw new moodle_exception('error_invalidinteractiontype', 'mod_playervideo');
        }

        $weight = (float) $interaction->weight;
        if ($params['teachergrade'] < 0 || $params['teachergrade'] > $weight) {
            throw new moodle_exception('error_invalidgrade', 'mod_playervideo');
        }

        $DB->update_record('playervideo_responses', (object) [
            'id' => $response->id,
            'teachergrade' => $params['teachergrade'],
            'teacherfeedback' => $params['teacherfeedback'],
            'status' => 'graded',
            'timemodified' => time(),
        ]);

        $instance = $DB->get_record('playervideo', ['id' => $response->playervideoid], '*', MUST_EXIST);
        $attempt = attempt_manager::recalculate_after_review((int) $response->attemptid, (float) $instance->grade);

        if ($attempt->status === 'finished') {
            playervideo_update_grades($instance, (int) $response->userid);
        }

        return [
            'responseid' => (int) $response->id,
            'attemptstatus' => $attempt->status,
            'grade' => $attempt->grade !== null ? (float) $attempt->grade : null,
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
            'attemptstatus' => new external_value(PARAM_ALPHA, 'finished | pendingcorrection'),
            'grade' => new external_value(
                PARAM_FLOAT,
                'The attempt\'s final grade, once finished; null while another response is still pending',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
