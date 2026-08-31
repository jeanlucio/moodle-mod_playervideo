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
 * Vimeo Player.js adapter, exposing the same interface as player_youtube/player_html5 so
 * amd/src/player.js can drive any of the three sources uniformly: ready(), play(), pause(),
 * seek(seconds), getCurrentTime(), getDuration(), onTimeUpdate(callback), onEnded(callback).
 *
 * Unlike the YouTube adapter, Player.js exposes a native 'timeupdate' event, so no polling
 * is needed here.
 *
 * Native controls are disabled (controls: false): the plugin's own timeline bar is the only
 * scrub/play surface, so a second, redundant progress bar never stacks under Vimeo's own.
 *
 * @module     mod_playervideo/player_vimeo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {Promise|null} Cached Player.js load promise, shared across every player instance. */
let apiPromise = null;

/**
 * Loads the Vimeo Player.js script once, resolving when window.Vimeo.Player is usable.
 *
 * @returns {Promise<void>}
 */
const loadApi = () => {
    if (apiPromise) {
        return apiPromise;
    }

    apiPromise = new Promise((resolve) => {
        if (window.Vimeo && window.Vimeo.Player) {
            resolve();
            return;
        }

        const tag = document.createElement('script');
        tag.src = 'https://player.vimeo.com/api/player.js';
        tag.addEventListener('load', () => resolve());
        document.head.appendChild(tag);
    });

    return apiPromise;
};

/**
 * Creates a Vimeo Player.js instance targeting the given placeholder element.
 *
 * @param {string} targetId Id of the placeholder element Player.js replaces in place.
 * @param {string} embedUrl Resolved embed URL (https://player.vimeo.com/video/{id}).
 * @returns {Promise<object>} The player interface.
 */
export const createPlayer = async(targetId, embedUrl) => {
    await loadApi();

    const vimeoplayer = new window.Vimeo.Player(targetId, {url: embedUrl, controls: false});
    await vimeoplayer.ready();

    return {
        ready: () => Promise.resolve(),
        play: () => vimeoplayer.play(),
        pause: () => vimeoplayer.pause(),
        seek: (seconds) => vimeoplayer.setCurrentTime(seconds),
        getCurrentTime: () => vimeoplayer.getCurrentTime(),
        getDuration: () => vimeoplayer.getDuration(),
        onTimeUpdate: (callback) => vimeoplayer.on('timeupdate', (data) => callback(data.seconds)),
        onEnded: (callback) => vimeoplayer.on('ended', () => callback()),
    };
};
