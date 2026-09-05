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
 * External function to start a new attempt, or resume the one already in progress.
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
use mod_playervideo\local\attempt_lock;
use mod_playervideo\local\attempt_manager;
use mod_playervideo\local\hud_service;
use moodle_exception;

/**
 * Opens a new attempt (charging the PlayerHUD retry cost when applicable) or hands back the
 * one already in progress, together with which interactions the player must never pause on
 * again in this attempt.
 */
class start_attempt extends external_api {
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
     * Starts or resumes an attempt.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array The attempt id and the interactions already treated in it.
     */
    public static function execute(int $playervideoid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['playervideoid' => $playervideoid]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:attempt', $context);

        $instance = $DB->get_record('playervideo', ['id' => $params['playervideoid']], '*', MUST_EXIST);
        $userid = (int) $USER->id;

        // The can_start_new_attempt() check and the attempt insert below must run as one atomic
        // sequence: without this lock, two concurrent requests can both see attemptcount <
        // maxattempts before either writes, letting the student open more attempts than the
        // teacher configured.
        $lock = attempt_lock::acquire('start_' . $instance->id . '_' . $userid);
        try {
            $attempt = attempt_manager::get_open_attempt($instance->id, $userid);

            // The attempt_manager::get_open_attempt() lookup above also returns a
            // pendingcorrection attempt, but there is nothing left to resume there — the video
            // was already watched, only the grade is withheld. Treat it like no open attempt: a
            // fresh one may start if can_start_new_attempt() still allows it (a pendingcorrection
            // attempt already counts toward that total, so this never lets a student exceed
            // maxattempts).
            if ($attempt !== null && $attempt->status === 'pendingcorrection') {
                $attempt = null;
            }

            if ($attempt === null) {
                if (!attempt_manager::can_start_new_attempt($instance->id, $userid, (int) $instance->maxattempts)) {
                    throw new moodle_exception('error_noattemptsleft', 'mod_playervideo');
                }

                $attemptnumber = 1 + $DB->count_records('playervideo_attempts', [
                    'playervideoid' => $instance->id,
                    'userid' => $userid,
                ]);

                $charged = false;
                if ($attemptnumber > 1 && (int) $instance->hudretrycostitem > 0 && (int) $instance->hudretrycostqty > 0) {
                    $blockinstanceid = hud_service::resolve_block_instance_id($instance);
                    $charged = hud_service::consume_items(
                        $blockinstanceid,
                        $userid,
                        (int) $instance->hudretrycostitem,
                        (int) $instance->hudretrycostqty
                    );
                    if (!$charged) {
                        throw new moodle_exception('error_insufficienthuditems', 'mod_playervideo');
                    }
                }

                $attempt = attempt_manager::start_attempt($instance->id, $userid);

                if ($charged) {
                    $attempt->hudretrycharged = 1;
                    $DB->set_field('playervideo_attempts', 'hudretrycharged', 1, ['id' => $attempt->id]);
                }
            }
        } finally {
            $lock->release();
        }

        $treatedinteractionids = $DB->get_fieldset_select(
            'playervideo_responses',
            'interactionid',
            'attemptid = :attemptid',
            ['attemptid' => $attempt->id]
        );

        return [
            'attemptid' => (int) $attempt->id,
            'attemptnumber' => (int) $attempt->attemptnumber,
            'treatedinteractionids' => array_map('intval', $treatedinteractionids),
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'The open attempt id'),
            'attemptnumber' => new external_value(PARAM_INT, '1st, 2nd, 3rd... attempt by this student'),
            'treatedinteractionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Interaction id already answered/viewed in this attempt'),
                'Interactions the player must never pause on again in this attempt'
            ),
        ]);
    }
}
