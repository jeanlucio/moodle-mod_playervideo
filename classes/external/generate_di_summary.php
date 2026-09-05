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
 * External function to generate an easy-read (DI) summary by AI, from a caption's text.
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
use mod_playervideo\local\ai_service;
use mod_playervideo\local\caption_service;
use mod_playervideo\local\di_summary_service;
use moodle_exception;

/**
 * Generates an easy-read summary, in the style of "Information for All"/"Informação para Todos"
 * plain-language guidelines, from an existing caption's text — for students with intellectual
 * disability (DI). The result always starts pending: it is never shown to a student until a
 * teacher reviews and approves it via {@see save_di_summary}.
 */
class generate_di_summary extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'lang' => new external_value(PARAM_RAW, 'Language code of the source caption, e.g. en, pt-br'),
        ]);
    }

    /**
     * Generates the easy-read summary.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code of the source caption.
     * @return array The generated summary, pending review.
     */
    public static function execute(int $playervideoid, string $lang): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'lang' => $lang,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:manage', $context);

        $lang = strtolower(trim($params['lang']));
        if ($lang === '' || !preg_match('/^[a-z0-9_-]+$/', $lang)) {
            throw new moodle_exception('error_invalidlang', 'mod_playervideo');
        }

        $caption = null;
        foreach (caption_service::get_captions($params['playervideoid']) as $record) {
            if ($record->lang === $lang) {
                $caption = $record;
                break;
            }
        }
        if ($caption === null) {
            throw new moodle_exception('error_nocaptionforlanguage', 'mod_playervideo');
        }

        $sourcetext = caption_service::extract_plain_text($caption->content);
        if ($sourcetext === '') {
            throw new moodle_exception('error_nocaptionforlanguage', 'mod_playervideo');
        }

        if (!ai_service::has_ai_source($context)) {
            throw new moodle_exception('error_noaisource', 'mod_playervideo');
        }

        $prompt = self::build_prompt($sourcetext);
        $description = get_string('aiusage_disummary', 'mod_playervideo');
        $result = ai_service::generate($prompt, $description, $context, false);

        if (empty($result['success']) || trim((string) ($result['data'] ?? '')) === '') {
            throw new moodle_exception('error_aigenerate', 'mod_playervideo');
        }

        $content = trim((string) $result['data']);
        di_summary_service::save_generated($params['playervideoid'], $lang, $content);

        return ['lang' => $lang, 'content' => $content, 'status' => di_summary_service::STATUS_PENDING];
    }

    /**
     * Builds the easy-read summary prompt.
     *
     * @param string $sourcetext Plain text extracted from the source caption.
     * @return string The prompt text.
     */
    private static function build_prompt(string $sourcetext): string {
        return implode("\n", [
            'You are writing an easy-read (plain-language) summary of an educational video for a '
                . 'student with an intellectual disability.',
            'Follow "Information for All" easy-read guidelines: short sentences, one idea per '
                . 'sentence, common everyday words, no jargon, no figures of speech, active voice.',
            'Write the summary in the same language as the source text below.',
            'Reply with the summary text only — no title, no markdown, no preamble.',
            '--- SOURCE TEXT ---',
            $sourcetext,
        ]);
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
            'content' => new external_value(PARAM_RAW, 'Generated summary text'),
            'status' => new external_value(PARAM_ALPHA, 'Always "pending" right after generation'),
        ]);
    }
}
