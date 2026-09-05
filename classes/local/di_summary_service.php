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
 * Reads and writes the easy-read (DI) summaries of a PlayerVideo instance.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * CRUD for playervideo_disummaries — one AI-generated, teacher-reviewable easy-read summary per
 * language, for students with intellectual disability (DI). Always starts 'pending' after a
 * (re)generation; only a teacher's explicit approval flips it to 'approved', the only status a
 * student is ever shown.
 */
final class di_summary_service {
    /** @var string Status before a teacher has approved the summary. */
    public const STATUS_PENDING = 'pending';

    /** @var string Status once a teacher has approved the summary for students to see. */
    public const STATUS_APPROVED = 'approved';

    /**
     * Returns every DI summary for an instance, regardless of status.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return \stdClass[] Rows of playervideo_disummaries, ordered by language code.
     */
    public static function get_summaries(int $playervideoid): array {
        global $DB;

        return array_values($DB->get_records('playervideo_disummaries', ['playervideoid' => $playervideoid], 'lang ASC'));
    }

    /**
     * Returns one language's DI summary, or null if none exists yet.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code, already lowercased/trimmed.
     * @return \stdClass|null
     */
    public static function get_summary(int $playervideoid, string $lang): ?\stdClass {
        global $DB;

        $record = $DB->get_record('playervideo_disummaries', ['playervideoid' => $playervideoid, 'lang' => $lang]);
        return $record ?: null;
    }

    /**
     * Creates or replaces the DI summary for one language, always resetting it to pending —
     * a fresh AI generation always needs a fresh teacher review, even if a previous version of
     * this same language was already approved.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code, already lowercased/trimmed.
     * @param string $content Summary text.
     * @return void
     */
    public static function save_generated(int $playervideoid, string $lang, string $content): void {
        self::upsert($playervideoid, $lang, $content, self::STATUS_PENDING);
    }

    /**
     * Saves a teacher's edit to a DI summary, setting its status explicitly — the teacher edits
     * the text and/or toggles approval in the same action (see
     * {@see \mod_playervideo\external\save_di_summary}).
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code, already lowercased/trimmed.
     * @param string $content Summary text.
     * @param bool $approved Whether to mark this summary approved.
     * @return void
     */
    public static function save_reviewed(int $playervideoid, string $lang, string $content, bool $approved): void {
        self::upsert($playervideoid, $lang, $content, $approved ? self::STATUS_APPROVED : self::STATUS_PENDING);
    }

    /**
     * Deletes one language's DI summary.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code, already lowercased/trimmed.
     * @return bool Whether a row was actually deleted.
     */
    public static function delete_summary(int $playervideoid, string $lang): bool {
        global $DB;

        $existing = $DB->get_record('playervideo_disummaries', ['playervideoid' => $playervideoid, 'lang' => $lang]);
        if (!$existing) {
            return false;
        }
        $DB->delete_records('playervideo_disummaries', ['id' => $existing->id]);
        return true;
    }

    /**
     * Creates or updates the DI summary row for one language.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code, already lowercased/trimmed.
     * @param string $content Summary text.
     * @param string $status One of the STATUS_* constants.
     * @return void
     */
    private static function upsert(int $playervideoid, string $lang, string $content, string $status): void {
        global $DB;

        $existing = $DB->get_record('playervideo_disummaries', ['playervideoid' => $playervideoid, 'lang' => $lang]);

        if ($existing) {
            $DB->update_record('playervideo_disummaries', (object) [
                'id' => $existing->id,
                'content' => $content,
                'status' => $status,
                'timemodified' => time(),
            ]);
        } else {
            $DB->insert_record('playervideo_disummaries', (object) [
                'playervideoid' => $playervideoid,
                'lang' => $lang,
                'content' => $content,
                'status' => $status,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }
}
