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
 * External function to list the timeline markers of a PlayerVideo instance.
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
use mod_playervideo\local\question_service;

/**
 * Returns the trim window and every interaction (question/note) for the management screen.
 */
class get_interactions extends external_api {
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
     * Lists the trim window and every interaction for the timeline editor.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array Trim window and interactions.
     */
    public static function execute(int $playervideoid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['playervideoid' => $playervideoid]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:manage', $context);

        $instance = $DB->get_record('playervideo', ['id' => $params['playervideoid']], '*', MUST_EXIST);

        $records = $DB->get_records(
            'playervideo_interactions',
            ['playervideoid' => $params['playervideoid']],
            'timestamp ASC'
        );

        $questionids = array_filter(array_map(
            static fn($record) => $record->type === 'question' ? (int) $record->questionid : null,
            $records
        ));
        $questiontexts = question_service::get_question_texts($questionids, $context);

        $pollinteractionids = array_values(array_filter(array_map(
            static fn($record) => $record->type === 'poll' ? (int) $record->id : null,
            $records
        )));
        $polloptionsbyinteraction = self::get_poll_options($pollinteractionids);

        $interactions = [];
        foreach ($records as $record) {
            $questionpreview = $record->type === 'question' && $record->questionid !== null
                ? ($questiontexts[(int) $record->questionid] ?? '')
                : '';

            $interactions[] = [
                'id' => (int) $record->id,
                'timestamp' => (float) $record->timestamp,
                'type' => $record->type,
                'weight' => (float) $record->weight,
                'questionid' => $record->questionid !== null ? (int) $record->questionid : 0,
                'questionpreview' => $questionpreview,
                'notetext' => $record->notetext ?? '',
                'polloptions' => $polloptionsbyinteraction[(int) $record->id] ?? [],
            ];
        }

        return [
            'trimstart' => $instance->trimstart !== null ? (float) $instance->trimstart : null,
            'trimend' => $instance->trimend !== null ? (float) $instance->trimend : null,
            'interactions' => $interactions,
        ];
    }

    /**
     * Returns every poll option for the given interaction ids, grouped by interaction id, in a
     * single query — avoids one query per poll interaction in the loop above.
     *
     * @param int[] $interactionids Poll interaction ids.
     * @return array<int, array> Interaction id => list of {id, text}.
     */
    private static function get_poll_options(array $interactionids): array {
        global $DB;

        if (empty($interactionids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($interactionids);
        $records = $DB->get_records_select(
            'playervideo_poll_options',
            "interactionid $insql",
            $inparams,
            'interactionid ASC, sortorder ASC'
        );

        $grouped = [];
        foreach ($records as $record) {
            $grouped[(int) $record->interactionid][] = [
                'id' => (int) $record->id,
                'text' => $record->optiontext,
            ];
        }

        return $grouped;
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
            'interactions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Interaction id'),
                    'timestamp' => new external_value(PARAM_FLOAT, 'Video second where the interaction fires'),
                    'type' => new external_value(PARAM_ALPHA, 'question | note | poll'),
                    'weight' => new external_value(PARAM_FLOAT, 'Grading weight (only meaningful when type is question)'),
                    'questionid' => new external_value(PARAM_INT, 'Question Bank id (0 when type is note or poll)'),
                    'questionpreview' => new external_value(PARAM_RAW, 'Formatted question text, for the editor list'),
                    'notetext' => new external_value(PARAM_RAW, 'Note content, or poll prompt text (empty when type is question)'),
                    'polloptions' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Poll option id'),
                            'text' => new external_value(PARAM_TEXT, 'Option text'),
                        ]),
                        'Poll options, in display order (empty unless type is poll)'
                    ),
                ]),
                'Timeline interactions, ordered by timestamp'
            ),
        ]);
    }
}
