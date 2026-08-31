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
 * Orchestrates the interactive player: picks the right source adapter, drives the pause-for-
 * interaction / auto-save / resume / anti-skip / finish / review flow described in the plugin
 * SCOPE. The three player_*.js modules only know how to talk to their own provider API; this
 * module is the only one that knows the mod_playervideo attempt lifecycle.
 *
 * @module     mod_playervideo/player
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';
import * as Autosave from 'mod_playervideo/autosave';
import {createTracker} from 'mod_playervideo/progress_tracker';
import {createPlayer as createYoutubePlayer} from 'mod_playervideo/player_youtube';
import {createPlayer as createVimeoPlayer} from 'mod_playervideo/player_vimeo';
import {createPlayer as createHtml5Player} from 'mod_playervideo/player_html5';

/** @var {number} How often, in ms, to heartbeat playback position to the server. */
const HEARTBEAT_INTERVAL_MS = 12000;

/** @var {object|null} Data embedded server-side by view.php (interactions, embed url, ...). */
let playerData = null;

/** @var {object|null} The active source adapter (player_youtube/vimeo/html5 instance). */
let adapter = null;

/** @var {object|null} The active progress tracker instance. */
let tracker = null;

/** @var {number} The open attempt id, once start_attempt() has returned. */
let attemptId = 0;

/** @var {number} The current attempt's 1st/2nd/3rd... ordinal, for the summary screen. */
let attemptNumber = 1;

/** @var {Set<number>} Interaction ids already answered/viewed in the current attempt. */
let treatedInteractionIds = new Set();

/** @var {object|null} The interaction currently paused on, if any. */
let currentInteraction = null;

/** @var {number|null} Heartbeat interval handle. */
let heartbeatTimer = null;

/** @var {boolean} Guards against handleEnded() firing twice (native ended + trim end). */
let attemptFinishing = false;

/**
 * Announces a short status change to screen-reader users via the polite aria-live region,
 * without stealing visual focus (see the plugin SCOPE, a11y requirements).
 *
 * @param {string} message Text to announce.
 */
const announce = (message) => {
    document.getElementById('playervideo-announcer').textContent = message;
};

/**
 * Shows one of the three top-level screens, hiding the other two.
 *
 * @param {string} name One of 'start', 'player', 'summary'.
 */
const showScreen = (name) => {
    document.getElementById('playervideo-start-screen').hidden = name !== 'start';
    document.getElementById('playervideo-player-screen').hidden = name !== 'player';
    document.getElementById('playervideo-summary-screen').hidden = name !== 'summary';
};

/**
 * Shows a business-rule error (a deliberate moodle_exception, using this plugin's own
 * error_* string convention) via a plain alert, or an unexpected one via the generic
 * AJAX-exception dialog — see the CLAUDE.md AMD rule this mirrors.
 *
 * @param {object} error Rejection from Ajax.call().
 * @returns {Promise<void>}
 */
const showError = async(error) => {
    if (typeof error.errorcode === 'string' && error.errorcode.startsWith('error_')) {
        Notification.alert('', error.message);
        return;
    }
    Notification.exception(error);
};

/**
 * Calls one mod_playervideo Web Service method directly (no retry queueing), for calls whose
 * caller needs an immediate, meaningful result to proceed (starting/finishing an attempt,
 * reading it back) rather than a best-effort background sync.
 *
 * @param {string} methodname Web service method name.
 * @param {object} args Arguments.
 * @returns {Promise<object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Resolves one review row's status (plus, for an answered multichoice-type row, whether it
 * was correct) to a {lang string key, badge class} pair — shared by the review overlay and the
 * attempt summary table, so the two views can never drift on what a given status looks like.
 *
 * @param {object} row One row from mod_playervideo_get_attempt_review.
 * @returns {Array<string>} [labelkey, cssclass].
 */
