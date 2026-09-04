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
 * Native <video> adapter for an uploaded (HTML5) source, exposing the same interface as
 * player_youtube/player_vimeo so amd/src/player.js can drive any of the three sources
 * uniformly: ready(), play(), pause(), seek(seconds), getCurrentTime(), getDuration(),
 * onTimeUpdate(callback), onEnded(callback), getCaptionTracks(), setCaptionTrack(code),
 * setRate(rate), getRate().
 *
 * There is no "native" caption source for an uploaded video — there is no "YouTube of the video
 * itself" to mirror — so getCaptionTracks() always resolves empty and setCaptionTrack() is a
 * no-op: every caption for this source is a manual one, rendered by player.js's own overlay.
 *
 * Native controls are left off: the plugin's own timeline bar is the only scrub/play surface,
 * so a second, redundant progress bar never stacks under the browser's own.
 *
 * @module     mod_playervideo/player_html5
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Replaces the placeholder element with a real <video> tag pointed at embedUrl.
 *
 * @param {string} targetId Id of the placeholder element to replace.
 * @param {string} embedUrl Direct pluginfile URL of the uploaded video.
 * @returns {Promise<object>} The player interface.
 */
export const createPlayer = async(targetId, embedUrl) => {
    const target = document.getElementById(targetId);

    const video = document.createElement('video');
    video.id = targetId;
    video.className = 'ph-video-embed';
    video.src = embedUrl;
    target.replaceWith(video);

    return {
        ready: () => new Promise((resolve) => {
            if (video.readyState >= 1) {
                resolve();
                return;
            }
            video.addEventListener('loadedmetadata', () => resolve(), {once: true});
        }),
        play: () => video.play(),
        pause: () => video.pause(),
        seek: (seconds) => {
            video.currentTime = seconds;
        },
        getCurrentTime: () => Promise.resolve(video.currentTime),
        getDuration: () => Promise.resolve(video.duration || 0),
        onTimeUpdate: (callback) => video.addEventListener('timeupdate', () => callback(video.currentTime)),
        onEnded: (callback) => video.addEventListener('ended', () => callback()),
        getCaptionTracks: () => Promise.resolve([]),
        setCaptionTrack: () => { /* No native source to select a track on. */ },
        setRate: (rate) => {
            video.playbackRate = rate;
        },
        getRate: () => Promise.resolve(video.playbackRate),
    };
};
