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
 * Resilient auto-save: wraps a mod_playervideo Web Service call so a genuine network failure
 * (not a server-side validation error) is queued in localStorage and retried automatically on
 * the next 'online' event or page load, instead of silently losing the response/heartbeat (see
 * the plugin SCOPE, "Resiliência a queda de conexão" — the localStorage-retry half of it; the
 * sendBeacon-on-close half is deferred, see the SCOPE decision log).
 *
 * @module     mod_playervideo/autosave
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @var {string} localStorage key holding the queued, not-yet-delivered calls. */
const QUEUE_KEY = 'mod_playervideo_retry_queue';

/**
 * Reads the current retry queue, tolerating a missing/corrupt value.
 *
 * @returns {Array<object>}
 */
const readQueue = () => {
    try {
        const raw = window.localStorage.getItem(QUEUE_KEY);
        return raw ? JSON.parse(raw) : [];
    } catch (error) {
        return [];
    }
};

/**
 * Persists the retry queue, tolerating unavailable storage (private browsing, quota).
 *
 * @param {Array<object>} queue Queue to persist.
 */
const writeQueue = (queue) => {
    try {
        window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    } catch (error) {
        // Nothing else to fall back to — the call itself already went out or failed;
        // only the retry bookkeeping is lost, not the response the user just gave.
        return;
    }
};

/**
 * Whether a call_external_function() rejection looks like a network problem rather than a
 * genuine server response — Moodle's own AJAX errors always carry an errorcode, a network
 * failure never reaches the server at all.
 *
 * @param {object} error Rejection from Ajax.call().
 * @returns {boolean}
 */
const isNetworkFailure = (error) => !error || typeof error.errorcode === 'undefined';

/**
 * Calls one mod_playervideo Web Service method, queuing it for automatic retry when the
 * failure looks like a network problem instead of surfacing it as an error.
 *
 * @param {string} methodname Web service method name.
 * @param {object} args Arguments.
 * @returns {Promise<object|null>} The result, or null when queued for later retry.
 */
export const callWithRetry = async(methodname, args) => {
    try {
        return await Ajax.call([{methodname, args}])[0];
    } catch (error) {
        if (!isNetworkFailure(error)) {
            throw error;
        }
        const queue = readQueue();
        queue.push({methodname, args});
        writeQueue(queue);
        return null;
    }
};

/**
 * Retries every queued call, in arrival order. A call that fails again on the network stops
 * the flush (it stays at the front of the queue for the next attempt); any other failure (e.g.
 * the attempt was since finished server-side) can never succeed on retry, so it is dropped
 * instead of blocking the rest of the queue forever.
 *
 * @returns {Promise<void>}
 */
export const flushQueue = async() => {
    let queue = readQueue();

    while (queue.length > 0) {
        const next = queue[0];
        try {
            // Deliberately sequential: later queued calls (e.g. a heartbeat after an answer)
            // may depend on an earlier one having already landed server-side.
            await Ajax.call([{methodname: next.methodname, args: next.args}])[0];
            queue = queue.slice(1);
        } catch (error) {
            if (isNetworkFailure(error)) {
                break;
            }
            queue = queue.slice(1);
        }
        writeQueue(queue);
    }
};

/**
 * Wires automatic retry-queue flushing on reconnect and on page load.
 */
export const init = () => {
    window.addEventListener('online', flushQueue);
    flushQueue();
};
