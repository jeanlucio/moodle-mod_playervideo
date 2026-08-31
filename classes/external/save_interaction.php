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
 * External function to create, update or remove a timeline marker.
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
 * Creates, updates or deletes one playervideo_interactions row.
 */
class save_interaction extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'interactionid' => new external_value(PARAM_INT, 'Interaction id to update/delete, 0 to create a new one'),
            'timestamp' => new external_value(PARAM_FLOAT, 'Video second where the interaction fires', VALUE_DEFAULT, 0.0),
            'type' => new external_value(PARAM_ALPHA, 'question | note', VALUE_DEFAULT, ''),
            'questionid' => new external_value(PARAM_INT, 'Question Bank id, when type is question', VALUE_DEFAULT, 0),
            'notetext' => new external_value(PARAM_RAW, 'Note content, when type is note', VALUE_DEFAULT, ''),
            'weight' => new external_value(PARAM_FLOAT, 'Grading weight, when type is question', VALUE_DEFAULT, 1.0),
            'delete' => new external_value(PARAM_BOOL, 'Whether to delete interactionid instead of saving', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Creates, updates or deletes a timeline marker.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param int $interactionid Interaction id to update/delete, 0 to create a new one.
     * @param float $timestamp Video second where the interaction fires.
     * @param string $type 'question' | 'note'.
     * @param int $questionid Question Bank id, when type is question.
     * @param string $notetext Note content, when type is note.
     * @param float $weight Grading weight, when type is question.
     * @param bool $delete Whether to delete interactionid instead of saving.
     * @return array The saved/deleted interaction id.
     */
    public static function execute(
        int $playervideoid,
        int $interactionid,
        float $timestamp,
        string $type,
        int $questionid,
        string $notetext,
        float $weight,
        bool $delete
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'interactionid' => $interactionid,
            'timestamp' => $timestamp,
            'type' => $type,
            'questionid' => $questionid,
            'notetext' => $notetext,
            'weight' => $weight,
            'delete' => $delete,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:manage', $context);

        $existing = null;
        if ($params['interactionid'] > 0) {
            // Never trust interactionid alone — re-bind it to the already-validated playervideoid.
            $existing = $DB->get_record('playervideo_interactions', [
                'id' => $params['interactionid'],
                'playervideoid' => $params['playervideoid'],
            ]);
            if (!$existing) {
                throw new moodle_exception('error_interactionnotfound', 'mod_playervideo');
            }
        }

        if ($params['delete']) {
            if ($existing === null) {
                throw new moodle_exception('error_interactionnotfound', 'mod_playervideo');
            }
            if ($DB->record_exists('playervideo_responses', ['interactionid' => $existing->id])) {
                throw new moodle_exception('error_interactionhasresponses', 'mod_playervideo');
            }
            $DB->delete_records('playervideo_interactions', ['id' => $existing->id]);
            return ['interactionid' => (int) $existing->id, 'deleted' => true];
        }

        if ($params['type'] !== 'question' && $params['type'] !== 'note') {
            throw new moodle_exception('error_invalidinteractiontype', 'mod_playervideo');
        }

        if ($params['type'] === 'note') {
            if (trim($params['notetext']) === '') {
                throw new moodle_exception('error_notetextrequired', 'mod_playervideo');
            }
        } else {
            if ($params['questionid'] <= 0 || !$DB->record_exists('question', ['id' => $params['questionid']])) {
                throw new moodle_exception('error_questionnotfound', 'mod_playervideo');
            }
        }

        $now = time();
        $record = new \stdClass();
        $record->playervideoid = $params['playervideoid'];
        $record->timestamp = $params['timestamp'];
        $record->type = $params['type'];
        $record->weight = $params['type'] === 'question' ? max(0.0, $params['weight']) : 1.0;
        $record->questionid = $params['type'] === 'question' ? $params['questionid'] : null;
        $record->notetext = $params['type'] === 'note' ? $params['notetext'] : null;
        $record->notetextformat = FORMAT_HTML;
        $record->timemodified = $now;

        if ($existing !== null) {
            $record->id = $existing->id;
            $record->sortorder = $existing->sortorder;
            $DB->update_record('playervideo_interactions', $record);
            $savedid = $existing->id;
        } else {
            $record->sortorder = 0;
            $record->timecreated = $now;
            $savedid = $DB->insert_record('playervideo_interactions', $record);
        }

        return ['interactionid' => (int) $savedid, 'deleted' => false];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'interactionid' => new external_value(PARAM_INT, 'Saved or deleted interaction id'),
            'deleted' => new external_value(PARAM_BOOL, 'Whether the interaction was deleted'),
        ]);
    }
}
