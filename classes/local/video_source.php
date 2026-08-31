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
 * Resolves a plain embeddable player URL from the three supported video sources.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use moodle_url;

/**
 * Turns a stored videotype/videourl pair into an embed URL.
 *
 * Only resolves the plain embed src — the interactive player itself (IFrame/Player.js API,
 * anti-skip, captions) is AMD work for the full activity view (see the plugin SCOPE, §16
 * Phase 3). This class only serves the simple inline embed used by cm_info_dynamic() when
 * the teacher pins the video to the course page.
 */
class video_source {
    /**
     * Extracts the YouTube video id from a watch/share/embed URL, or null if not recognised.
     *
     * @param string $url The URL as typed by the teacher.
     * @return string|null
     */
    public static function get_youtube_id(string $url): ?string {
        $pattern = '#(?:youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#';
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extracts the Vimeo video id from a share/player URL, or null if not recognised.
     *
     * @param string $url The URL as typed by the teacher.
     * @return string|null
     */
    public static function get_vimeo_id(string $url): ?string {
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Resolves the embeddable player URL for an instance, given its stored source fields.
     *
     * @param string $videotype 'youtube' | 'vimeo' | 'html5'.
     * @param string|null $videourl Stored URL, for youtube/vimeo.
     * @param moodle_url|null $fileurl Resolved pluginfile URL, for html5.
     * @return moodle_url|null Null when the source cannot be resolved (bad/missing URL).
     */
    public static function get_embed_url(string $videotype, ?string $videourl, ?moodle_url $fileurl): ?moodle_url {
        if ($videotype === 'html5') {
            return $fileurl;
        }

        if ($videotype === 'youtube' && $videourl !== null) {
            $id = self::get_youtube_id($videourl);
            return $id !== null ? new moodle_url("https://www.youtube.com/embed/$id") : null;
        }

        if ($videotype === 'vimeo' && $videourl !== null) {
            $id = self::get_vimeo_id($videourl);
            return $id !== null ? new moodle_url("https://player.vimeo.com/video/$id") : null;
        }

        return null;
    }
}
