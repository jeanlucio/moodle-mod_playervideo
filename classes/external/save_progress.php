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
use mod_playervideo\local\segment_tracker;
use moodle_exception;
use stdClass;

/**
 * Persists resume position and watched segments (playervideo_progress).
 *
 * The optional ended flag was not part of the WS originally, but was added because the "watched
 * to the end" completion rule needs some write path to flip playervideo_progress.watchedtoend
 * when the player's native `ended` event fires, and no
 * separate WS was budgeted for that single boolean — piggybacking on the same heartbeat record
 * avoids a sixth WS for one field on the same row.
 *
 * Segments reported by the client are untrusted: they are validated, clamped to the known video
 * duration and merged with the already-persisted set via segment_tracker before being stored —
 * never persisted as the raw JSON the client sent, which is what happened previously and left
 * watchedpct permanently unwritten.
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
            'duration' => new external_value(
                PARAM_FLOAT,
                'Video duration reported by the player, in seconds (0 when not yet known)',
                VALUE_DEFAULT,
                0.0
            ),
            'ended' => new external_value(PARAM_BOOL, 'Whether the native ended event just fired', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Records the student's current playback progress.
     *
     * @param int $attemptid Attempt id.
     * @param float $lastposition Current playback position, in seconds.
     * @param string $segments JSON array of watched second ranges.
     * @param float $duration Video duration reported by the player, in seconds (0 when not yet known).
     * @param bool $ended Whether the native ended event just fired.
     * @return array Confirmation and the newly calculated watched percentage.
     */
    public static function execute(int $attemptid, float $lastposition, string $segments, float $duration, bool $ended): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'lastposition' => $lastposition,
            'segments' => $segments,
            'duration' => $duration,
            'ended' => $ended,
        ]);

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $instance = $DB->get_record('playervideo', ['id' => $attempt->playervideoid], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $attempt->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:attempt', $context);

        if ((int) $attempt->userid !== (int) $USER->id) {
            throw new moodle_exception('error_notyourattempt', 'mod_playervideo');
        }

        $incoming = json_decode($params['segments'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('error_invalidsegments', 'mod_playervideo');
        }
        $incoming = is_array($incoming) ? $incoming : [];

        // The video duration is a property of the shared content, not of this student's
        // progress, so it lives on the instance itself and only ever grows: an isolated
        // heartbeat with an incompletely-resolved player duration must never shrink the
        // divisor used to calculate everyone else's watchedpct.
        if ($params['duration'] > (float) $instance->duration) {
            $DB->set_field('playervideo', 'duration', $params['duration'], ['id' => $instance->id]);
            $instance->duration = $params['duration'];
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
            $progress->segments = '[]';
            $progress->timecreated = $now;
        }

        $existing = json_decode((string) $progress->segments, true);
        $merged = segment_tracker::merge(is_array($existing) ? $existing : [], $incoming, (float) $instance->duration);

        $progress->lastposition = $params['lastposition'];
        $progress->segments = json_encode($merged);
        $progress->watchedpct = self::calculate_watched_percent($instance, $merged);
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

        return ['ok' => true, 'watchedpct' => (float) $progress->watchedpct];
    }

    /**
     * Calculates the percentage of the effective playback window actually watched.
     *
     * The effective window respects the activity's own trim (see save_trim): a video cut to
     * end before its real duration must not require watching the discarded tail to reach 100%.
     *
     * @param stdClass $instance The playervideo instance (needs duration/trimstart/trimend).
     * @param array $segments Already-normalised [start, end] pairs.
     * @return float Percentage from 0 to 100, rounded to two decimals.
     */
    private static function calculate_watched_percent(stdClass $instance, array $segments): float {
        $windowstart = $instance->trimstart !== null ? (float) $instance->trimstart : 0.0;
        $windowend = $instance->trimend !== null ? (float) $instance->trimend : (float) $instance->duration;
        $windowlength = $windowend - $windowstart;
        if ($windowlength <= 0) {
            return 0.0;
        }

        $watched = segment_tracker::unique_seconds(segment_tracker::clip_to_window($segments, $windowstart, $windowend));

        return round(min(100, ($watched / $windowlength) * 100), 2);
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the progress was saved'),
            'watchedpct' => new external_value(PARAM_FLOAT, 'Newly calculated percentage of the video actually watched'),
        ]);
    }
}
