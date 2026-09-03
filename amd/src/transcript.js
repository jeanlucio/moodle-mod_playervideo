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
 * Text-only linear document: renders the merged caption+interactions document from
 * transcript.php's data island, and drives the same attempt lifecycle (start_attempt/
 * submit_answer/finish_attempt) the video player uses — a first-class alternate route, not an
 * adaptation of the video screen (see the plugin SCOPE, "Modo texto-only").
 *
 * @module     mod_playervideo/transcript
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import * as Autosave from 'mod_playervideo/autosave';
import Notification from 'core/notification';
import {getString} from 'core/str';

/** @var {object|null} Data embedded server-side by transcript.php (blocks, playervideoid). */
let transcriptData = null;

/** @var {number} The open attempt id, once start_attempt() has returned. */
let attemptId = 0;

/** @var {Set<number>} Interaction ids already answered/viewed in the current attempt. */
let treatedInteractionIds = new Set();

/**
 * Calls one mod_playervideo Web Service method directly.
 *
 * @param {string} methodname Web service method name.
 * @param {object} args Arguments.
 * @returns {Promise<object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Shows a business-rule error via a plain alert, or an unexpected one via the generic
 * AJAX-exception dialog — mirrors the identical helper in amd/src/player.js.
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
 * Escapes a string for safe insertion as HTML text content.
 *
 * @param {string} text Raw text.
 * @returns {string}
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Announces a short status change to screen-reader users via the polite aria-live region.
 *
 * @param {string} message Text to announce.
 */
const announce = (message) => {
    document.getElementById('playervideo-transcript-announcer').textContent = message;
};

/**
 * Renders a plain text block (one caption cue).
 *
 * @param {HTMLElement} container Element to append to.
 * @param {object} block A {kind: 'text', text} block.
 */
const renderTextBlock = (container, block) => {
    const p = document.createElement('p');
    p.className = 'playervideo-transcript-text';
    p.textContent = block.text;
    container.appendChild(p);
};

/**
 * Renders a badge showing the outcome of an already-answered interaction — a lighter-weight
 * indicator than the full review view (which video mode itself only shows after the whole
 * attempt finishes, never mid-attempt for an already-treated item; see player.js).
 *
 * @param {HTMLElement} container Element to append to.
 * @param {string} labelkey Lang string key of the badge text.
 */
const renderTreatedBadge = async(container, labelkey) => {
    const badge = document.createElement('span');
    badge.className = 'badge bg-secondary';
    badge.textContent = await getString(labelkey, 'mod_playervideo');
    container.appendChild(badge);
};

/**
 * Renders one note interaction: the note text and a "Continue" button that marks it viewed.
 *
 * @param {HTMLElement} container Element to append to.
 * @param {object} block A {kind: 'interaction', type: 'note', ...} block.
 */
