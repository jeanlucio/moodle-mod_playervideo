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
 * Builds the linear text-only document for the transcript alternate route.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use context;

/**
 * Merges a caption's text with the activity's interactions, in timestamp order, into one linear
 * document — text, text, question, text, ... — for a student navigating with a screen reader
 * (see the plugin SCOPE, "Modo texto-only"). A first-class alternate route (transcript.php), not
 * an adaptation of the video screen: no player, no timeline, no anti-skip, since there is no
 * video position to protect here — the student already has the full document in front of them.
 */
final class transcript_service {
    /**
     * Builds the ordered list of blocks for one instance.
     *
     * @param \stdClass $instance The playervideo instance record.
     * @param context $context The activity's context, for formatting stored text.
     * @return array Blocks in timestamp order, each shaped either
     *      {kind: 'text', timestamp, text} or
     *      {kind: 'interaction', timestamp, id, type, notetext, question, polloptions}.
     */
    public static function build_document(\stdClass $instance, context $context): array {
        global $DB;

        $blocks = [];
        foreach (self::pick_caption_cues((int) $instance->id) as $cue) {
            $blocks[] = [
                'kind' => 'text',
                'timestamp' => $cue['start'],
                'text' => $cue['text'],
            ];
        }

        $interactionrecords = $DB->get_records(
            'playervideo_interactions',
            ['playervideoid' => $instance->id],
            'timestamp ASC'
        );
        $pollinteractionids = array_values(array_filter(array_map(
            static fn($record) => $record->type === 'poll' ? (int) $record->id : null,
            $interactionrecords
        )));
        $polloptionsbyinteraction = self::get_poll_options($pollinteractionids);

        $questionids = array_values(array_filter(array_map(
            static fn($record) => $record->type === 'question' ? (int) $record->questionid : null,
            $interactionrecords
        )));
        $questionsbyid = question_service::get_questions_for_frontend($questionids, $context);

        foreach ($interactionrecords as $record) {
            $question = null;
            if ($record->type === 'question' && $record->questionid !== null) {
                $question = $questionsbyid[(int) $record->questionid] ?? null;
            }
            $blocks[] = [
                'kind' => 'interaction',
                'timestamp' => (float) $record->timestamp,
                'id' => (int) $record->id,
                'type' => $record->type,
                'notetext' => $record->type !== 'question'
                    ? format_text($record->notetext ?? '', $record->notetextformat, ['context' => $context])
                    : '',
                'question' => $question,
                'polloptions' => $polloptionsbyinteraction[$record->id] ?? [],
            ];
        }

        usort($blocks, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        return $blocks;
    }

    /**
     * Picks which caption to use as the document's narrative text: the one matching the current
     * user's language, or the first available alphabetically, or none at all — this mode still
     * works with zero captions, it just has no text blocks between interactions.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array Cues from the chosen caption, or an empty array if none exists.
     */
    private static function pick_caption_cues(int $playervideoid): array {
        $captions = caption_service::get_captions($playervideoid);
        if (empty($captions)) {
            return [];
        }

        $currentlang = current_language();
        $chosen = null;
        foreach ($captions as $caption) {
            if ($caption->lang === $currentlang) {
                $chosen = $caption;
                break;
            }
        }
        $chosen ??= reset($captions);

        return caption_service::parse_cues($chosen->content);
    }

    /**
     * Returns every poll option for the given interaction ids, grouped by interaction id, in a
     * single query — mirrors the identical helper in
     * {@see \mod_playervideo\external\get_interactions}.
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
}