const resolveResult = (row) => {
    const resultmap = {
        answered: row.iscorrect ? ['result_correct', 'badge-success'] : ['result_incorrect', 'badge-danger'],
        viewed: ['result_viewed', 'badge-secondary'],
        'pending_review': ['result_pending', 'badge-warning'],
        graded: ['result_correct', 'badge-success'],
        notreached: ['result_notreached', 'badge-secondary'],
    };
    return resultmap[row.status] ?? ['result_notreached', 'badge-secondary'];
};

/**
 * Builds the read-only overlay content for one interaction in review mode.
 *
 * @param {object} row One row from mod_playervideo_get_attempt_review.
 * @returns {object} Context for the interaction_review template.
 */
const buildReviewContext = (row) => {
    const [resultkey, resultclass] = resolveResult(row);

    return {
        isnote: row.type === 'note',
        isquestion: row.type === 'question',
        notetext: row.notetext,
        questiontext: row.questiontext,
        options: row.options,
        hasresponsetext: row.responsetext !== '',
        responsetext: row.responsetext,
        resultlabelkey: resultkey,
        resultclass,
    };
};

/**
 * Renders and shows the review overlay for one interaction, resolving once the student
 * dismisses it.
 *
 * @param {object} row One row from mod_playervideo_get_attempt_review.
 * @returns {Promise<void>}
 */
const showReviewOverlay = (row) => new Promise((resolve) => {
    const context = buildReviewContext(row);
    getString(context.resultlabelkey, 'mod_playervideo').then(async(resultlabel) => {
        const {html, js} = await Templates.renderForPromise('mod_playervideo/interaction_review', {
            ...context,
            resultlabel,
            hasteacherfeedback: row.teacherfeedback !== '',
            teacherfeedback: row.teacherfeedback,
        });
        const overlay = document.getElementById('playervideo-review-overlay');
        Templates.replaceNodeContents(overlay, html, js);
        overlay.hidden = false;
        announce(resultlabel);
        overlay.querySelector('#playervideo-review-continue').addEventListener('click', () => {
            overlay.hidden = true;
            resolve();
        }, {once: true});
        overlay.querySelector('button').focus();
        return null;
    }).catch(Notification.exception);
});

/**
 * Plays a finished attempt back in review mode: pauses at every interaction, in timeline
 * order, showing what the student answered and the correct answer, with no anti-skip.
 *
 * @param {number} reviewattemptid Attempt id to review.
 * @returns {Promise<void>}
 */
const startReview = async(reviewattemptid) => {
    try {
        const data = await call('mod_playervideo_get_attempt_review', {attemptid: reviewattemptid});
        showScreen('player');
        adapter = await createAdapterForSource();
        await adapter.ready();

        for (const row of data.interactions) {
            if (row.type === 'note' || row.type === 'question') {
                adapter.seek(row.timestamp);
                await showReviewOverlay(row);
            }
        }

        showScreen('start');
    } catch (error) {
        showError(error);
    }
};

/**
 * Creates the right source adapter for the currently loaded playerData.
 *
 * @returns {Promise<object>}
 */
const createAdapterForSource = () => {
    const factories = {
        youtube: createYoutubePlayer,
        vimeo: createVimeoPlayer,
        html5: createHtml5Player,
    };
    const factory = factories[playerData.videotype];
    return factory('playervideo-target', playerData.embedurl);
};

/**
 * Sends the periodic playback heartbeat.
 *
 * @param {boolean} ended Whether the native ended event just fired.
 * @returns {Promise<void>}
 */
const heartbeat = async(ended = false) => {
    if (attemptId === 0) {
        return;
    }
    const currentTime = await adapter.getCurrentTime();
    tracker.recordProgress(currentTime);
    await Autosave.callWithRetry('mod_playervideo_save_progress', {
        attemptid: attemptId,
        lastposition: currentTime,
        segments: tracker.getSegmentsJson(),
        ended,
    });
};

/**
 * Renders and shows the compact attempt summary once an attempt finishes.
 *
 * @returns {Promise<void>}
 */