const renderNoteBlock = async(container, block) => {
    const body = document.createElement('div');
    body.innerHTML = block.notetext;
    container.appendChild(body);

    if (treatedInteractionIds.has(block.id)) {
        await renderTreatedBadge(container, 'alreadytreated');
        return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-outline-secondary btn-sm';
    button.textContent = await getString('continuewatching', 'mod_playervideo');
    button.addEventListener('click', async() => {
        button.disabled = true;
        try {
            await Autosave.callWithRetry('mod_playervideo_submit_answer', {
                attemptid: attemptId,
                interactionid: block.id,
                answerid: 0,
                responsetext: '',
            });
            treatedInteractionIds.add(block.id);
            button.replaceWith(document.createTextNode(''));
            await renderTreatedBadge(container, 'alreadytreated');
        } catch (error) {
            button.disabled = false;
            showError(error);
        }
    });
    container.appendChild(button);
};

/**
 * Renders one question interaction: the question text, answer inputs (radio for multichoice,
 * textarea for open) and a confirm button wired to submit_answer.
 *
 * @param {HTMLElement} container Element to append to.
 * @param {object} block A {kind: 'interaction', type: 'question', question, id} block.
 */
const renderQuestionBlock = async(container, block) => {
    const body = document.createElement('div');
    body.innerHTML = block.question.text;
    container.appendChild(body);

    if (treatedInteractionIds.has(block.id)) {
        await renderTreatedBadge(container, 'alreadytreated');
        return;
    }

    const inputname = `playervideo-transcript-answer-${block.id}`;
    const inputscontainer = document.createElement('div');
    inputscontainer.className = 'mb-2';

    if (block.question.options.length > 0) {
        block.question.options.forEach((option) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'form-check';
            wrapper.innerHTML = `
                <input class="form-check-input" type="radio" name="${inputname}" value="${option.id}"
                    id="${inputname}-${option.id}">
                <label class="form-check-label" for="${inputname}-${option.id}">${escapeHtml(option.text)}</label>
            `;
            inputscontainer.appendChild(wrapper);
        });
    } else {
        const textarea = document.createElement('textarea');
        textarea.className = 'form-control';
        textarea.id = `${inputname}-open`;
        inputscontainer.appendChild(textarea);
    }
    container.appendChild(inputscontainer);

    const confirmbutton = document.createElement('button');
    confirmbutton.type = 'button';
    confirmbutton.className = 'btn btn-primary btn-sm';
    confirmbutton.textContent = await getString('confirmanswer', 'mod_playervideo');
    confirmbutton.addEventListener('click', async() => {
        const selected = container.querySelector(`input[name="${inputname}"]:checked`);
        const openresponse = document.getElementById(`${inputname}-open`);

        if (block.question.options.length > 0 && !selected) {
            Notification.alert('', await getString('error_invalidanswer', 'mod_playervideo'));
            return;
        }
        if (openresponse && openresponse.value.trim() === '') {
            Notification.alert('', await getString('error_responsetextrequired', 'mod_playervideo'));
            return;
        }

        confirmbutton.disabled = true;
        try {
            const result = await Autosave.callWithRetry('mod_playervideo_submit_answer', {
                attemptid: attemptId,
                interactionid: block.id,
                answerid: selected ? parseInt(selected.value, 10) : 0,
                responsetext: openresponse ? openresponse.value : '',
            });
            treatedInteractionIds.add(block.id);
            inputscontainer.remove();
            confirmbutton.remove();

            let resultlabelkey = 'result_pending';
            let badgeclass = 'bg-secondary';
            if (result !== null && result.iscorrect === true) {
                resultlabelkey = 'result_correct';
                badgeclass = 'bg-success';
            } else if (result !== null && result.iscorrect === false) {
                resultlabelkey = 'result_incorrect';
                badgeclass = 'bg-danger';
            }
            const badge = document.createElement('span');
            badge.className = `badge ${badgeclass}`;
            badge.textContent = await getString(resultlabelkey, 'mod_playervideo');
            container.appendChild(badge);
            announce(badge.textContent);
        } catch (error) {
            confirmbutton.disabled = false;
            showError(error);
        }
    });
    container.appendChild(confirmbutton);
};

/**
 * Renders one poll interaction: the prompt, radio options and a confirm button wired to
 * submit_answer, showing the aggregate result afterwards (same data as the video player's poll
 * result view, see mod_playervideo_get_poll_results).
 *
 * @param {HTMLElement} container Element to append to.
 * @param {object} block A {kind: 'interaction', type: 'poll', notetext, polloptions, id} block.
 */
