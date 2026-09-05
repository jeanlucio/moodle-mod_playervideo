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
 * External function for a teacher to edit, approve or delete a DI easy-read summary.
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
use mod_playervideo\local\di_summary_service;
use moodle_exception;

/**
 * The teacher's review step for a DI summary: edit its text and/or approve it in one action, or
 * delete it outright. This is the only path that ever sets status to 'approved' — a summary is
 * never shown to a student until a teacher explicitly calls this with approved=true.
 */
class save_di_summary extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'lang' => new external_value(PARAM_RAW, 'Language code, e.g. en, pt-br'),
            'content' => new external_value(PARAM_RAW, 'Summary text', VALUE_DEFAULT, ''),
            'approved' => new external_value(PARAM_BOOL, 'Whether to mark this summary approved', VALUE_DEFAULT, false),
            'delete' => new external_value(PARAM_BOOL, 'Whether to delete this language instead of saving', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Saves or deletes a DI summary.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code.
     * @param string $content Summary text.
     * @param bool $approved Whether to mark this summary approved.
     * @param bool $delete Whether to delete this language instead of saving.
     * @return array The language saved/deleted, and its resulting status.
     */
    public static function execute(
        int $playervideoid,
        string $lang,
        string $content,
        bool $approved,
        bool $delete
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'lang' => $lang,
            'content' => $content,
            'approved' => $approved,
            'delete' => $delete,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:manage', $context);

        $lang = strtolower(trim($params['lang']));
        if ($lang === '' || !preg_match('/^[a-z0-9_-]+$/', $lang)) {
            throw new moodle_exception('error_invalidlang', 'mod_playervideo');
        }

        if ($params['delete']) {
            $deleted = di_summary_service::delete_summary($params['playervideoid'], $lang);
            if (!$deleted) {
                throw new moodle_exception('error_disummarynotfound', 'mod_playervideo');
            }
            return ['lang' => $lang, 'status' => '', 'deleted' => true];
        }

        if (trim($params['content']) === '') {
            throw new moodle_exception('error_disummarycontentrequired', 'mod_playervideo');
        }

        di_summary_service::save_reviewed($params['playervideoid'], $lang, $params['content'], $params['approved']);

        $status = $params['approved'] ? di_summary_service::STATUS_APPROVED : di_summary_service::STATUS_PENDING;
        return ['lang' => $lang, 'status' => $status, 'deleted' => false];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language code saved/deleted'),
            'status' => new external_value(PARAM_ALPHA, 'Resulting status ("" when deleted)', VALUE_DEFAULT, ''),
            'deleted' => new external_value(PARAM_BOOL, 'Whether this call deleted the language instead of saving'),
        ]);
    }
}
