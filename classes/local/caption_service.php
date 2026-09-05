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
 * Reads, writes and normalises manually authored caption tracks (playervideo_captions).
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * One instance's manually authored captions: parsing/normalising to VTT, and CRUD against
 * playervideo_captions. Never touches a provider's own native tracks (YouTube tracklist, Vimeo
 * text tracks) — those are read live, client-side, by the player adapters; this class only ever
 * knows about the rows the plugin itself stores.
 */
final class caption_service {
    /** @var int Fallback cue duration, in seconds, for a cue with no following cue to bound it. */
    private const DEFAULT_CUE_DURATION = 5;

    /**
     * Returns every manually authored caption for an instance.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return \stdClass[] Rows of playervideo_captions, ordered by language code.
     */
    public static function get_captions(int $playervideoid): array {
        global $DB;

        return array_values($DB->get_records('playervideo_captions', ['playervideoid' => $playervideoid], 'lang ASC'));
    }

    /**
     * Creates or replaces the caption for one language of an instance.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code (e.g. 'en', 'pt-br'), already lowercased/trimmed.
     * @param string $rawcontent Pasted content — real VTT, or plain "timestamp text" lines.
     * @return void
     */
    public static function save_caption(int $playervideoid, string $lang, string $rawcontent): void {
        global $DB;

        $vtt = self::normalise_to_vtt($rawcontent);
        $existing = $DB->get_record('playervideo_captions', ['playervideoid' => $playervideoid, 'lang' => $lang]);

        if ($existing) {
            $DB->update_record('playervideo_captions', (object) [
                'id' => $existing->id,
                'content' => $vtt,
                'timemodified' => time(),
            ]);
        } else {
            $DB->insert_record('playervideo_captions', (object) [
                'playervideoid' => $playervideoid,
                'lang' => $lang,
                'source' => 'manual',
                'content' => $vtt,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Deletes one language's caption from an instance.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $lang Language code, already lowercased/trimmed.
     * @return bool Whether a row was actually deleted.
     */
    public static function delete_caption(int $playervideoid, string $lang): bool {
        global $DB;

        $existing = $DB->get_record('playervideo_captions', ['playervideoid' => $playervideoid, 'lang' => $lang]);
        if (!$existing) {
            return false;
        }
        $DB->delete_records('playervideo_captions', ['id' => $existing->id]);
        return true;
    }

    /**
     * Normalises pasted content into a valid VTT document.
     *
     * Content that already looks like VTT (starts with the "WEBVTT" header, after stripping a
     * leading BOM/whitespace) is trusted and stored as-is — a teacher pasting a real .vtt file
     * round-trips unchanged, including on a later edit of an already-saved caption. Anything else
     * is treated as one "timestamp text" entry per line (the same permissive format already
     * accepted by the batch question generator's transcript field — see
     * {@see \mod_playervideo\external\generate_questions_batch}) and converted to cue blocks.
     *
     * @param string $rawcontent Pasted content.
     * @return string A valid VTT document.
     */
    public static function normalise_to_vtt(string $rawcontent): string {
        $trimmed = ltrim($rawcontent, "\xEF\xBB\xBF \t\r\n");
        if (preg_match('/^WEBVTT/i', $trimmed)) {
            return $trimmed;
        }
        return self::build_vtt_from_lines($rawcontent);
    }

    /**
     * Extracts the timestamp (in seconds) a single line of pasted text declares, if any.
     *
     * Shared by the batch question generator's transcript anchoring, so both features recognise
     * exactly the same set of "reasonable" timestamp formats — mm:ss, h:mm:ss, or a bare "12s".
     *
     * @param string $line One line of pasted text.
     * @return int|null Timestamp in seconds, or null if the line has none.
     */
    public static function parse_line_timestamp(string $line): ?int {
        if (preg_match('/(?:(\d+):)?(\d{1,2}):(\d{2})/', $line, $matches)) {
            $hours = $matches[1] !== '' ? (int) $matches[1] : 0;
            return $hours * 3600 + ((int) $matches[2]) * 60 + (int) $matches[3];
        }
        if (preg_match('/\b(\d+)\s*s\b/i', $line, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Builds a VTT document from "timestamp text" lines.
     *
     * @param string $rawcontent Pasted content, one entry per line.
     * @return string A valid VTT document.
     */
    private static function build_vtt_from_lines(string $rawcontent): string {
        $cues = [];
        foreach (explode("\n", $rawcontent) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $start = self::parse_line_timestamp($line);
            if ($start === null) {
                // A line without a recognisable timestamp is skipped, never guessed at.
                continue;
            }
            $text = trim(self::strip_timestamp_token($line));
            if ($text === '') {
                continue;
            }
            $cues[] = ['start' => $start, 'text' => $text];
        }

        // Multiple lines can legitimately share the same second (fast dialogue); a stable sort
        // keeps the pasted order for a tie instead of an arbitrary one.
        usort($cues, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $vtt = "WEBVTT\n\n";
        foreach ($cues as $index => $cue) {
            $end = $cues[$index + 1]['start'] ?? ($cue['start'] + self::DEFAULT_CUE_DURATION);
            if ($end <= $cue['start']) {
                $end = $cue['start'] + self::DEFAULT_CUE_DURATION;
            }
            $vtt .= self::format_vtt_timestamp($cue['start']) . ' --> ' . self::format_vtt_timestamp($end) . "\n";
            $vtt .= $cue['text'] . "\n\n";
        }

        return rtrim($vtt) . "\n";
    }

    /**
     * Removes the first timestamp token found in a line, leaving only its text.
     *
     * @param string $line One line of pasted text.
     * @return string The line with its timestamp token removed.
     */
    private static function strip_timestamp_token(string $line): string {
        $stripped = preg_replace('/(?:(\d+):)?(\d{1,2}):(\d{2})/', '', $line, 1);
        if ($stripped === $line) {
            $stripped = preg_replace('/\b\d+\s*s\b/i', '', $line, 1);
        }
        return $stripped ?? $line;
    }

    /**
     * Formats a number of seconds as a VTT timestamp (HH:MM:SS.mmm).
     *
     * @param int $totalseconds Timestamp in seconds.
     * @return string VTT-formatted timestamp.
     */
    private static function format_vtt_timestamp(int $totalseconds): string {
        $hours = intdiv($totalseconds, 3600);
        $minutes = intdiv($totalseconds % 3600, 60);
        $seconds = $totalseconds % 60;
        return sprintf('%02d:%02d:%02d.000', $hours, $minutes, $seconds);
    }

    /**
     * Parses a VTT document into a flat list of cues.
     *
     * Deliberately minimal — cue identifiers and styling are ignored, a cue is exactly
     * {start, end, text} — mirroring the client-side parser in amd/src/player.js, since this
     * only ever reads back what this class itself wrote or passed through, never an arbitrary
     * third-party file. Shared by {@see extract_plain_text()} and by
     * {@see \mod_playervideo\local\transcript_service}, which needs real cue boundaries (not
     * just concatenated text) to interleave captions with interactions by timestamp.
     *
     * @param string $vtt A VTT document.
     * @return array Cues, in file order — each shaped {start: float, end: float, text: string}.
     */
    public static function parse_cues(string $vtt): array {
        $timelinepattern = '/(\d{2}):(\d{2}):(\d{2})[.,](\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})[.,](\d{3})/';
        $toseconds = static fn(string $h, string $m, string $s, string $ms): float =>
            ((int) $h * 3600) + ((int) $m * 60) + (int) $s + ((int) $ms / 1000);

        $cues = [];
        foreach (preg_split('/\n\n+/', str_replace("\r", '', $vtt)) as $block) {
            $lines = array_values(array_filter(explode("\n", $block), static fn(string $line): bool => trim($line) !== ''));
            $timelineindex = null;
            foreach ($lines as $index => $line) {
                if (preg_match($timelinepattern, $line)) {
                    $timelineindex = $index;
                    break;
                }
            }
            if ($timelineindex === null) {
                continue;
            }
            preg_match($timelinepattern, $lines[$timelineindex], $matches);
            $text = trim(implode(' ', array_slice($lines, $timelineindex + 1)));
            if ($text === '') {
                continue;
            }
            $cues[] = [
                'start' => $toseconds($matches[1], $matches[2], $matches[3], $matches[4]),
                'end' => $toseconds($matches[5], $matches[6], $matches[7], $matches[8]),
                'text' => $text,
            ];
        }
        return $cues;
    }

    /**
     * Extracts the plain concatenated text of a VTT document, discarding all timing — the
     * source text for the DI easy-read summary prompt (see
     * {@see \mod_playervideo\external\generate_di_summary}), which only needs the narrative
     * content, not when each line was said.
     *
     * @param string $vtt A VTT document.
     * @return string Every cue's text, joined by a space.
     */
    public static function extract_plain_text(string $vtt): string {
        $texts = array_map(static fn(array $cue): string => $cue['text'], self::parse_cues($vtt));
        return trim(implode(' ', $texts));
    }
}
