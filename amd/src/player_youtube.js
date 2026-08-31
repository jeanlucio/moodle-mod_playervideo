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
 * YouTube IFrame API adapter, exposing the same interface as player_vimeo/player_html5 so
 * amd/src/player.js can drive any of the three sources uniformly: ready(), play(), pause(),
 * seek(seconds), getCurrentTime(), getDuration(), onTimeUpdate(callback), onEnded(callback).
 *
 * The IFrame API has no native timeupdate event, so onTimeUpdate is backed by a short poll
 * started once the player fires its own onReady — the same technique mod_interactivevideo
 * uses (confirmed reading its amd/src/player/yt.js during the plugin's research phase).
 *
 * Native controls are disabled (playerVars.controls = 0): the plugin's own timeline bar is the
 * only scrub/play surface, so a second, redundant progress bar never stacks under YouTube's own.
 * disablekb keeps arrow-key seeking from bypassing that same bar's anti-skip gating.
 *
 * @module     mod_playervideo/player_youtube
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {number} How often, in ms, to poll getCurrentTime() for the onTimeUpdate callbacks. */
const POLL_INTERVAL_MS = 500;

/** @var {Promise|null} Cached IFrame API load promise, shared across every player instance. */
let apiPromise = null;

/**
 * Loads the YouTube IFrame API script once, resolving when window.YT.Player is usable.
 *
 * @returns {Promise<void>}
 */
const loadApi = () => {
    if (apiPromise) {
        return apiPromise;
    }

    apiPromise = new Promise((resolve) => {
        if (window.YT && window.YT.Player) {
            resolve();
            return;
        }

        const previousCallback = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            if (typeof previousCallback === 'function') {
                previousCallback();
            }
            resolve();
        };

        const tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
    });

    return apiPromise;
};

/**
 * Extracts the YouTube video id from an already-resolved embed URL
 * (https://www.youtube.com/embed/{id}), as produced server-side by video_source::get_embed_url().
 *
 * @param {string} embedUrl Resolved embed URL.
 * @returns {string}
 */
const extractVideoId = (embedUrl) => {
    const match = embedUrl.match(/\/embed\/([^/?]+)/);
    return match ? match[1] : '';
};

/**
 * Creates a YouTube IFrame player targeting the given placeholder element.
 *
 * @param {string} targetId Id of the placeholder element the IFrame API replaces in place.
 * @param {string} embedUrl Resolved embed URL, used only to extract the video id.
 * @returns {Promise<object>} The player interface.
 */
export const createPlayer = async(targetId, embedUrl) => {
    await loadApi();

    const videoId = extractVideoId(embedUrl);
    const timeUpdateCallbacks = [];
    const endedCallbacks = [];
    let pollTimer = null;
    let readyResolve;
    const readyPromise = new Promise((resolve) => {
        readyResolve = resolve;
    });

    const ytplayer = new window.YT.Player(targetId, {
        videoId,
        playerVars: {playsinline: 1, controls: 0, disablekb: 1},
        events: {
            onReady: () => {
                pollTimer = window.setInterval(() => {
                    const time = ytplayer.getCurrentTime();
                    timeUpdateCallbacks.forEach((callback) => callback(time));
                }, POLL_INTERVAL_MS);
                readyResolve();
            },
            onStateChange: (event) => {
                if (event.data === window.YT.PlayerState.ENDED) {
                    endedCallbacks.forEach((callback) => callback());
                }
            },
        },
    });

    return {
        ready: () => readyPromise,
        play: () => ytplayer.playVideo(),
        pause: () => ytplayer.pauseVideo(),
        /*
         * Calling seekTo() is a documented no-op while the player is still UNSTARTED/CUED (never
         * played even once) — getCurrentTime() keeps reporting 0 afterwards, silently. Calling
         * playVideo() immediately before it is not enough on its own: the player only actually
         * honours the seek once it has genuinely reached the PLAYING state, which happens
         * asynchronously (buffering), not the instant playVideo() is called. So this waits for
         * the real onStateChange -> PLAYING event before seeking, then pauses again to land on
         * the sought frame without leaving playback running. Once the player has played at least
         * once, seekTo() alone works and this whole branch is skipped.
         *
         * The `handled` guard exists because removeEventListener() is itself an async postMessage
         * call across the iframe boundary: it does not necessarily take effect before a second
         * onStateChange:PLAYING event (fired by our own seekTo() call re-buffering) reaches this
         * same listener, which would otherwise replay the whole seek+pause sequence a second time
         * on the next unrelated play() call.
         */
        seek: (seconds) => new Promise((resolve) => {
            const state = ytplayer.getPlayerState();
            const neverstarted = state === window.YT.PlayerState.UNSTARTED || state === window.YT.PlayerState.CUED;
            if (!neverstarted) {
                ytplayer.seekTo(seconds, true);
                resolve();
                return;
            }
            let handled = false;
            const onPlaying = (event) => {
                if (handled || event.data !== window.YT.PlayerState.PLAYING) {
                    return;
                }
                handled = true;
                ytplayer.removeEventListener('onStateChange', onPlaying);
                ytplayer.seekTo(seconds, true);
                ytplayer.pauseVideo();
                resolve();
            };
            ytplayer.addEventListener('onStateChange', onPlaying);
            ytplayer.playVideo();
        }),
        getCurrentTime: () => Promise.resolve(ytplayer.getCurrentTime()),
        getDuration: () => Promise.resolve(ytplayer.getDuration()),
        onTimeUpdate: (callback) => timeUpdateCallbacks.push(callback),
        onEnded: (callback) => endedCallbacks.push(callback),
        destroy: () => window.clearInterval(pollTimer),
    };
};
