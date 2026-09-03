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
 * External function to list the DI easy-read summaries of a PlayerVideo instance.
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
use mod_playervideo\local\di_summary_service;

/**
 * Returns the DI summaries of an instance — every one (any status) for a teacher, but only the
 * approved ones for anyone else, enforced server-side rather than left to the client to hide a
 * still-pending draft (see the plugin SCOPE, "Resumo por IA em leitura fácil").
 */
class get_di_summaries extends external_api {
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
     * Lists the DI summaries visible to the current user.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array DI summaries for this instance.
     */
    public static function execute(int $playervideoid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['playervideoid' => $playervideoid]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:view', $context);

        $canmanage = has_capability('mod/playervideo:manage', $context);
        $records = di_summary_service::get_summaries($params['playervideoid']);

        $summaries = [];
        foreach ($records as $record) {
            if (!$canmanage && $record->status !== di_summary_service::STATUS_APPROVED) {
                continue;
            }
            $summaries[] = ['lang' => $record->lang, 'content' => $record->content, 'status' => $record->status];
        }

        return ['summaries' => $summaries];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'summaries' => new external_multiple_structure(
                new external_single_structure([
                    'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                    'content' => new external_value(PARAM_RAW, 'Summary text'),
                    'status' => new external_value(PARAM_ALPHA, 'pending | approved'),
                ]),
                'DI summaries visible to the current user'
            ),
        ]);
    }
}
