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
        ]);
    }

    /**
     * Sets the trim window for an instance.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param float|null $trimstart Playback window start, in seconds, or null to clear it.
     * @param float|null $trimend Playback window end, in seconds, or null to clear it.
     * @return array The saved trim window.
     */
    public static function execute(int $playervideoid, ?float $trimstart, ?float $trimend): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'trimstart' => $trimstart,
            'trimend' => $trimend,
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

        $DB->update_record('playervideo', (object) [
            'id' => $params['playervideoid'],
            'trimstart' => $params['trimstart'],
            'trimend' => $params['trimend'],
            'timemodified' => time(),
        ]);

        return ['trimstart' => $params['trimstart'], 'trimend' => $params['trimend']];
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
        ]);
    }
}
