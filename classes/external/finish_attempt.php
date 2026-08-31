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
 * External function to end an attempt and, when possible, send its grade to the Gradebook.
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
use moodle_exception;

/**
 * Closes an attempt: if no open question is left pending correction, computes the attempt's
 * grade and aggregates the student's final activity grade to the Gradebook (mirroring mod_quiz,
 * which withholds any grade while manual marking is outstanding — see the plugin SCOPE,
 * "Gradebook só depois de toda aberta corrigida").
 */
class finish_attempt extends external_api {
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
     * Finishes the attempt.
     *
     * @param int $attemptid Attempt id.
     * @return array The final grade (when available) and the attempt's resulting status.
     */
    public static function execute(int $attemptid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $attempt->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:attempt', $context);

        if ((int) $attempt->userid !== (int) $USER->id) {
            throw new moodle_exception('error_notyourattempt', 'mod_playervideo');
        }
        if ($attempt->status !== 'inprogress') {
            throw new moodle_exception('error_attemptnotinprogress', 'mod_playervideo');
        }

        $instance = $DB->get_record('playervideo', ['id' => $attempt->playervideoid], '*', MUST_EXIST);

        $finished = attempt_manager::finish_attempt($attempt->id, (float) $instance->grade);

        if ($finished->status === 'finished') {
            playervideo_update_grades($instance, $attempt->userid);
        }

        $course = get_course($cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
        }

        return [
            'grade' => $finished->grade !== null ? (float) $finished->grade : null,
            'status' => $finished->status,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'grade' => new external_value(
                PARAM_FLOAT,
                'Final grade, null while pending correction',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
            'status' => new external_value(PARAM_ALPHA, 'finished | pendingcorrection'),
        ]);
    }
}
