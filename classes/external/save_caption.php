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
 * External function to create, update or delete one language's manual caption.
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
use mod_playervideo\local\caption_service;
use moodle_exception;

/**
 * Creates, updates or deletes one playervideo_captions row (one language of one instance).
 *
 * The teacher's caption editor and the "use this transcript as caption too" synergy in the
 * batch question generator both call this same endpoint — one save path, no special case for
 * where the pasted text came from.
 */
class save_caption extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            // PARAM_RAW on purpose: PARAM_ALPHANUMEXT's strict validate-vs-clean check would
            // reject a value with surrounding whitespace outright, before execute() ever gets a
            // chance to trim/lowercase it — the charset itself is still enforced below, just
            // after normalising instead of before.
            'lang' => new external_value(PARAM_RAW, 'Language code, e.g. en, pt-br'),
            'content' => new external_value(
                PARAM_RAW,
                'Caption content — real VTT, or plain "timestamp text" lines',
                VALUE_DEFAULT,
                ''
            ),
            'delete' => new external_value(PARAM_BOOL, 'Whether to delete this language instead of saving', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Creates, updates or deletes one language's caption.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code, e.g. 'en', 'pt-br'.
     * @param string $content Caption content — real VTT, or plain "timestamp text" lines.
     * @param bool $delete Whether to delete this language instead of saving.
     * @return array The language saved/deleted.
     */
    public static function execute(int $playervideoid, string $lang, string $content, bool $delete): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'lang' => $lang,
            'content' => $content,
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
            $deleted = caption_service::delete_caption($params['playervideoid'], $lang);
            if (!$deleted) {
                throw new moodle_exception('error_captionnotfound', 'mod_playervideo');
            }
            return ['lang' => $lang, 'deleted' => true];
        }

        if (trim($params['content']) === '') {
            throw new moodle_exception('error_captioncontentrequired', 'mod_playervideo');
        }

        caption_service::save_caption($params['playervideoid'], $lang, $params['content']);

        return ['lang' => $lang, 'deleted' => false];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language code saved/deleted'),
            'deleted' => new external_value(PARAM_BOOL, 'Whether this call deleted the language instead of saving'),
        ]);
    }
}