const showSummary = async() => {
    try {
        // Sequential on purpose: the review must be read back only after finish_attempt() has
        // actually persisted the responses' final status (e.g. 'graded' vs still 'pending_review').
        const finishresult = await call('mod_playervideo_finish_attempt', {attemptid: attemptId});
        const review = await call('mod_playervideo_get_attempt_review', {attemptid: attemptId});

        const labels = await Promise.all(
            review.interactions.map((row) => getString(row.type === 'note' ? 'typenote' : 'typequestion', 'mod_playervideo'))
        );
        const rows = review.interactions.map((row, index) => {
            const [resultkey, resultclass] = resolveResult(row);
            const minutes = Math.floor(row.timestamp / 60);
            const seconds = Math.floor(row.timestamp % 60).toString().padStart(2, '0');
            return {
                interactionid: row.interactionid,
                timelabel: `${minutes}:${seconds}`,
                typelabel: labels[index],
                resultlabel: resultkey,
                resultclass,
            };
        });

        const resultstrings = await Promise.all(rows.map((row) => getString(row.resultlabel, 'mod_playervideo')));
        rows.forEach((row, index) => {
            row.resultlabel = resultstrings[index];
        });

        const gradeline = finishresult.status === 'finished' && finishresult.grade !== null
            ? await getString('yourgrade', 'mod_playervideo', Math.round(finishresult.grade * 100) / 100)
            : '';
        const pendingnotice = finishresult.status === 'pendingcorrection'
            ? await getString('pendingcorrectionnotice', 'mod_playervideo')
            : '';

        const {html, js} = await Templates.renderForPromise('mod_playervideo/attempt_summary', {
            attemptnumber: attemptNumber,
            gradeline,
            pendingcorrectionnotice: pendingnotice,
            rows,
        });
        const container = document.getElementById('playervideo-summary-screen');
        Templates.replaceNodeContents(container, html, js);
        showScreen('summary');

        container.querySelector('#playervideo-summary-back').addEventListener('click', () => {
            window.location.reload();
        });
    } catch (error) {
        showError(error);
    }
};

/**
 * Ends the current attempt: stops the heartbeat, sends a final progress flush, then renders
 * the summary screen.
 *
 * @param {boolean} nativeended Whether this was triggered by the player's own ended event.
 * @returns {Promise<void>}
 */
const finishAttempt = async(nativeended) => {
    if (attemptFinishing) {
        return;
    }
    attemptFinishing = true;

    window.clearInterval(heartbeatTimer);
    adapter.pause();
    await heartbeat(nativeended);
    await showSummary();
};

/**
 * Builds the multichoice/truefalse answer options as radio inputs, or a free-text textarea
 * for an open question.
 *
 * @param {object} question Blind-JSON question data (id, type, text, options).
 * @param {HTMLElement} container Element to append the answer input(s) to.
 */
const buildAnswerInput = (question, container) => {
    if (question.options.length > 0) {
        question.options.forEach((option) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'form-check';
            wrapper.innerHTML = `
                <input class="form-check-input" type="radio" name="playervideo-answer" value="${option.id}"
                    id="playervideo-answer-${option.id}">
                <label class="form-check-label" for="playervideo-answer-${option.id}">${option.text}</label>
            `;
            container.appendChild(wrapper);
        });
    } else {
        const textarea = document.createElement('textarea');
        textarea.className = 'form-control';
        textarea.id = 'playervideo-open-response';
        container.appendChild(textarea);
    }
};

/**
 * Resumes playback after an interaction has been dealt with, hiding its overlay and
 * returning focus to the video.
 */
const resumeAfterInteraction = () => {
    document.getElementById('playervideo-interaction-overlay').hidden = true;
    document.getElementById('playervideo-interaction-overlay').innerHTML = '';
    currentInteraction = null;
    document.getElementById('playervideo-target').focus?.();
    adapter.play();
};

