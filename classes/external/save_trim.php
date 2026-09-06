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
 * External function to set the playback trim window (start/end) of a PlayerVideo instance.
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
use moodle_exception;

/**
 * Sets playervideo.trimstart/trimend — two draggable markers on the same timeline widget
 * used for interactions, but stored as plain
 * instance columns rather than interaction rows: a trim boundary is a property of the video
 * (at most one start, one end), never a repeatable list item.
 *
 * The optional `duration` parameter lets the interactions editor persist the video length the
 * player reported to it, from the teacher's own browser. This is the only write path to
 * playervideo.duration: save_progress (the student heartbeat) never touches it. Every real
 * activity passes through this editor — it is where interactions are authored — so the
 * duration is established here before any student attempt. A teacher holding
 * mod/playervideo:manage is trusted: the value is stored as-is, only sanity-clamped to 24h.
 */
class save_trim extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'trimstart' => new external_value(PARAM_FLOAT, 'Playback window start, in seconds', VALUE_DEFAULT, null, NULL_ALLOWED),
            'trimend' => new external_value(PARAM_FLOAT, 'Playback window end, in seconds', VALUE_DEFAULT, null, NULL_ALLOWED),
            'duration' => new external_value(
                PARAM_FLOAT,
                'Video length the editor\'s player reported, in seconds; null to leave the stored value untouched',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }

    /**
     * Sets the trim window for an instance.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param float|null $trimstart Playback window start, in seconds, or null to clear it.
     * @param float|null $trimend Playback window end, in seconds, or null to clear it.
     * @param float|null $duration Video length the editor's player reported, or null to leave it untouched.
     * @return array The saved trim window.
     */
    public static function execute(
        int $playervideoid,
        ?float $trimstart,
        ?float $trimend,
        ?float $duration = null
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'trimstart' => $trimstart,
            'trimend' => $trimend,
            'duration' => $duration,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:manage', $context);

        if ($params['trimstart'] !== null && $params['trimstart'] < 0) {
            throw new moodle_exception('error_invalidtrim', 'mod_playervideo');
        }
        if (
            $params['trimstart'] !== null && $params['trimend'] !== null
            && $params['trimend'] <= $params['trimstart']
        ) {
            throw new moodle_exception('error_invalidtrim', 'mod_playervideo');
        }

        $record = (object) [
            'id' => $params['playervideoid'],
            'trimstart' => $params['trimstart'],
            'trimend' => $params['trimend'],
            'timemodified' => time(),
        ];

        // A teacher's own player is the most trustworthy source of the video length available
        // (there is no server-side probe). Store it authoritatively, only sanity-clamped, so
        // opening the editor also repairs a value a hostile save_progress heartbeat may have set.
        $reported = $params['duration'];
        $durationsane = $reported !== null && is_finite($reported) && $reported > 0 && $reported <= DAYSECS;
        if ($durationsane) {
            $record->duration = round((float) $reported, 2);
        }

        $DB->update_record('playervideo', $record);
        $storedduration = $DB->get_field('playervideo', 'duration', ['id' => $params['playervideoid']]);

        return [
            'trimstart' => $params['trimstart'],
            'trimend' => $params['trimend'],
            'duration' => $storedduration !== false && $storedduration !== null ? (float) $storedduration : null,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'trimstart' => new external_value(PARAM_FLOAT, 'Playback window start, in seconds', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'trimend' => new external_value(PARAM_FLOAT, 'Playback window end, in seconds', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'duration' => new external_value(
                PARAM_FLOAT,
                'Video length now stored for the instance, in seconds, or null when still unknown',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
