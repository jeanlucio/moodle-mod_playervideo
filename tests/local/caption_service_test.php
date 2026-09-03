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
 * Unit tests for the manual caption service.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for caption_service.
 *
 * @covers \mod_playervideo\local\caption_service
 */
final class caption_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that content already starting with the WEBVTT header is trusted and stored as-is,
     * unchanged — a teacher pasting a real .vtt file (or re-saving an already-converted caption)
     * must round-trip exactly, never be re-parsed as plain lines.
     *
     * @return void
     */
    public function test_normalise_to_vtt_passes_through_real_vtt_unchanged(): void {
        $vtt = "WEBVTT\n\n00:00:05.000 --> 00:00:10.000\nHello there.\n";

        $this->assertSame($vtt, caption_service::normalise_to_vtt($vtt));
    }

    /**
     * Tests that a leading BOM/whitespace before the WEBVTT header does not defeat the
     * "already VTT" detection.
     *
     * @return void
     */
    public function test_normalise_to_vtt_detects_vtt_past_a_leading_bom(): void {
        $vtt = "\xEF\xBB\xBF  \nWEBVTT\n\n00:00:05.000 --> 00:00:10.000\nHello.\n";

        $result = caption_service::normalise_to_vtt($vtt);

        $this->assertStringStartsWith('WEBVTT', $result);
    }

    /**
     * Tests that plain "timestamp text" lines are converted into a valid VTT document, with
     * cues in timestamp order and each cue bounded by the next cue's start.
     *
     * @return void
     */
    public function test_normalise_to_vtt_converts_plain_lines(): void {
        $raw = "0:05 Introduction.\n0:45 Plants use sunlight.\n1:20 Chlorophyll is green.";

        $vtt = caption_service::normalise_to_vtt($raw);

        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
        $this->assertStringContainsString("00:00:05.000 --> 00:00:45.000\nIntroduction.", $vtt);
        $this->assertStringContainsString("00:00:45.000 --> 00:01:20.000\nPlants use sunlight.", $vtt);
        // The last cue has no following one to bound it — falls back to a fixed cue duration.
        $this->assertStringContainsString("00:01:20.000 --> 00:01:25.000\nChlorophyll is green.", $vtt);
    }

    /**
     * Tests that lines with no recognisable timestamp are skipped rather than guessed at, and
     * that out-of-order pasted lines are still sorted into timestamp order in the output.
     *
     * @return void
     */
    public function test_normalise_to_vtt_skips_lines_without_a_timestamp_and_sorts_the_rest(): void {
        $raw = "This line has no timestamp at all.\n0:45 Second.\n0:05 First.";

        $vtt = caption_service::normalise_to_vtt($raw);

        $this->assertStringNotContainsString('no timestamp at all', $vtt);
        $firstpos = strpos($vtt, 'First.');
        $secondpos = strpos($vtt, 'Second.');
        $this->assertNotFalse($firstpos);
        $this->assertNotFalse($secondpos);
        $this->assertLessThan($secondpos, $firstpos);
    }

    /**
     * Tests parse_line_timestamp() against every format the batch question generator also
     * relies on (mm:ss, h:mm:ss, bare "45s"), since both features share this exact method.
     *
     * @return void
     */
    public function test_parse_line_timestamp_recognises_common_formats(): void {
        $this->assertSame(5, caption_service::parse_line_timestamp('0:05 First line.'));
        $this->assertSame(3723, caption_service::parse_line_timestamp('01:02:03 Second line.'));
        $this->assertSame(45, caption_service::parse_line_timestamp('Third line at 45s.'));
        $this->assertNull(caption_service::parse_line_timestamp('No timestamp here.'));
    }

    /**
     * Tests the full save/get/delete cycle against the real database.
     *
     * @return void
     */
    public function test_save_get_and_delete_caption_cycle(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);

        caption_service::save_caption($instance->id, 'en', '0:05 Hello.');

        $captions = caption_service::get_captions($instance->id);
        $this->assertCount(1, $captions);
        $this->assertSame('en', $captions[0]->lang);
        $this->assertSame('manual', $captions[0]->source);
        $this->assertStringStartsWith('WEBVTT', $captions[0]->content);

        // Saving the same language again updates the existing row rather than duplicating it —
        // the UNIQUE(playervideoid, lang) index in install.xml depends on this being an upsert.
        caption_service::save_caption($instance->id, 'en', '0:10 Updated.');
        $captions = caption_service::get_captions($instance->id);
        $this->assertCount(1, $captions);
        $this->assertStringContainsString('Updated.', $captions[0]->content);

        $this->assertTrue(caption_service::delete_caption($instance->id, 'en'));
        $this->assertCount(0, caption_service::get_captions($instance->id));
        $this->assertFalse($DB->record_exists('playervideo_captions', ['playervideoid' => $instance->id]));
    }

    /**
     * Tests that deleting a language with no existing caption is reported, not silently
     * accepted — the caller (save_caption WS) uses this to throw a clear error instead.
     *
     * @return void
     */
    public function test_delete_caption_returns_false_when_nothing_to_delete(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);

        $this->assertFalse(caption_service::delete_caption($instance->id, 'en'));
    }
}