/**
 * Shows the confirmed-answer feedback (or the queued-for-sync notice), then a Continue button.
 *
 * @param {object|null} result submit_answer() result, or null if queued for later retry.
 * @returns {Promise<void>}
 */
const showAnswerFeedback = async(result) => {
    const overlay = document.getElementById('playervideo-interaction-overlay');
    let resultlabel;
    let resultclass;
    if (result === null) {
        resultlabel = await getString('result_pending', 'mod_playervideo');
        resultclass = 'badge-secondary';
    } else if (result.iscorrect === true) {
        resultlabel = await getString('result_correct', 'mod_playervideo');
        resultclass = 'badge-success';
    } else if (result.iscorrect === false) {
        resultlabel = await getString('result_incorrect', 'mod_playervideo');
        resultclass = 'badge-danger';
    } else {
        resultlabel = await getString('result_pending', 'mod_playervideo');
        resultclass = 'badge-warning';
    }

    const continuestr = await getString('continuewatching', 'mod_playervideo');
    overlay.innerHTML = `
        <div class="card-body">
            <p><span class="badge ${resultclass}">${resultlabel}</span></p>
            <button type="button" class="btn btn-primary" id="playervideo-overlay-continue">${continuestr}</button>
        </div>
    `;
    announce(resultlabel);
    overlay.querySelector('#playervideo-overlay-continue').addEventListener('click', resumeAfterInteraction);
    overlay.querySelector('button').focus();
};

/**
 * Submits the student's answer for the currently open question interaction.
 *
 * @returns {Promise<void>}
 */
const confirmAnswer = async() => {
    const selected = document.querySelector('input[name="playervideo-answer"]:checked');
    const openresponse = document.getElementById('playervideo-open-response');

    if (currentInteraction.question.options.length > 0 && !selected) {
        Notification.alert('', await getString('error_invalidanswer', 'mod_playervideo'));
        return;
    }
    if (openresponse && openresponse.value.trim() === '') {
        Notification.alert('', await getString('error_responsetextrequired', 'mod_playervideo'));
        return;
    }

    const result = await Autosave.callWithRetry('mod_playervideo_submit_answer', {
        attemptid: attemptId,
        interactionid: currentInteraction.id,
        answerid: selected ? parseInt(selected.value, 10) : 0,
        responsetext: openresponse ? openresponse.value : '',
    });

    treatedInteractionIds.add(currentInteraction.id);
    await showAnswerFeedback(result);
};

/**
 * Dismisses the currently open note interaction — a single step (submit + resume), unlike a
 * question, which shows feedback before resuming (see the plugin SCOPE).
 *
 * @returns {Promise<void>}
 */
const dismissNote = async() => {
    await Autosave.callWithRetry('mod_playervideo_submit_answer', {
        attemptid: attemptId,
        interactionid: currentInteraction.id,
        answerid: 0,
        responsetext: '',
    });
    treatedInteractionIds.add(currentInteraction.id);
    resumeAfterInteraction();
};

/**
 * Pauses the video and shows the live, editable overlay for one interaction.
 *
 * @param {object} interaction Interaction from playerData.interactions.
 * @returns {Promise<void>}
 */
