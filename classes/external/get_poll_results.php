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
 * External function to read the aggregate vote distribution of a poll interaction.
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
use moodle_exception;

/**
 * Returns how many students voted for each option of a poll — shown to a student right after
 * confirming their own vote (see the plugin SCOPE, "Enquete"). Never identifies which student
 * voted for which option, only aggregate counts, so it needs no ownership check beyond belonging
 * to the already-validated instance.
 */
class get_poll_results extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'interactionid' => new external_value(PARAM_INT, 'Poll interaction id'),
        ]);
    }

    /**
     * Builds the vote distribution for one poll.
     *
     * @param int $interactionid Poll interaction id.
     * @return array Per-option vote counts and percentages.
     */
    public static function execute(int $interactionid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['interactionid' => $interactionid]);

        $interaction = $DB->get_record('playervideo_interactions', ['id' => $params['interactionid']], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $interaction->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:attempt', $context);

        if ($interaction->type !== 'poll') {
            throw new moodle_exception('error_invalidinteractiontype', 'mod_playervideo');
        }

        $options = $DB->get_records('playervideo_poll_options', ['interactionid' => $interaction->id], 'sortorder ASC');

        $votecounts = $DB->get_records_sql(
            'SELECT polloptionid, COUNT(id) AS votes
               FROM {playervideo_responses}
              WHERE interactionid = :interactionid AND status = :status
           GROUP BY polloptionid',
            ['interactionid' => $interaction->id, 'status' => 'voted']
        );

        $totalvotes = 0;
        foreach ($votecounts as $row) {
            $totalvotes += (int) $row->votes;
        }

        $results = [];
        foreach ($options as $option) {
            $votes = isset($votecounts[$option->id]) ? (int) $votecounts[$option->id]->votes : 0;
            $results[] = [
                'polloptionid' => (int) $option->id,
                'optiontext' => $option->optiontext,
                'votes' => $votes,
                'percent' => $totalvotes > 0 ? round(($votes / $totalvotes) * 100, 1) : 0.0,
            ];
        }

        return ['options' => $results];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'options' => new external_multiple_structure(
                new external_single_structure([
                    'polloptionid' => new external_value(PARAM_INT, 'Poll option id'),
                    'optiontext' => new external_value(PARAM_TEXT, 'Option text'),
                    'votes' => new external_value(PARAM_INT, 'Number of students who chose this option'),
                    'percent' => new external_value(PARAM_FLOAT, 'Percentage of all votes, rounded to 1 decimal'),
                ]),
                'Vote distribution, in the poll\'s display order'
            ),
        ]);
    }
}
