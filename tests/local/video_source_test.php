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
 * Unit tests for video_source.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for video_source.
 *
 * @covers \mod_playervideo\local\video_source
 */
final class video_source_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that a YouTube video id is extracted from every URL shape a teacher might paste.
     *
     * @return void
     */
    public function test_get_youtube_id_recognises_every_url_shape(): void {
        $expected = 'dQw4w9WgXcQ';

        $this->assertSame($expected, video_source::get_youtube_id('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame($expected, video_source::get_youtube_id('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame($expected, video_source::get_youtube_id('https://www.youtube.com/embed/dQw4w9WgXcQ'));
        $this->assertSame($expected, video_source::get_youtube_id('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertSame(
            $expected,
            video_source::get_youtube_id('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        );
        // Extra query params after the id are ignored.
        $this->assertSame(
            $expected,
            video_source::get_youtube_id('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s')
        );
    }

    /**
     * Tests that an unrecognised URL returns null rather than a wrong/partial id.
     *
     * @return void
     */
    public function test_get_youtube_id_returns_null_for_unrecognised_url(): void {
        $this->assertNull(video_source::get_youtube_id('https://example.com/not-a-video'));
        $this->assertNull(video_source::get_youtube_id(''));
        // A vimeo URL is not a youtube one.
        $this->assertNull(video_source::get_youtube_id('https://vimeo.com/12345678'));
    }

    /**
     * Tests that a Vimeo video id is extracted from both share and player URL shapes.
     *
     * @return void
     */
    public function test_get_vimeo_id_recognises_every_url_shape(): void {
        $this->assertSame('12345678', video_source::get_vimeo_id('https://vimeo.com/12345678'));
        $this->assertSame('12345678', video_source::get_vimeo_id('https://player.vimeo.com/video/12345678'));
    }

    /**
     * Tests that an unrecognised URL returns null.
     *
     * @return void
     */
    public function test_get_vimeo_id_returns_null_for_unrecognised_url(): void {
        $this->assertNull(video_source::get_vimeo_id('https://example.com/not-a-video'));
        $this->assertNull(video_source::get_vimeo_id(''));
    }

    /**
     * Tests that an html5 instance always resolves to the given file URL, regardless of its
     * (irrelevant, for this videotype) videourl field.
     *
     * @return void
     */
    public function test_get_embed_url_html5_returns_the_file_url_unchanged(): void {
        $fileurl = new \moodle_url('/pluginfile.php/1/mod_playervideo/videofile/1/movie.mp4');

        $result = video_source::get_embed_url('html5', null, $fileurl);

        $this->assertSame($fileurl->out(false), $result->out(false));
    }

    /**
     * Tests that a youtube instance resolves to the youtube embed URL for its extracted id.
     *
     * @return void
     */
    public function test_get_embed_url_youtube_resolves_embed_url(): void {
        $result = video_source::get_embed_url('youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', null);

        $this->assertNotNull($result);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $result->out(false));
    }

    /**
     * Tests that a vimeo instance resolves to the vimeo player URL for its extracted id.
     *
     * @return void
     */
    public function test_get_embed_url_vimeo_resolves_embed_url(): void {
        $result = video_source::get_embed_url('vimeo', 'https://vimeo.com/12345678', null);

        $this->assertNotNull($result);
        $this->assertSame('https://player.vimeo.com/video/12345678', $result->out(false));
    }

    /**
     * Tests that an unrecognisable youtube/vimeo URL resolves to null rather than a broken
     * embed URL — never null-coalesced into a source with no video id at all.
     *
     * @return void
     */
    public function test_get_embed_url_returns_null_for_unrecognised_url(): void {
        $this->assertNull(video_source::get_embed_url('youtube', 'https://example.com/nope', null));
        $this->assertNull(video_source::get_embed_url('vimeo', 'https://example.com/nope', null));
    }

    /**
     * Tests that a youtube/vimeo instance with no stored URL at all resolves to null, rather
     * than passing null into the regex matcher.
     *
     * @return void
     */
    public function test_get_embed_url_returns_null_when_videourl_is_null(): void {
        $this->assertNull(video_source::get_embed_url('youtube', null, null));
        $this->assertNull(video_source::get_embed_url('vimeo', null, null));
    }
}