const pauseForInteraction = async(interaction) => {
    adapter.pause();
    currentInteraction = interaction;
    const overlay = document.getElementById('playervideo-interaction-overlay');

    if (interaction.type === 'note') {
        const continuestr = await getString('continuewatching', 'mod_playervideo');
        overlay.innerHTML = `
            <div class="card-body">
                <div id="playervideo-note-body">${interaction.notetext}</div>
                <button type="button" class="btn btn-primary" id="playervideo-overlay-continue">${continuestr}</button>
            </div>
        `;
        overlay.querySelector('#playervideo-overlay-continue').addEventListener('click', dismissNote);
        announce(await getString('typenote', 'mod_playervideo'));
    } else {
        const confirmstr = await getString('confirmanswer', 'mod_playervideo');
        const body = document.createElement('div');
        body.className = 'card-body';
        const questiontext = document.createElement('div');
        questiontext.innerHTML = interaction.question.text;
        body.appendChild(questiontext);

        const answercontainer = document.createElement('div');
        answercontainer.className = 'mb-2';
        buildAnswerInput(interaction.question, answercontainer);
        body.appendChild(answercontainer);

        const confirmbutton = document.createElement('button');
        confirmbutton.type = 'button';
        confirmbutton.className = 'btn btn-primary';
        confirmbutton.id = 'playervideo-overlay-confirm';
        confirmbutton.textContent = confirmstr;
        confirmbutton.addEventListener('click', confirmAnswer);
        body.appendChild(confirmbutton);

        overlay.innerHTML = '';
        overlay.appendChild(body);
        announce(await getString('typequestion', 'mod_playervideo'));
    }

    overlay.hidden = false;
    overlay.querySelector('input, textarea, button')?.focus();
};

/**
 * Handles one playback tick: records progress, enforces anti-skip, and checks whether the
 * player has reached the trim end or the next untreated interaction.
 *
 * @param {number} currentTime Current playback position, in seconds.
 * @returns {Promise<void>}
 */
const onTick = async(currentTime) => {
    if (currentInteraction !== null || attemptFinishing) {
        return;
    }

    if (!tracker.canSeekTo(currentTime, playerData.allowseekahead)) {
        adapter.seek(tracker.getLastTime());
        announce(await getString('error_seekaheadblocked', 'mod_playervideo'));
        return;
    }
    tracker.recordProgress(currentTime);

    if (playerData.trimend !== null && currentTime >= playerData.trimend) {
        await finishAttempt(false);
        return;
    }

    const nextInteraction = playerData.interactions.find(
        (interaction) => !treatedInteractionIds.has(interaction.id) && currentTime >= interaction.timestamp
    );
    if (nextInteraction) {
        await pauseForInteraction(nextInteraction);
    }
};

/**
 * Starts (or resumes) an attempt and shows the interactive player screen.
 *
 * @returns {Promise<void>}
 */
const startOrResumeAttempt = async() => {
    try {
        const started = await call('mod_playervideo_start_attempt', {playervideoid: playerData.playervideoid});
        attemptId = started.attemptid;
        attemptNumber = started.attemptnumber;
        treatedInteractionIds = new Set(started.treatedinteractionids);
        attemptFinishing = false;

        showScreen('player');
        adapter = await createAdapterForSource();
        tracker = createTracker(JSON.parse(playerData.segments));
        await adapter.ready();

        adapter.onTimeUpdate(onTick);
        adapter.onEnded(() => finishAttempt(true));

        if (playerData.lastposition !== null) {
            adapter.seek(playerData.lastposition);
        } else if (playerData.trimstart !== null) {
            adapter.seek(playerData.trimstart);
        }

        heartbeatTimer = window.setInterval(() => heartbeat(false), HEARTBEAT_INTERVAL_MS);
        adapter.play();
    } catch (error) {
        showError(error);
    }
};

/**
 * Reads the interactions/embed data island embedded server-side by view.php.
 *
 * @returns {object}
 */
const readPlayerData = () => JSON.parse(document.getElementById('playervideo-player-data').textContent);

/**
 * Initialises the activity page: wires the start button and every "review this attempt"
 * button on the start screen.
 */
export const init = () => {
    playerData = readPlayerData();
    Autosave.init();

    document.getElementById('playervideo-start-btn')?.addEventListener('click', startOrResumeAttempt);

    document.querySelectorAll('.playervideo-review-btn').forEach((button) => {
        button.addEventListener('click', () => startReview(parseInt(button.dataset.attemptid, 10)));
    });

    document.getElementById('playervideo-finish-btn')?.addEventListener('click', () => finishAttempt(false));
};
