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
 * Tracks which seconds of the video have actually been watched, and decides whether a forward
 * seek is allowed — the client-side half of the anti-skip mechanic (see the plugin SCOPE,
 * "Anti-skip"). A jump larger than JUMP_THRESHOLD_SECONDS is never counted as watched, mirroring
 * the same rule already used by format_streaming.
 *
 * Playback speed (Fase 7, see the plugin SCOPE) never needed a change here: every adapter's
 * getCurrentTime()/onTimeUpdate() already reports the actual video position, not wall-clock
 * time, so a delta between two ticks is always measured in video-seconds regardless of the
 * rate the student chose — a 3-second jump per tick at 2x playback is still only ~1.5 real
 * seconds of polling, safely under JUMP_THRESHOLD_SECONDS either way. Verified live at 2x
 * (the fastest preset offered) as part of closing Fase 7, not just assumed from the APIs' docs.
 *
 * @module     mod_playervideo/progress_tracker
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {number} Forward jumps up to this many seconds still count as continuously watched. */
const JUMP_THRESHOLD_SECONDS = 3;

/** @var {number} Tolerance, in seconds, when checking whether a target time was already watched. */
const WATCHED_TOLERANCE_SECONDS = 1;

/**
 * Merges a new [start, end] range into an already-sorted, already-merged list of ranges.
 *
 * @param {Array<Array<number>>} segments Existing merged ranges, sorted by start.
 * @param {number} start Range start, in seconds.
 * @param {number} end Range end, in seconds.
 * @returns {Array<Array<number>>} The updated, still-merged list.
 */
const mergeSegment = (segments, start, end) => {
    const merged = [];
    let inserted = false;
    let currentstart = start;
    let currentend = end;

    segments.forEach((segment) => {
        const [segmentstart, segmentend] = segment;
        if (segmentend < currentstart - WATCHED_TOLERANCE_SECONDS) {
            merged.push(segment);
        } else if (segmentstart > currentend + WATCHED_TOLERANCE_SECONDS) {
            if (!inserted) {
                merged.push([currentstart, currentend]);
                inserted = true;
            }
            merged.push(segment);
        } else {
            currentstart = Math.min(currentstart, segmentstart);
            currentend = Math.max(currentend, segmentend);
        }
    });

    if (!inserted) {
        merged.push([currentstart, currentend]);
    }

    return merged;
};

/**
 * Creates a progress tracker for one playback session.
 *
 * @param {Array<Array<number>>} initialSegments Previously saved watched ranges, if resuming.
 * @returns {object} The tracker interface.
 */
export const createTracker = (initialSegments) => {
    let segments = Array.isArray(initialSegments) ? initialSegments : [];
    let lastTime = segments.length > 0 ? segments[segments.length - 1][1] : 0;

    /**
     * Whether a given point in the video already falls inside a watched range.
     *
     * @param {number} time Video position, in seconds.
     * @returns {boolean}
     */
    const isWatched = (time) => segments.some(
        ([start, end]) => time >= start - WATCHED_TOLERANCE_SECONDS && time <= end + WATCHED_TOLERANCE_SECONDS
    );

    return {
        /**
         * Records the player having reached currentTime, extending the watched ranges when the
         * movement since the last recorded position is small enough to count as continuous
         * playback (not a skip).
         *
         * @param {number} currentTime Current playback position, in seconds.
         */
        recordProgress: (currentTime) => {
            const delta = currentTime - lastTime;
            if (delta > 0 && delta <= JUMP_THRESHOLD_SECONDS) {
                segments = mergeSegment(segments, lastTime, currentTime);
            }
            lastTime = currentTime;
        },

        /**
         * Whether moving from the current position to targetTime should be allowed — either a
         * genuine seek (the scrubber, a "seek ahead" keyboard shortcut) or just the next regular
         * timeupdate tick of ordinary playback, which this must NOT mistake for a skip: a forward
         * move of at most JUMP_THRESHOLD_SECONDS is always allowed, exactly like recordProgress()
         * still counts it as continuously watched. Only a jump bigger than that is checked against
         * the watched history, and only then blocked if the target was never reached before.
         *
         * @param {number} targetTime Requested position, in seconds.
         * @param {boolean} allowSeekAhead Whether the instance disabled the anti-skip restriction.
         * @returns {boolean}
         */
        canSeekTo: (targetTime, allowSeekAhead) => {
            if (allowSeekAhead || targetTime <= lastTime) {
                return true;
            }
            if (targetTime - lastTime <= JUMP_THRESHOLD_SECONDS) {
                return true;
            }
            return isWatched(targetTime);
        },

        /**
         * Returns the merged watched ranges, ready to persist via mod_playervideo_save_progress.
         *
         * @returns {string} JSON-encoded array of [start, end] ranges.
         */
        getSegmentsJson: () => JSON.stringify(segments),

        /**
         * Returns the last position recordProgress() was called with — the safe point to seek
         * back to when a disallowed forward jump is detected.
         *
         * @returns {number}
         */
        getLastTime: () => lastTime,
    };
};
