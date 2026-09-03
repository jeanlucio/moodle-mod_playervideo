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
 * Shared AI routing for PlayerVideo (question generation, open-answer correction, DI summary).
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use context;

/**
 * Thin routing layer over local_aihub (BYOK) with a core_ai fallback, shared by every AI-backed
 * mechanic in this plugin — question generation (point-to-point and batch), open-answer
 * correction suggestions, and easy-read summaries for students with intellectual disabilities.
 *
 * Deliberately holds no prompt-building or response-parsing logic of its own: each caller owns
 * its own prompt and is responsible for treating the returned text as untrusted (validating
 * structure/JSON before using it), per the AI features rules in the project's CLAUDE.md. Mirrors
 * the routing half of {@see \mod_playerwords\local\ai_word_generator}, generalised because this
 * plugin has more than one AI task instead of just one.
 */
class ai_service {
    /**
     * Returns true when an AI source (hub key or core_ai) is available for the given context.
     *
     * local_aihub is a site-wide BYOK service with no per-course scoping of its own, so only the
     * core_ai path needs the caller's context to honour a course/module-level "Enable AI tools"
     * override.
     *
     * @param context $context Context of the activity checking availability.
     * @return bool
     */
    public static function has_ai_source(context $context): bool {
        if (class_exists(\local_aihub\ai::class) && \local_aihub\ai::is_available()) {
            return true;
        }
        return self::has_core_ai($context);
    }

    /**
     * Generates text, routing to local_aihub first and falling back to core_ai.
     *
     * @param string $prompt The full prompt text.
     * @param string $description Short label of what is being generated, for the hub usage log.
     * @param context $context Context of the activity requesting generation.
     * @param bool $jsonmode Whether to request structured JSON output from local_aihub.
     * @return array Keys: success (bool), data (string), message (string), provider (string).
     */
    public static function generate(
        string $prompt,
        string $description,
        context $context,
        bool $jsonmode = true
    ): array {
        $lasterror = ['success' => false, 'message' => '', 'data' => '', 'provider' => ''];

        if (class_exists(\local_aihub\ai::class)) {
            $result = \local_aihub\ai::generate_text('', $prompt, $jsonmode, 'mod_playervideo', $description);
            if (!empty($result['success'])) {
                return $result;
            }
            // Preserve a real failure (e.g. an invalid key) so it is not masked as "no source".
            if (!empty($result['message'])) {
                $lasterror = $result;
            }
        }

        if (self::has_core_ai($context)) {
            $result = self::call_core_ai($prompt, $context);
            if ($result['success'] || !empty($result['message'])) {
                return $result;
            }
        }

        return $lasterror;
    }

    /**
     * Returns true when the Moodle core_ai subsystem has a text-generation provider available
     * and, on Moodle versions that support it, not disabled for this context.
     *
     * @param context $context Context of the activity checking availability.
     * @return bool
     */
    protected static function has_core_ai(context $context): bool {
        if (
            !class_exists(\core_ai\manager::class)
            || !class_exists(\core_ai\aiactions\generate_text::class)
        ) {
            return false;
        }

        try {
            $actionclass = \core_ai\aiactions\generate_text::class;
            $manager = \core\di::get(\core_ai\manager::class);
            $providers = $manager->get_providers_for_actions([$actionclass], true);
            if (empty($providers[$actionclass])) {
                return false;
            }
            return self::action_enabled_in_context($manager, $context, $actionclass);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Checks the per-course/per-module "Enable AI tools" override, when the running Moodle
     * version supports it.
     *
     * core_ai\manager::is_action_enabled_in_context() was added to Moodle core after 4.5 (this
     * plugin supports 4.5+5.x), so the check is skipped — never blocking — on versions where the
     * method does not exist yet.
     *
     * @param \core_ai\manager $manager The AI manager instance.
     * @param context $context Context of the activity requesting generation.
     * @param string $actionclass Fully qualified AI action class name.
     * @return bool
     */
    protected static function action_enabled_in_context(
        \core_ai\manager $manager,
        context $context,
        string $actionclass
    ): bool {
        if (!method_exists($manager, 'is_action_enabled_in_context')) {
            return true;
        }
        return $manager->is_action_enabled_in_context($context, $actionclass);
    }

    /**
     * Generates text via the Moodle core_ai subsystem (institutional fallback).
     *
     * @param string $prompt The prompt text.
     * @param context $context Context of the activity requesting generation.
     * @return array Result with keys: success (bool), data (string), message (string),
     *      provider (string).
     */
    protected static function call_core_ai(string $prompt, context $context): array {
        global $USER;

        try {
            $actionclass = \core_ai\aiactions\generate_text::class;
            $manager = \core\di::get(\core_ai\manager::class);
            $providers = $manager->get_providers_for_actions([$actionclass], true);

            if (empty($providers[$actionclass]) || !self::action_enabled_in_context($manager, $context, $actionclass)) {
                return ['success' => false, 'message' => '', 'data' => '', 'provider' => ''];
            }

            $action = new \core_ai\aiactions\generate_text(
                contextid: $context->id,
                userid: (int) $USER->id,
                prompttext: $prompt,
            );

            $response = $manager->process_action($action);

            if (!$response->get_success()) {
                return ['success' => false, 'message' => 'core_ai: provider returned failure', 'data' => '', 'provider' => ''];
            }

            $data = $response->get_response_data();
            $content = (string) ($data['generatedcontent'] ?? '');

            if ($content === '') {
                return ['success' => false, 'message' => 'core_ai: empty response', 'data' => '', 'provider' => ''];
            }

            return ['success' => true, 'data' => $content, 'message' => '', 'provider' => 'Moodle AI'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'core_ai: ' . $e->getMessage(), 'data' => '', 'provider' => ''];
        }
    }
}
