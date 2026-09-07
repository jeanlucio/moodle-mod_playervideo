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
 * Correction queue: lists open-question responses awaiting a grade, offers an AI suggestion on
 * demand, and lets the teacher confirm (as-is or edited) via review_response — a human always
 * stays in the loop, never an automatic grade.
 *
 * @module     mod_playervideo/grading
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';
import {escapeHtml} from 'mod_playervideo/escape';

/** @var {number} The instance id this grading queue belongs to. */
let playerVideoId = 0;

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
 * AJAX-exception dialog — mirrors the identical helper already used across this plugin's AMD.
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
 * Formats a number of seconds as m:ss.
 *
 * @param {number} seconds Seconds.
 * @returns {string}
 */
const formatTime = (seconds) => {
    const safe = Math.max(0, Math.round(seconds || 0));
    const minutes = Math.floor(safe / 60);
    const secs = (safe % 60).toString().padStart(2, '0');
    return `${minutes}:${secs}`;
};

/**
 * Shows the AI suggestion box for one card, or clears it when there is none.
 *
 * @param {HTMLElement} card The response card.
 * @param {string} feedback AI-suggested feedback text, empty when none.
 * @param {string} label Localised "AI suggestion" label.
 */
const renderAiBlock = (card, feedback, label) => {
    const block = card.querySelector('.playervideo-ai-block');
    if (feedback === '') {
        block.innerHTML = '';
        return;
    }
    block.innerHTML = `<div class="alert alert-info mb-0"><strong>${escapeHtml(label)}:</strong> ${escapeHtml(feedback)}</div>`;
};

/**
 * Builds one response card: question/answer context, an AI-suggestion trigger, and the grade/
 * feedback form the teacher confirms via review_response.
 *
 * @param {object} response One row from mod_playervideo_get_pending_corrections.
 * @returns {Promise<HTMLElement>}
 */
const renderCard = async(response) => {
    const aisuggestionlabel = await getString('aisuggestionlabel', 'mod_playervideo');

    const {html} = await Templates.renderForPromise('mod_playervideo/grading_card', {
        responseid: response.responseid,
        fullname: response.fullname,
        timedisplay: formatTime(response.timestamp),
        questiontext: response.questiontext,
        responsetext: response.responsetext,
        maxgrade: response.maxgrade,
        gradevalue: response.aigrade !== null ? response.aigrade : '',
        aifeedback: response.aifeedback,
    });
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    const card = wrapper.firstElementChild;

    renderAiBlock(card, response.aifeedback, aisuggestionlabel);

    card.querySelector('[data-action="generate"]').addEventListener('click', async(event) => {
        const button = event.target;
        button.disabled = true;
        try {
            const result = await call('mod_playervideo_generate_response_correction', {
                responseid: response.responseid,
            });
            await applySuggestion(response, result, aisuggestionlabel);
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    });

    card.querySelector('[data-action="confirm"]').addEventListener('click', async(event) => {
        const button = event.target;
        const gradeinput = card.querySelector(`#playervideo-grade-${response.responseid}`);
        const feedbackinput = card.querySelector(`#playervideo-feedback-${response.responseid}`);
        button.disabled = true;
        try {
            await call('mod_playervideo_review_response', {
                responseid: response.responseid,
                teachergrade: parseFloat(gradeinput.value) || 0,
                teacherfeedback: feedbackinput.value,
            });
            card.remove();
            if (document.getElementById('playervideo-grading-list').children.length === 0) {
                await loadPending();
            }
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    });

    return card;
};

/**
 * Applies a freshly generated suggestion to the still-open card for one response, if it is
 * still on screen — shared by the single "Generate" button and the "Generate all" batch action
 * so the two paths never drift on how a suggestion gets rendered.
 *
 * @param {object} response The response the suggestion belongs to.
 * @param {object} result generate_response_correction's return value.
 * @param {string} aisuggestionlabel Localised "AI suggestion" label.
 */
const applySuggestion = async(response, result, aisuggestionlabel) => {
    const card = document.querySelector(`[data-responseid="${response.responseid}"]`);
    if (!card) {
        return;
    }
    card.querySelector(`#playervideo-grade-${response.responseid}`).value = result.aigrade;
    card.querySelector(`#playervideo-feedback-${response.responseid}`).value = result.aifeedback;
    renderAiBlock(card, result.aifeedback, aisuggestionlabel);
};

/**
 * Renders the "generate all" button, offered above the queue whenever at least one response has
 * no AI suggestion yet — spares the teacher from clicking "Generate" once per response. Requests
 * run one at a time (never in parallel): a real AI provider's own per-minute token limit can
 * reject a burst of simultaneous calls, and one failure must never stop the rest of the batch —
 * same principle already applied to the batch question generator.
 *
 * @param {Array} pending The full pending queue, as returned by get_pending_corrections.
 * @returns {Promise<HTMLElement|null>} The button, or null if every response already has one.
 */
const renderGenerateAllButton = async(pending) => {
    const targets = pending.filter((response) => response.aigrade === null);
    if (targets.length === 0) {
        return null;
    }

    const [label, workinglabel, aisuggestionlabel] = await Promise.all([
        getString('generateallsuggestions', 'mod_playervideo', targets.length),
        getString('generatingsuggestions', 'mod_playervideo'),
        getString('aisuggestionlabel', 'mod_playervideo'),
    ]);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-outline-secondary btn-sm mb-3';
    button.textContent = label;

    button.addEventListener('click', async() => {
        button.disabled = true;
        button.textContent = workinglabel;

        for (const response of targets) {
            try {
                const result = await call('mod_playervideo_generate_response_correction', {
                    responseid: response.responseid,
                });
                await applySuggestion(response, result, aisuggestionlabel);
            } catch (error) {
                // Keep going — a card whose generation failed just keeps its "Generate" button,
                // same as if the teacher had never clicked "generate all" in the first place.
                continue;
            }
        }

        button.remove();
    });

    return button;
};

/**
 * Loads and renders the whole pending-correction queue.
 *
 * @returns {Promise<void>}
 */
const loadPending = async() => {
    const container = document.getElementById('playervideo-grading-list');
    container.innerHTML = '';

    try {
        const result = await call('mod_playervideo_get_pending_corrections', {playervideoid: playerVideoId});

        if (result.responses.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'alert alert-info';
            empty.textContent = await getString('nopendingcorrections', 'mod_playervideo');
            container.appendChild(empty);
            return;
        }

        const generateallbutton = await renderGenerateAllButton(result.responses);
        if (generateallbutton) {
            container.appendChild(generateallbutton);
        }

        for (const response of result.responses) {
            container.appendChild(await renderCard(response));
        }
    } catch (error) {
        showError(error);
    }
};

/**
 * Initialises the correction queue for one instance.
 *
 * @param {number} instanceid PlayerVideo instance id.
 * @returns {Promise<void>}
 */
export const init = (instanceid) => {
    playerVideoId = instanceid;
    return loadPending();
};
