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
 * External function to heartbeat playback position and watched segments.
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
use stdClass;

/**
 * Persists resume position and watched segments (§5, playervideo_progress).
 *
 * The optional ended flag is not part of the WS originally scoped in the SCOPE document, but was
 * added because the "watched to the end" completion rule (§4, Rule 2) needs some write path to
 * flip playervideo_progress.watchedtoend when the player's native `ended` event fires, and no
 * separate WS was budgeted for that single boolean — piggybacking on the same heartbeat record
 * avoids a sixth WS for one field on the same row.
 */
class save_progress extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'lastposition' => new external_value(PARAM_FLOAT, 'Current playback position, in seconds'),
            'segments' => new external_value(PARAM_RAW, 'JSON array of watched second ranges', VALUE_DEFAULT, '[]'),
            'ended' => new external_value(PARAM_BOOL, 'Whether the native ended event just fired', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Records the student's current playback progress.
     *
     * @param int $attemptid Attempt id.
     * @param float $lastposition Current playback position, in seconds.
     * @param string $segments JSON array of watched second ranges.
     * @param bool $ended Whether the native ended event just fired.
     * @return array Confirmation.
     */
    public static function execute(int $attemptid, float $lastposition, string $segments, bool $ended): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'lastposition' => $lastposition,
            'segments' => $segments,
            'ended' => $ended,
        ]);

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $attempt->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:attempt', $context);

        if ((int) $attempt->userid !== (int) $USER->id) {
            throw new moodle_exception('error_notyourattempt', 'mod_playervideo');
        }

        if (json_decode($params['segments']) === null && $params['segments'] !== 'null') {
            throw new moodle_exception('error_invalidsegments', 'mod_playervideo');
        }

        $now = time();
        $progress = $DB->get_record('playervideo_progress', [
            'playervideoid' => $attempt->playervideoid,
            'userid' => $attempt->userid,
        ]);

        if ($progress === false) {
            $progress = new stdClass();
            $progress->playervideoid = $attempt->playervideoid;
            $progress->userid = $attempt->userid;
            $progress->watchedtoend = 0;
            $progress->timecreated = $now;
        }

        $progress->lastposition = $params['lastposition'];
        $progress->segments = $params['segments'];
        $progress->timemodified = $now;
        if ($params['ended']) {
            $progress->watchedtoend = 1;
        }

        if (isset($progress->id)) {
            $DB->update_record('playervideo_progress', $progress);
        } else {
            $DB->insert_record('playervideo_progress', $progress);
        }

        if ($params['ended']) {
            $course = get_course($cm->course);
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
            }
        }

        return ['ok' => true];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the progress was saved'),
        ]);
    }
}