const renderPollBlock = async(container, block) => {
    const body = document.createElement('div');
    body.innerHTML = block.notetext;
    container.appendChild(body);

    if (treatedInteractionIds.has(block.id)) {
        await renderTreatedBadge(container, 'alreadytreated');
        return;
    }

    const inputname = `playervideo-transcript-poll-${block.id}`;
    const inputscontainer = document.createElement('div');
    inputscontainer.className = 'mb-2';
    block.polloptions.forEach((option) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'form-check';
        wrapper.innerHTML = `
            <input class="form-check-input" type="radio" name="${inputname}" value="${option.id}"
                id="${inputname}-${option.id}">
            <label class="form-check-label" for="${inputname}-${option.id}">${escapeHtml(option.text)}</label>
        `;
        inputscontainer.appendChild(wrapper);
    });
    container.appendChild(inputscontainer);

    const confirmbutton = document.createElement('button');
    confirmbutton.type = 'button';
    confirmbutton.className = 'btn btn-primary btn-sm';
    confirmbutton.textContent = await getString('confirmanswer', 'mod_playervideo');
    confirmbutton.addEventListener('click', async() => {
        const selected = container.querySelector(`input[name="${inputname}"]:checked`);
        if (!selected) {
            Notification.alert('', await getString('error_invalidpolloption', 'mod_playervideo'));
            return;
        }
        confirmbutton.disabled = true;
        try {
            await Autosave.callWithRetry('mod_playervideo_submit_answer', {
                attemptid: attemptId,
                interactionid: block.id,
                answerid: 0,
                responsetext: '',
                polloptionid: parseInt(selected.value, 10),
            });
            treatedInteractionIds.add(block.id);
            inputscontainer.remove();
            confirmbutton.remove();
            await renderTreatedBadge(container, 'alreadytreated');
        } catch (error) {
            confirmbutton.disabled = false;
            showError(error);
        }
    });
    container.appendChild(confirmbutton);
};

/**
 * Renders every block of the document into #playervideo-transcript-blocks, in order.
 *
 * @returns {Promise<void>}
 */
const renderBlocks = async() => {
    const container = document.getElementById('playervideo-transcript-blocks');
    container.innerHTML = '';

    if (transcriptData.blocks.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'alert alert-warning';
        empty.textContent = await getString('notranscriptcontent', 'mod_playervideo');
        container.appendChild(empty);
    }

    for (const block of transcriptData.blocks) {
        if (block.kind === 'text') {
            renderTextBlock(container, block);
            continue;
        }

        const wrapper = document.createElement('section');
        wrapper.className = 'card mb-2 p-2';
        container.appendChild(wrapper);

        if (block.type === 'note') {
            await renderNoteBlock(wrapper, block);
        } else if (block.type === 'poll') {
            await renderPollBlock(wrapper, block);
        } else {
            await renderQuestionBlock(wrapper, block);
        }
    }

    document.getElementById('playervideo-transcript-finish-btn').hidden = false;
};

/**
 * Ends the attempt and shows a short result summary.
 *
 * @returns {Promise<void>}
 */
const finishAttempt = async() => {
    const button = document.getElementById('playervideo-transcript-finish-btn');
    button.disabled = true;
    try {
        const result = await call('mod_playervideo_finish_attempt', {attemptid: attemptId});
        const summary = document.getElementById('playervideo-transcript-summary');

        if (result.status === 'finished' && result.grade !== null) {
            summary.textContent = await getString('yourgrade', 'mod_playervideo', Math.round(result.grade * 100) / 100);
        } else if (result.status === 'pendingcorrection') {
            summary.textContent = await getString('pendingcorrectionnotice', 'mod_playervideo');
        } else {
            summary.textContent = await getString('attemptsummaryheader', 'mod_playervideo');
        }

        summary.hidden = false;
        button.hidden = true;
        announce(summary.textContent);
    } catch (error) {
        button.disabled = false;
        showError(error);
    }
};

/**
 * Reads the transcript data island embedded server-side by transcript.php.
 *
 * @returns {object}
 */
const readTranscriptData = () => JSON.parse(document.getElementById('playervideo-transcript-data').textContent);

/**
 * Initialises the text-only page: starts/resumes the attempt (same lifecycle as the video
 * player) and renders the merged document.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    transcriptData = readTranscriptData();

    try {
        const started = await call('mod_playervideo_start_attempt', {playervideoid: transcriptData.playervideoid});
        attemptId = started.attemptid;
        treatedInteractionIds = new Set(started.treatedinteractionids);

        await renderBlocks();

        document.getElementById('playervideo-transcript-finish-btn').addEventListener('click', finishAttempt);
    } catch (error) {
        showError(error);
    }
};
