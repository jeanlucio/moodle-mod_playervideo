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
 * Custom completion rules for PlayerVideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\completion;

use core_completion\activity_custom_completion;

/**
 * The two completion rules that cover the "no grade" case (see the plugin SCOPE, "Nota
 * opcional"): completionallinteractions counts participation (answered/viewed, correctness
 * irrelevant) across any attempt; completionwatchtoend reflects the player's own native
 * `ended` event, never a percentage threshold.
 */
class custom_completion extends activity_custom_completion {
    #[\Override]
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        if ($rule === 'completionallinteractions') {
            $totalinteractions = $DB->count_records('playervideo_interactions', [
                'playervideoid' => $this->cm->instance,
            ]);

            if ($totalinteractions === 0) {
                return COMPLETION_INCOMPLETE;
            }

            $answeredinteractions = $DB->count_records_sql(
                'SELECT COUNT(DISTINCT interactionid)
                   FROM {playervideo_responses}
                  WHERE playervideoid = :playervideoid AND userid = :userid',
                ['playervideoid' => $this->cm->instance, 'userid' => $this->userid]
            );

            return $answeredinteractions >= $totalinteractions ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }

        // Rule: completionwatchtoend.
        $watchedtoend = $DB->get_field('playervideo_progress', 'watchedtoend', [
            'playervideoid' => $this->cm->instance,
            'userid' => $this->userid,
        ]);

        return !empty($watchedtoend) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    #[\Override]
    public static function get_defined_custom_rules(): array {
        return ['completionallinteractions', 'completionwatchtoend'];
    }

    #[\Override]
    public function get_custom_rule_descriptions(): array {
        return [
            'completionallinteractions' => get_string('completiondetail:allinteractions', 'mod_playervideo'),
            'completionwatchtoend' => get_string('completiondetail:watchtoend', 'mod_playervideo'),
        ];
    }

    #[\Override]
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionallinteractions',
            'completionwatchtoend',
            'completionusegrade',
            'completionpassgrade',
        ];
    }
}
