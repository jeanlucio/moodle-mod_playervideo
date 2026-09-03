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
 * External function to list the manually authored captions of a PlayerVideo instance.
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
use mod_playervideo\local\caption_service;

/**
 * Returns every manually authored caption of an instance.
 *
 * Used both by the teacher's caption editor (to list what already exists) and by the student
 * player (to merge with whatever native tracks the source adapter finds live) — never any
 * provider's native tracks itself, since those are never copied into the plugin's own tables
 * (see the plugin SCOPE, "Editor manual de legenda"). Read-only content authored by the
 * teacher, so any enrolled user with view access may call it — never gated behind
 * mod/playervideo:manage.
 */
class get_captions extends external_api {
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
     * Lists the manually authored captions of an instance.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array Captions for this instance.
     */
    public static function execute(int $playervideoid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['playervideoid' => $playervideoid]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:view', $context);

        $records = caption_service::get_captions($params['playervideoid']);

        $captions = array_map(static fn(\stdClass $record): array => [
            'lang' => $record->lang,
            'source' => $record->source,
            'content' => $record->content,
        ], $records);

        return ['captions' => $captions];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'captions' => new external_multiple_structure(
                new external_single_structure([
                    'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language code, e.g. en, pt-br'),
                    'source' => new external_value(PARAM_ALPHA, 'manual | youtube | vimeo (informational only)'),
                    'content' => new external_value(PARAM_RAW, 'Full caption content, VTT format'),
                ]),
                'Manually authored caption tracks for this instance'
            ),
        ]);
    }
}
