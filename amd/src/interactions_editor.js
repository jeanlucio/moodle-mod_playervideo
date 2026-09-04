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
 * Timeline management screen (Fase 3c redesign): a real, clickable/draggable video timeline —
 * markers for existing interactions, handles for the trim window — driving a docked panel that
 * alternates between a type picker and a focused editor form. Reuses the same source adapters
 * (player_youtube/vimeo/html5) the student player uses, since the timeline needs the same
 * duration/seek/currentTime primitives.
 *
 * @module     mod_playervideo/interactions_editor
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Modal from 'core/modal';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getString} from 'core/str';
import {createPlayer as createYoutubePlayer} from 'mod_playervideo/player_youtube';
import {createPlayer as createVimeoPlayer} from 'mod_playervideo/player_vimeo';
import {createPlayer as createHtml5Player} from 'mod_playervideo/player_html5';

/** @var {number} Minimum drag distance, in seconds, to bother saving a trim handle move. */
const TRIM_DRAG_EPSILON = 0.05;

/** @var {number} Seconds an arrow-key press nudges a trim handle; Shift multiplies this. */
const TRIM_KEY_STEP = 1;

/** @var {number} Maximum number of answers/options a multichoice question or poll can have. */
const MAX_ANSWERS = 6;

/** @var {string} Decorative checkmark icon for the "mark as correct" answer button. */
const ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">' +
    '<path d="M20 6 9 17l-5-5"/></svg>';

/** @var {string} Decorative cross icon for the "mark as incorrect" answer button. */
const ICON_CROSS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">' +
    '<path d="M18 6 6 18M6 6l12 12"/></svg>';

/** @var {string} Decorative trash icon for the "remove alternative/option" buttons. */
const ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
    '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>';

/** @var {object|null} Editor data embedded server-side by interactions.php. */
let editorData = null;

/** @var {object|null} The active source adapter. */
let adapter = null;

/** @var {number} Video duration, in seconds, once known. */
let duration = 0;

/** @var {Array<object>} Interactions last loaded from the server. */
let interactions = [];

/** @var {number|null} Current trim window start, in seconds. */
let trimstart = null;

/** @var {number|null} Current trim window end, in seconds. */
let trimend = null;

/** @var {number|null} Id of the interaction currently open for editing, null when creating new. */
let activeInteractionId = null;

/** @var {number} Timestamp (seconds) a new marker would be placed at. */
let pendingTimestamp = 0;

/** @var {boolean} Whether the video is currently playing, tracked locally to drive the play/pause button. */
let isPlaying = false;

/**
 * Calls a single mod_playervideo Web Service method.
 *
 * @param {string} methodname Web service method name.
 * @param {object} args Arguments.
 * @returns {Promise<object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Shows a business-rule error (a deliberate moodle_exception, using this plugin's own
 * error_* string convention) via a plain alert, or an unexpected one via the generic
 * AJAX-exception dialog — see the CLAUDE.md AMD rule this mirrors (and amd/src/player.js,
 * where the same helper already exists for the student-facing side).
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
 * Converts a video position to a percentage of the timeline's width.
 *
 * @param {number} seconds Position, in seconds.
 * @returns {number} 0-100.
 */
const percentForTime = (seconds) => {
    if (duration <= 0) {
        return 0;
    }
    return Math.min(100, Math.max(0, (seconds / duration) * 100));
};

/**
 * Converts a percentage of the timeline's width back to a video position.
 *
 * @param {number} percent 0-100.
 * @returns {number} Position, in seconds.
 */
const timeForPercent = (percent) => (Math.min(100, Math.max(0, percent)) / 100) * duration;

/**
 * Creates the right source adapter for the currently loaded editorData.
 *
 * @returns {Promise<object>}
 */
const createAdapterForSource = () => {
    const factories = {
        youtube: createYoutubePlayer,
        vimeo: createVimeoPlayer,
        html5: createHtml5Player,
    };
    const factory = factories[editorData.videotype];
    return factory('playervideo-target', editorData.embedurl);
};

/**
 * Positions the two trim handles and their shaded regions.
 */
const renderTrim = () => {
    const startpercent = percentForTime(trimstart ?? 0);
    const endpercent = percentForTime(trimend ?? duration);

    const starthandle = document.getElementById('playervideo-trim-handle-start');
    const endhandle = document.getElementById('playervideo-trim-handle-end');
    starthandle.style.left = `${startpercent}%`;
    endhandle.style.left = `${endpercent}%`;

    const startshade = document.getElementById('playervideo-trim-shade-start');
    startshade.style.left = '0';
    startshade.style.width = `${startpercent}%`;

    const endshade = document.getElementById('playervideo-trim-shade-end');
    endshade.style.left = `${endpercent}%`;
    endshade.style.width = `${100 - endpercent}%`;
};

/**
 * Renders every interaction as a marker on the timeline.
 */
const renderMarkers = () => {
    const container = document.getElementById('playervideo-markers');
    container.innerHTML = '';

    interactions.forEach((interaction) => {
        const marker = document.createElement('button');
        marker.type = 'button';
        marker.className = `playervideo-marker playervideo-marker-${interaction.type}`;
        if (interaction.id === activeInteractionId) {
            marker.classList.add('active');
        }
        marker.style.left = `${percentForTime(interaction.timestamp)}%`;
        marker.dataset.id = interaction.id;

        const preview = interaction.type === 'question' ? interaction.questionpreview : interaction.notetext;
        const plain = preview.replace(/<[^>]*>/g, '').trim();
        marker.setAttribute('aria-label', `${formatTime(interaction.timestamp)} — ${plain}`);

        marker.addEventListener('click', () => openInteractionForEdit(interaction));
        container.appendChild(marker);
    });
};

/**
 * Renders the accessible outline list mirroring the timeline markers — the keyboard/screen
 * reader path to the same interactions (see the plugin SCOPE, a11y requirements).
 */
const renderOutline = () => {
    const list = document.getElementById('playervideo-outline-list');
    const empty = document.getElementById('playervideo-empty-list');
    list.innerHTML = '';

    if (interactions.length === 0) {
        empty.hidden = false;
        return;
    }
    empty.hidden = true;

    interactions.forEach((interaction) => {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        const preview = interaction.type === 'question' ? interaction.questionpreview : interaction.notetext;
        const plain = preview.replace(/<[^>]*>/g, '').trim();
        button.innerHTML = `
            <span class="playervideo-swatch playervideo-swatch-${interaction.type}"></span>
            <span class="mono">${formatTime(interaction.timestamp)}</span>
            <span>${escapeHtml(plain)}</span>
        `;
        button.addEventListener('click', () => openInteractionForEdit(interaction));
        item.appendChild(button);
        list.appendChild(item);
    });
};

/**
 * Loads the trim window and interactions from the server and re-renders the timeline/outline.
 *
 * @returns {Promise<void>}
 */
const loadInteractions = async() => {
    try {
        const data = await call('mod_playervideo_get_interactions', {playervideoid: editorData.playervideoid});
        trimstart = data.trimstart;
        trimend = data.trimend;
        interactions = data.interactions;
        renderTrim();
        renderMarkers();
        renderOutline();
    } catch (error) {
        showError(error);
    }
};

/**
 * Saves the trim window to the server.
 *
 * @returns {Promise<void>}
 */
const saveTrim = async() => {
    try {
        await call('mod_playervideo_save_trim', {
            playervideoid: editorData.playervideoid,
            trimstart,
            trimend,
        });
    } catch (error) {
        showError(error);
        await loadInteractions();
    }
};

/**
 * Shows the type picker (add a new marker at pendingTimestamp) in the panel.
 *
 * @returns {Promise<void>}
 */
const renderPicker = async() => {
    activeInteractionId = null;
    renderMarkers();
    document.getElementById('playervideo-panel-footer').hidden = true;

    const [addlabel, questionlabel, notelabel, polllabel, questiondesc, notedesc, polldesc] = await Promise.all([
        getString('addmarkerat', 'mod_playervideo', formatTime(pendingTimestamp)),
        getString('typequestion', 'mod_playervideo'),
        getString('typenote', 'mod_playervideo'),
        getString('typepoll', 'mod_playervideo'),
        getString('questiondescription', 'mod_playervideo'),
        getString('notedescription', 'mod_playervideo'),
        getString('polldescription', 'mod_playervideo'),
    ]);

    const body = document.getElementById('playervideo-panel-body');
    body.innerHTML = `
        <div>
            <h2 class="playervideo-section-title">${escapeHtml(addlabel)}</h2>
            <div class="playervideo-type-grid" id="playervideo-type-grid">
                <button type="button" class="playervideo-type-card" data-type="note">
                    <span class="icon" aria-hidden="true">&#9998;</span>
                    <strong>${escapeHtml(notelabel)}</strong>
                    <small>${escapeHtml(notedesc)}</small>
                </button>
                <button type="button" class="playervideo-type-card" data-type="question">
                    <span class="icon" aria-hidden="true">?</span>
                    <strong>${escapeHtml(questionlabel)}</strong>
                    <small>${escapeHtml(questiondesc)}</small>
                </button>
                <button type="button" class="playervideo-type-card" data-type="poll">
                    <span class="icon" aria-hidden="true">%</span>
                    <strong>${escapeHtml(polllabel)}</strong>
                    <small>${escapeHtml(polldesc)}</small>
                </button>
            </div>
        </div>
    `;
    body.querySelectorAll('.playervideo-type-card').forEach((card) => {
        card.addEventListener('click', () => renderEditor(card.dataset.type, null));
    });
};

/**
 * Opens the editor panel for an existing interaction, and seeks the preview video there.
 *
 * @param {object} interaction Interaction to edit.
 */
const openInteractionForEdit = (interaction) => {
    activeInteractionId = interaction.id;
    if (adapter) {
        adapter.seek(interaction.timestamp);
    }
    renderMarkers();
    renderEditor(interaction.type, interaction);
};

/**
 * Deletes the currently-open interaction, after confirming with the teacher.
 *
 * @returns {Promise<void>}
 */
const deleteActiveInteraction = async() => {
    try {
        const [title, body, deletestr] = await Promise.all([
            getString('confirm', 'moodle'),
            getString('confirmdeleteinteraction', 'mod_playervideo'),
            getString('delete', 'moodle'),
        ]);
        const modal = await ModalSaveCancel.create({title, body, removeOnClose: true});
        modal.setSaveButtonText(deletestr);
        modal.getRoot().on(ModalEvents.save, async() => {
            try {
                await call('mod_playervideo_save_interaction', {
                    playervideoid: editorData.playervideoid,
                    interactionid: activeInteractionId,
                    'delete': true,
                });
                await loadInteractions();
                await renderPicker();
            } catch (error) {
                showError(error);
            }
        });
        modal.show();
    } catch (error) {
        showError(error);
    }
};

/**
 * Renders the accept/discard list of candidates generated by the batch modal.
 *
 * Accepting a candidate reuses the existing mod_playervideo_save_interaction endpoint (the
 * candidate's question already lives in the bank via the official save path) — no dedicated
 * endpoint for this step, exactly as planned. Discarding just removes the row; the question
 * stays an orphaned-but-harmless bank entry, same as any question never used anywhere.
 *
 * @param {HTMLElement} root Modal root element.
 * @param {object} modal The modal instance, closed once every candidate is resolved.
 * @param {Array} candidates Generated candidates from mod_playervideo_generate_questions_batch.
 */
const renderBatchCandidates = async(root, modal, candidates) => {
    const [acceptlabel, discardlabel, emptylabel] = await Promise.all([
        getString('accept', 'mod_playervideo'),
        getString('discard', 'mod_playervideo'),
        getString('nocandidates', 'mod_playervideo'),
    ]);
    const results = root.querySelector('#playervideo-batch-results');

    if (candidates.length === 0) {
        results.innerHTML = `<div class="alert alert-warning">${escapeHtml(emptylabel)}</div>`;
        return;
    }

    results.innerHTML = candidates.map((candidate, index) => {
        const answerslist = candidate.answers.map(
            (a) => `<li>${a.correct ? '<strong>' : ''}${escapeHtml(a.text)}${a.correct ? '</strong>' : ''}</li>`
        ).join('');
        return `
            <div class="playervideo-batch-candidate mb-2 p-2 border rounded" data-index="${index}">
                <div class="mono">${formatTime(candidate.timestamp)}</div>
                <div>${candidate.questiontext}</div>
                ${answerslist ? `<ul class="mb-1">${answerslist}</ul>` : ''}
                <button type="button" class="btn btn-sm btn-success" data-action="accept">
                    ${escapeHtml(acceptlabel)}
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-action="discard">
                    ${escapeHtml(discardlabel)}
                </button>
            </div>
        `;
    }).join('');

    const closeIfEmpty = () => {
        if (results.children.length === 0) {
            modal.hide();
        }
    };

    results.querySelectorAll('.playervideo-batch-candidate').forEach((row) => {
        const candidate = candidates[parseInt(row.dataset.index, 10)];
        row.querySelector('[data-action="accept"]').addEventListener('click', async() => {
            try {
                await call('mod_playervideo_save_interaction', {
                    playervideoid: editorData.playervideoid,
                    interactionid: 0,
                    timestamp: candidate.timestamp,
                    type: 'question',
                    questionid: candidate.questionid,
                    weight: 1,
                });
                row.remove();
                await loadInteractions();
                await renderPicker();
                closeIfEmpty();
            } catch (error) {
                showError(error);
            }
        });
        row.querySelector('[data-action="discard"]').addEventListener('click', () => {
            row.remove();
            closeIfEmpty();
        });
    });
};

/**
 * Opens the "generate from transcript" modal: paste a transcript, pick how many questions and
 * of what type, then review each AI-picked candidate individually before it becomes a real
 * timeline interaction (see {@see renderBatchCandidates}).
 *
 * @returns {Promise<void>}
 */
const openBatchGenerateModal = async() => {
    const [
        title, transcriptlabel, countlabel, formatlabel,
        mclabel, essaylabel, mixlabel, generatelabel,
    ] = await Promise.all([
        getString('generatebatch', 'mod_playervideo'),
        getString('pastetranscript', 'mod_playervideo'),
        getString('batchcount', 'mod_playervideo'),
        getString('questiontype', 'mod_playervideo'),
        getString('qtypemultichoice', 'mod_playervideo'),
        getString('qtypeessay', 'mod_playervideo'),
        getString('qtypemix', 'mod_playervideo'),
        getString('generate', 'mod_playervideo'),
    ]);

    const [usetranscriptlabel, langlabel] = await Promise.all([
        getString('usetranscriptascaption', 'mod_playervideo'),
        getString('captionlanguage', 'mod_playervideo'),
    ]);

    const body = `
        <label class="playervideo-field-label" for="playervideo-batch-transcript">
            ${escapeHtml(transcriptlabel)}
        </label>
        <textarea class="form-control mb-2" id="playervideo-batch-transcript" rows="8"></textarea>
        <label class="playervideo-field-label" for="playervideo-batch-count">${escapeHtml(countlabel)}</label>
        <input type="number" min="1" max="10" value="5" class="form-control mb-2" id="playervideo-batch-count">
        <label class="playervideo-field-label" for="playervideo-batch-format">${escapeHtml(formatlabel)}</label>
        <select class="form-select mb-2" id="playervideo-batch-format">
            <option value="mc">${escapeHtml(mclabel)}</option>
            <option value="open">${escapeHtml(essaylabel)}</option>
            <option value="mix">${escapeHtml(mixlabel)}</option>
        </select>
        <button type="button" class="btn btn-primary" id="playervideo-batch-generate-btn">
            ${escapeHtml(generatelabel)}
        </button>
        <div id="playervideo-batch-results" class="mt-3"></div>
        <hr>
        <div class="d-flex align-items-end gap-2">
            <div class="flex-grow-1">
                <label class="playervideo-field-label" for="playervideo-batch-caption-lang">
                    ${escapeHtml(langlabel)}
                </label>
                <input type="text" class="form-control" id="playervideo-batch-caption-lang" placeholder="en, pt-br...">
            </div>
            <button type="button" class="btn btn-outline-secondary" id="playervideo-batch-use-as-caption-btn">
                ${escapeHtml(usetranscriptlabel)}
            </button>
        </div>
    `;

    const modal = await Modal.create({title, body, large: true, removeOnClose: true, show: true});
    const root = modal.getRoot()[0];

    root.querySelector('#playervideo-batch-generate-btn').addEventListener('click', async(event) => {
        const button = event.target;
        button.disabled = true;
        try {
            const result = await call('mod_playervideo_generate_questions_batch', {
                playervideoid: editorData.playervideoid,
                transcript: root.querySelector('#playervideo-batch-transcript').value,
                count: parseInt(root.querySelector('#playervideo-batch-count').value, 10) || 1,
                format: root.querySelector('#playervideo-batch-format').value,
            });
            await renderBatchCandidates(root, modal, result.candidates);
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    });

    root.querySelector('#playervideo-batch-use-as-caption-btn').addEventListener('click', async(event) => {
        const button = event.target;
        const transcript = root.querySelector('#playervideo-batch-transcript').value;
        const lang = root.querySelector('#playervideo-batch-caption-lang').value.trim().toLowerCase();
        if (transcript.trim() === '') {
            Notification.alert('', await getString('error_transcriptrequired', 'mod_playervideo'));
            return;
        }
        if (lang === '') {
            Notification.alert('', await getString('error_invalidlang', 'mod_playervideo'));
            return;
        }
        button.disabled = true;
        try {
            await saveCaptionWithOverwriteConfirm(editorData.playervideoid, lang, transcript);
            Notification.addNotification({
                message: await getString('captionsaved', 'mod_playervideo'),
                type: 'success',
            });
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    });
};

/**
 * Saves a caption, first checking whether a manual caption already exists for that language and
 * confirming with the teacher before overwriting it — the plugin never overwrites a caption in
 * silence (see the plugin SCOPE, "Sinergia com legenda").
 *
 * @param {number} playervideoid PlayerVideo instance id.
 * @param {string} lang Language code, already trimmed/lowercased.
 * @param {string} content Caption content to save.
 * @returns {Promise<void>} Resolves once saved, or once the teacher declines to overwrite.
 */
const saveCaptionWithOverwriteConfirm = async(playervideoid, lang, content) => {
    const existing = await call('mod_playervideo_get_captions', {playervideoid});
    const alreadyexists = existing.captions.some((caption) => caption.lang === lang);

    if (alreadyexists) {
        const [title, body, savestr] = await Promise.all([
            getString('confirm', 'moodle'),
            getString('confirmoverwritecaption', 'mod_playervideo', lang),
            getString('save', 'moodle'),
        ]);
        const confirmed = await new Promise((resolve) => {
            ModalSaveCancel.create({title, body, removeOnClose: true}).then((modal) => {
                modal.setSaveButtonText(savestr);
                modal.getRoot().on(ModalEvents.save, () => resolve(true));
                modal.getRoot().on(ModalEvents.cancel, () => resolve(false));
                modal.show();
                return null;
            }).catch(showError);
        });
        if (!confirmed) {
            return;
        }
    }

    await call('mod_playervideo_save_caption', {playervideoid, lang, content});
};

/**
 * Opens the caption editor modal: pick an existing language (or add a new one), paste/edit its
 * content, save or delete it. One save path (mod_playervideo_save_caption) shared with the
 * "use transcript as caption too" synergy in the batch generation modal above.
 *
 * @returns {Promise<void>}
 */
const openCaptionsModal = async() => {
    const [
        title, langlabel, addlanguagelabel, newlanguagelabel, contentlabel, contenthint, savestr, deletestr,
    ] = await Promise.all([
        getString('captioneditor', 'mod_playervideo'),
        getString('captionlanguage', 'mod_playervideo'),
        getString('addcaptionlanguage', 'mod_playervideo'),
        getString('newlanguagecode', 'mod_playervideo'),
        getString('captioncontent', 'mod_playervideo'),
        getString('pastecaptioncontenthint', 'mod_playervideo'),
        getString('save', 'moodle'),
        getString('delete', 'moodle'),
    ]);

    const body = `
        <label class="playervideo-field-label" for="playervideo-caption-lang-select">${escapeHtml(langlabel)}</label>
        <select class="form-select mb-2" id="playervideo-caption-lang-select">
            <option value="__new__">${escapeHtml(addlanguagelabel)}</option>
        </select>
        <input type="text" class="form-control mb-2" id="playervideo-caption-new-lang"
            placeholder="${escapeHtml(newlanguagelabel)}">
        <label class="playervideo-field-label" for="playervideo-caption-content">${escapeHtml(contentlabel)}</label>
        <div class="form-text mb-1">${escapeHtml(contenthint)}</div>
        <textarea class="form-control mb-2" id="playervideo-caption-content" rows="10"></textarea>
        <button type="button" class="btn btn-primary" id="playervideo-caption-save-btn">${escapeHtml(savestr)}</button>
        <button type="button" class="btn btn-outline-danger" id="playervideo-caption-delete-btn" hidden>
            ${escapeHtml(deletestr)}
        </button>
    `;

    const modal = await Modal.create({title, body, large: true, removeOnClose: true, show: true});
    const root = modal.getRoot()[0];
    const select = root.querySelector('#playervideo-caption-lang-select');
    const newlanginput = root.querySelector('#playervideo-caption-new-lang');
    const contenttextarea = root.querySelector('#playervideo-caption-content');
    const deletebutton = root.querySelector('#playervideo-caption-delete-btn');

    let captions = [];

    const applySelection = () => {
        const isnew = select.value === '__new__';
        newlanginput.hidden = !isnew;
        deletebutton.hidden = isnew;
        if (!isnew) {
            const caption = captions.find((item) => item.lang === select.value);
            contenttextarea.value = caption ? caption.content : '';
        } else {
            contenttextarea.value = '';
        }
    };

    const reload = async(selectlang) => {
        const result = await call('mod_playervideo_get_captions', {playervideoid: editorData.playervideoid});
        captions = result.captions;
        select.innerHTML = `<option value="__new__">${escapeHtml(addlanguagelabel)}</option>` + captions.map(
            (caption) => `<option value="${escapeHtml(caption.lang)}">${escapeHtml(caption.lang)}</option>`
        ).join('');
        select.value = selectlang && captions.some((item) => item.lang === selectlang) ? selectlang : '__new__';
        applySelection();
    };

    select.addEventListener('change', applySelection);

    root.querySelector('#playervideo-caption-save-btn').addEventListener('click', async(event) => {
        const button = event.target;
        const lang = select.value === '__new__' ? newlanginput.value.trim().toLowerCase() : select.value;
        button.disabled = true;
        try {
            await call('mod_playervideo_save_caption', {
                playervideoid: editorData.playervideoid,
                lang,
                content: contenttextarea.value,
            });
            await reload(lang);
            Notification.addNotification({
                message: await getString('captionsaved', 'mod_playervideo'),
                type: 'success',
            });
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    });

    deletebutton.addEventListener('click', async() => {
        const lang = select.value;
        const [confirmtitle, confirmbody] = await Promise.all([
            getString('confirm', 'moodle'),
            getString('confirmdeletecaption', 'mod_playervideo', lang),
        ]);
        const confirmmodal = await ModalSaveCancel.create({title: confirmtitle, body: confirmbody, removeOnClose: true});
        confirmmodal.setSaveButtonText(deletestr);
        confirmmodal.getRoot().on(ModalEvents.save, async() => {
            try {
                await call('mod_playervideo_save_caption', {
                    playervideoid: editorData.playervideoid,
                    lang,
                    'delete': true,
                });
                try {
        await reload();
    } catch (error) {
        showError(error);
    }
                Notification.addNotification({
                    message: await getString('captiondeleted', 'mod_playervideo'),
                    type: 'success',
                });
            } catch (error) {
                showError(error);
            }
        });
        confirmmodal.show();
    });

    try {
        await reload();
    } catch (error) {
        showError(error);
    }
};

/**
 * Opens the easy-read (DI) summary modal: pick a language that already has a caption, generate
 * a summary by AI from that caption's text, edit it, and approve it (or leave it pending) — a
 * summary is never shown to a student until approved (see the plugin SCOPE, "Resumo por IA em
 * leitura fácil"). Needs at least one caption to exist first, since generation reads from it.
 *
 * @returns {Promise<void>}
 */
const openDiSummaryModal = async() => {
    const [
        title, langlabel, contentlabel, generatestr, savestr, approvestr, approvedstr, deletestr, nocaptionsstr,
    ] = await Promise.all([
        getString('disummary', 'mod_playervideo'),
        getString('captionlanguage', 'mod_playervideo'),
        getString('disummary', 'mod_playervideo'),
        getString('generate', 'mod_playervideo'),
        getString('save', 'moodle'),
        getString('approve', 'mod_playervideo'),
        getString('approved', 'mod_playervideo'),
        getString('delete', 'moodle'),
        getString('nocaptionsyet', 'mod_playervideo'),
    ]);

    const body = `
        <div class="alert alert-info" id="playervideo-disummary-nocaptions">${escapeHtml(nocaptionsstr)}</div>
        <div id="playervideo-disummary-form" hidden>
            <label class="playervideo-field-label" for="playervideo-disummary-lang-select">
                ${escapeHtml(langlabel)}
            </label>
            <select class="form-select mb-2" id="playervideo-disummary-lang-select"></select>
            <div id="playervideo-disummary-status" class="mb-2"></div>
            <label class="playervideo-field-label" for="playervideo-disummary-content">
                ${escapeHtml(contentlabel)}
            </label>
            <textarea class="form-control mb-2" id="playervideo-disummary-content" rows="8"></textarea>
            <button type="button" class="btn btn-outline-secondary" id="playervideo-disummary-generate-btn">
                ${escapeHtml(generatestr)}
            </button>
            <button type="button" class="btn btn-primary" id="playervideo-disummary-save-btn">
                ${escapeHtml(savestr)}
            </button>
            <button type="button" class="btn btn-success" id="playervideo-disummary-approve-btn">
                ${escapeHtml(approvestr)}
            </button>
            <button type="button" class="btn btn-outline-danger" id="playervideo-disummary-delete-btn" hidden>
                ${escapeHtml(deletestr)}
            </button>
        </div>
    `;

    const modal = await Modal.create({title, body, large: true, removeOnClose: true, show: true});
    const root = modal.getRoot()[0];
    const select = root.querySelector('#playervideo-disummary-lang-select');
    const statusdiv = root.querySelector('#playervideo-disummary-status');
    const contenttextarea = root.querySelector('#playervideo-disummary-content');
    const approvebutton = root.querySelector('#playervideo-disummary-approve-btn');
    const deletebutton = root.querySelector('#playervideo-disummary-delete-btn');

    let captionlangs = [];
    let summaries = [];

    const pendingstr = await getString('pending', 'mod_playervideo');

    const renderStatus = (status) => {
        if (status === 'approved') {
            statusdiv.innerHTML = `<span class="badge bg-success">${escapeHtml(approvedstr)}</span>`;
            deletebutton.hidden = false;
        } else if (status === 'pending') {
            statusdiv.innerHTML = `<span class="badge bg-warning text-dark">${escapeHtml(pendingstr)}</span>`;
            deletebutton.hidden = false;
        } else {
            statusdiv.innerHTML = '';
            deletebutton.hidden = true;
        }
    };

    const applySelection = () => {
        const summary = summaries.find((item) => item.lang === select.value);
        contenttextarea.value = summary ? summary.content : '';
        renderStatus(summary ? summary.status : '');
    };

    const reload = async(selectlang) => {
        const [captionsresult, summariesresult] = await Promise.all([
            call('mod_playervideo_get_captions', {playervideoid: editorData.playervideoid}),
            call('mod_playervideo_get_di_summaries', {playervideoid: editorData.playervideoid}),
        ]);
        captionlangs = captionsresult.captions.map((caption) => caption.lang);
        summaries = summariesresult.summaries;

        root.querySelector('#playervideo-disummary-nocaptions').hidden = captionlangs.length > 0;
        root.querySelector('#playervideo-disummary-form').hidden = captionlangs.length === 0;
        if (captionlangs.length === 0) {
            return;
        }

        select.innerHTML = captionlangs.map((lang) => `<option value="${escapeHtml(lang)}">${escapeHtml(lang)}</option>`).join('');
        select.value = selectlang && captionlangs.includes(selectlang) ? selectlang : captionlangs[0];
        applySelection();
    };

    select.addEventListener('change', applySelection);

    root.querySelector('#playervideo-disummary-generate-btn').addEventListener('click', async(event) => {
        const button = event.target;
        button.disabled = true;
        try {
            const result = await call('mod_playervideo_generate_di_summary', {
                playervideoid: editorData.playervideoid,
                lang: select.value,
            });
            contenttextarea.value = result.content;
            renderStatus(result.status);
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    });

    const saveWithApproval = async(approved) => {
        await call('mod_playervideo_save_di_summary', {
            playervideoid: editorData.playervideoid,
            lang: select.value,
            content: contenttextarea.value,
            approved,
        });
        await reload(select.value);
        Notification.addNotification({
            message: await getString('disummarysaved', 'mod_playervideo'),
            type: 'success',
        });
    };

    root.querySelector('#playervideo-disummary-save-btn').addEventListener('click', async(event) => {
        const button = event.target;
        const existing = summaries.find((item) => item.lang === select.value);
        button.disabled = true;
        try {
            await saveWithApproval(existing ? existing.status === 'approved' : false);
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    });

    approvebutton.addEventListener('click', async() => {
        approvebutton.disabled = true;
        try {
            if (contenttextarea.value.trim() === '') {
                Notification.alert('', await getString('error_disummarycontentrequired', 'mod_playervideo'));
                return;
            }
            await saveWithApproval(true);
        } catch (error) {
            showError(error);
        } finally {
            approvebutton.disabled = false;
        }
    });

    deletebutton.addEventListener('click', async() => {
        const lang = select.value;
        const [confirmtitle, confirmbody] = await Promise.all([
            getString('confirm', 'moodle'),
            getString('confirmdeletedisummary', 'mod_playervideo', lang),
        ]);
        const confirmmodal = await ModalSaveCancel.create({title: confirmtitle, body: confirmbody, removeOnClose: true});
        confirmmodal.setSaveButtonText(deletestr);
        confirmmodal.getRoot().on(ModalEvents.save, async() => {
            try {
                await call('mod_playervideo_save_di_summary', {
                    playervideoid: editorData.playervideoid,
                    lang,
                    'delete': true,
                });
                try {
        await reload();
    } catch (error) {
        showError(error);
    }
                Notification.addNotification({
                    message: await getString('disummarydeleted', 'mod_playervideo'),
                    type: 'success',
                });
            } catch (error) {
                showError(error);
            }
        });
        confirmmodal.show();
    });

    try {
        await reload();
    } catch (error) {
        showError(error);
    }
};

/**
 * Renders the footer Save (+ Delete when editing) buttons for the panel.
 *
 * @param {Function} onSave Called when Save is pressed.
 */
const renderFooter = (onSave) => {
    const footer = document.getElementById('playervideo-panel-footer');
    footer.hidden = false;
    footer.innerHTML = '';

    const savebutton = document.createElement('button');
    savebutton.type = 'button';
    savebutton.className = 'btn btn-primary';
    getString('save', 'moodle').then((label) => {
        savebutton.textContent = label;
        return null;
    }).catch(showError);
    savebutton.addEventListener('click', async() => {
        savebutton.disabled = true;
        try {
            await onSave();
        } finally {
            savebutton.disabled = false;
        }
    });
    footer.appendChild(savebutton);

    if (activeInteractionId !== null) {
        const deletebutton = document.createElement('button');
        deletebutton.type = 'button';
        deletebutton.className = 'btn btn-outline-danger';
        getString('delete', 'moodle').then((label) => {
            deletebutton.textContent = label;
            return null;
        }).catch(showError);
        deletebutton.addEventListener('click', deleteActiveInteraction);
        footer.appendChild(deletebutton);
    }
};

/**
 * Renders the note editor form.
 *
 * @param {object|null} existing The interaction being edited, or null when creating new.
 * @returns {Promise<void>}
 */
const renderNoteEditor = async(existing) => {
    const heading = await getString(
        existing ? 'editmarkerat' : 'addmarkerat',
        'mod_playervideo',
        formatTime(existing ? existing.timestamp : pendingTimestamp)
    );
    const label = await getString('notetext', 'mod_playervideo');

    const body = document.getElementById('playervideo-panel-body');
    body.innerHTML = `
        <div>
            <h2 class="playervideo-section-title">${escapeHtml(heading)}</h2>
            <label class="playervideo-field-label" for="playervideo-note-text">${escapeHtml(label)}</label>
            <textarea class="form-control" id="playervideo-note-text" rows="3"></textarea>
        </div>
    `;
    document.getElementById('playervideo-note-text').value = existing ? existing.notetext : '';

    renderFooter(async() => {
        const notetext = document.getElementById('playervideo-note-text').value;
        try {
            await call('mod_playervideo_save_interaction', {
                playervideoid: editorData.playervideoid,
                interactionid: existing ? existing.id : 0,
                timestamp: existing ? existing.timestamp : pendingTimestamp,
                type: 'note',
                notetext,
            });
            await loadInteractions();
            await renderPicker();
        } catch (error) {
            showError(error);
        }
    });
};

/**
 * Renders the poll editor form.
 *
 * @param {object|null} existing The interaction being edited, or null when creating new.
 * @returns {Promise<void>}
 */
const renderPollEditor = async(existing) => {
    const heading = await getString(
        existing ? 'editmarkerat' : 'addmarkerat',
        'mod_playervideo',
        formatTime(existing ? existing.timestamp : pendingTimestamp)
    );
    const [promptlabel, addoptionlabel, removeoptionlabel, countlabel] = await Promise.all([
        getString('pollprompt', 'mod_playervideo'),
        getString('addpolloption', 'mod_playervideo'),
        getString('removepolloption', 'mod_playervideo'),
        getString('answercounthint', 'mod_playervideo', {count: 0, max: MAX_ANSWERS}),
    ]);

    const body = document.getElementById('playervideo-panel-body');
    body.innerHTML = `
        <div>
            <h2 class="playervideo-section-title">${escapeHtml(heading)}</h2>
            <label class="playervideo-field-label" for="playervideo-poll-prompt">${escapeHtml(promptlabel)}</label>
            <textarea class="form-control mb-2" id="playervideo-poll-prompt" rows="2"></textarea>
            <div id="playervideo-poll-options"></div>
            <button type="button" class="playervideo-addanswer"
                id="playervideo-add-poll-option">${escapeHtml(addoptionlabel)}</button>
            <div class="playervideo-answer-count"
                id="playervideo-poll-option-count">${escapeHtml(countlabel)}</div>
        </div>
    `;
    document.getElementById('playervideo-poll-prompt').value = existing ? existing.notetext : '';

    const optionscontainer = document.getElementById('playervideo-poll-options');
    const addbutton = document.getElementById('playervideo-add-poll-option');
    const countlabelel = document.getElementById('playervideo-poll-option-count');

    const updateOptionCount = async() => {
        const count = optionscontainer.children.length;
        countlabelel.textContent = await getString('answercounthint', 'mod_playervideo', {count, max: MAX_ANSWERS});
        addbutton.disabled = count >= MAX_ANSWERS;
    };

    const addOptionRow = (text) => {
        if (optionscontainer.children.length >= MAX_ANSWERS) {
            return;
        }
        const row = document.createElement('div');
        row.className = 'input-group mb-1 playervideo-poll-option-row';
        row.innerHTML = `
            <input type="text" class="form-control playervideo-poll-option-text">
            <button type="button" class="playervideo-answer-remove" data-action="remove"
                aria-label="${escapeHtml(removeoptionlabel)}">${ICON_TRASH}</button>
        `;
        row.querySelector('.playervideo-poll-option-text').value = text;
        row.querySelector('[data-action="remove"]').addEventListener('click', () => {
            row.remove();
            updateOptionCount();
        });
        optionscontainer.appendChild(row);
        updateOptionCount();
    };

    const existingoptions = existing ? existing.polloptions : [];
    if (existingoptions.length > 0) {
        existingoptions.forEach((option) => addOptionRow(option.text));
    } else {
        addOptionRow('');
        addOptionRow('');
    }
    addbutton.addEventListener('click', () => addOptionRow(''));

    renderFooter(async() => {
        const notetext = document.getElementById('playervideo-poll-prompt').value;
        const polloptions = Array.from(document.querySelectorAll('.playervideo-poll-option-text')).map((el) => el.value);
        try {
            await call('mod_playervideo_save_interaction', {
                playervideoid: editorData.playervideoid,
                interactionid: existing ? existing.id : 0,
                timestamp: existing ? existing.timestamp : pendingTimestamp,
                type: 'poll',
                notetext,
                polloptions,
            });
            await loadInteractions();
            await renderPicker();
        } catch (error) {
            showError(error);
        }
    });
};

/**
 * Renders the question editor form: "create here" (multichoice/truefalse) and "pull from
 * bank" subtabs, sharing the selected-question state between them.
 *
 * @param {object|null} existing The interaction being edited, or null when creating new.
 * @returns {Promise<void>}
 */
const renderQuestionEditor = async(existing) => {
    let selectedQuestionId = existing ? existing.questionid : 0;
    let selectedQuestionPreview = existing ? existing.questionpreview : '';
    let activetab = existing ? 'bank' : 'create';

    const heading = await getString(
        existing ? 'editmarkerat' : 'addmarkerat',
        'mod_playervideo',
        formatTime(existing ? existing.timestamp : pendingTimestamp)
    );
    const [createherelabel, pullfrombanklabel, generatelabel, weightlabel, hintlabel] = await Promise.all([
        getString('createhere', 'mod_playervideo'),
        getString('pullfrombank', 'mod_playervideo'),
        getString('generatewithai', 'mod_playervideo'),
        getString('interactionweight', 'mod_playervideo'),
        getString('selectedquestionhint', 'mod_playervideo'),
    ]);

    const body = document.getElementById('playervideo-panel-body');
    body.innerHTML = `
        <div>
            <h2 class="playervideo-section-title">${escapeHtml(heading)}</h2>
            <label class="playervideo-field-label" for="playervideo-weight">${escapeHtml(weightlabel)}</label>
            <input type="number" min="0" step="0.5" class="form-control mb-2" id="playervideo-weight"
                value="${existing ? existing.weight : 1}">
            <div class="playervideo-subtabs" role="tablist">
                <button type="button" role="tab" aria-selected="${!existing}"
                    id="playervideo-tab-create">${escapeHtml(createherelabel)}</button>
                <button type="button" role="tab" aria-selected="${!!existing}"
                    id="playervideo-tab-bank">${escapeHtml(pullfrombanklabel)}</button>
                <button type="button" role="tab" aria-selected="false"
                    id="playervideo-tab-ai">${escapeHtml(generatelabel)}</button>
            </div>
            <div id="playervideo-question-subpanel" class="mt-2"></div>
            <div class="alert alert-info mt-2" id="playervideo-selected-question" hidden></div>
        </div>
    `;

    const updateSelectedQuestionDisplay = () => {
        const el = document.getElementById('playervideo-selected-question');
        if (selectedQuestionId > 0) {
            el.hidden = false;
            el.innerHTML = `<strong>${escapeHtml(hintlabel)}</strong> ${selectedQuestionPreview}`;
        } else {
            el.hidden = true;
            el.innerHTML = '';
        }
    };

    /**
     * @var {Function|null} Creates the question from the "create here" fields and resolves with
     * its new question id, once renderCreateSubpanel() has set it up. Shared by the panel
     * footer's single Save button, which only calls this when the create tab is active — see
     * the "activetab" branch in the renderFooter() call below.
     */
    let createQuestion = null;

    const renderCreateSubpanel = async() => {
        const [
            qtypelabel, texttlabel, mclabel, tflabel, singlelabel, singlehintlabel,
            addanswerlabel, removealternativelabel, markcorrectlabel, markincorrectlabel,
        ] = await Promise.all([
            getString('questiontype', 'mod_playervideo'),
            getString('questiontext', 'mod_playervideo'),
            getString('qtypemultichoice', 'mod_playervideo'),
            getString('qtypetruefalse', 'mod_playervideo'),
            getString('singleanswer', 'mod_playervideo'),
            getString('singleanswerhint', 'mod_playervideo'),
            getString('addanswer', 'mod_playervideo'),
            getString('removealternative', 'mod_playervideo'),
            getString('markcorrect', 'mod_playervideo'),
            getString('markincorrect', 'mod_playervideo'),
        ]);
        const [truelabel, falselabel] = await Promise.all([
            getString('true', 'mod_playervideo'),
            getString('false', 'mod_playervideo'),
        ]);
        const sub = document.getElementById('playervideo-question-subpanel');
        sub.innerHTML = `
            <label class="playervideo-field-label" for="playervideo-qtype">${escapeHtml(qtypelabel)}</label>
            <select class="form-select mb-2" id="playervideo-qtype">
                <option value="multichoice">${escapeHtml(mclabel)}</option>
                <option value="truefalse">${escapeHtml(tflabel)}</option>
            </select>
            <label class="playervideo-field-label" for="playervideo-questiontext">${escapeHtml(texttlabel)}</label>
            <textarea class="form-control mb-2" id="playervideo-questiontext" rows="2"></textarea>
            <div id="playervideo-multichoice-fields">
                <div class="playervideo-toggle-row">
                    <div class="copy">
                        <strong>${escapeHtml(singlelabel)}</strong>
                        <span>${escapeHtml(singlehintlabel)}</span>
                    </div>
                    <label class="playervideo-switch">
                        <input type="checkbox" id="playervideo-single" checked>
                        <span class="playervideo-switch-track"></span>
                        <span class="playervideo-switch-thumb"></span>
                    </label>
                </div>
                <div id="playervideo-answers"></div>
                <button type="button" class="playervideo-addanswer"
                    id="playervideo-add-answer">${escapeHtml(addanswerlabel)}</button>
                <div class="playervideo-answer-count" id="playervideo-answer-count"></div>
            </div>
            <div id="playervideo-truefalse-fields" hidden>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="playervideo-tf" id="playervideo-truefalse-correct" checked>
                    <label class="form-check-label" for="playervideo-truefalse-correct">${escapeHtml(truelabel)}</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="playervideo-tf" id="playervideo-truefalse-incorrect">
                    <label class="form-check-label" for="playervideo-truefalse-incorrect">${escapeHtml(falselabel)}</label>
                </div>
            </div>
        `;

        const answers = document.getElementById('playervideo-answers');
        const addbutton = document.getElementById('playervideo-add-answer');
        const countlabelel = document.getElementById('playervideo-answer-count');

        const updateAnswerCount = async() => {
            const count = answers.children.length;
            countlabelel.textContent = await getString('answercounthint', 'mod_playervideo', {count, max: MAX_ANSWERS});
            addbutton.disabled = count >= MAX_ANSWERS;
        };

        const addAnswerRow = () => {
            if (answers.children.length >= MAX_ANSWERS) {
                return;
            }
            const row = document.createElement('div');
            row.className = 'playervideo-answer-row mb-1';
            row.innerHTML = `
                <input type="text" class="form-control playervideo-answer-text">
                <div class="playervideo-correctness">
                    <button type="button" class="playervideo-correct" aria-pressed="false"
                        aria-label="${escapeHtml(markcorrectlabel)}">${ICON_CHECK}</button>
                    <button type="button" class="playervideo-incorrect" aria-pressed="true"
                        aria-label="${escapeHtml(markincorrectlabel)}">${ICON_CROSS}</button>
                </div>
                <button type="button" class="playervideo-answer-remove" data-action="remove"
                    aria-label="${escapeHtml(removealternativelabel)}">${ICON_TRASH}</button>
            `;
            const correctbutton = row.querySelector('.playervideo-correct');
            const incorrectbutton = row.querySelector('.playervideo-incorrect');
            correctbutton.addEventListener('click', () => {
                correctbutton.setAttribute('aria-pressed', 'true');
                incorrectbutton.setAttribute('aria-pressed', 'false');
            });
            incorrectbutton.addEventListener('click', () => {
                correctbutton.setAttribute('aria-pressed', 'false');
                incorrectbutton.setAttribute('aria-pressed', 'true');
            });
            row.querySelector('[data-action="remove"]').addEventListener('click', () => {
                row.remove();
                updateAnswerCount();
            });
            answers.appendChild(row);
            updateAnswerCount();
        };
        addAnswerRow();
        addAnswerRow();
        addbutton.addEventListener('click', addAnswerRow);

        document.getElementById('playervideo-qtype').addEventListener('change', (event) => {
            const qtype = event.target.value;
            document.getElementById('playervideo-multichoice-fields').hidden = qtype !== 'multichoice';
            document.getElementById('playervideo-truefalse-fields').hidden = qtype !== 'truefalse';
        });

        createQuestion = async() => {
            const qtype = document.getElementById('playervideo-qtype').value;
            const questiontext = document.getElementById('playervideo-questiontext').value;
            const args = {
                playervideoid: editorData.playervideoid,
                qtype,
                questiontext,
                name: '',
                single: true,
                correctanswer: true,
                answers: [],
            };
            if (qtype === 'truefalse') {
                args.correctanswer = document.getElementById('playervideo-truefalse-correct')?.checked ?? true;
            } else {
                args.single = document.getElementById('playervideo-single').checked;
                args.answers = Array.from(document.querySelectorAll('.playervideo-answer-row')).map((row) => ({
                    text: row.querySelector('.playervideo-answer-text').value,
                    correct: row.querySelector('.playervideo-correct').getAttribute('aria-pressed') === 'true',
                }));
            }
            const result = await call('mod_playervideo_create_question', args);
            return result.questionid;
        };
    };

    const renderBankSubpanel = () => {
        const sub = document.getElementById('playervideo-question-subpanel');
        sub.innerHTML = `
            <input type="text" class="form-control mb-2" id="playervideo-search" placeholder="&hellip;">
            <ul class="list-group" id="playervideo-search-results"></ul>
        `;
        let searchtimeout = null;
        document.getElementById('playervideo-search').addEventListener('input', (event) => {
            window.clearTimeout(searchtimeout);
            const query = event.target.value;
            searchtimeout = window.setTimeout(async() => {
                const results = document.getElementById('playervideo-search-results');
                results.innerHTML = '';
                if (query.trim() === '') {
                    return;
                }
                try {
                    const data = await call('mod_playervideo_search_questions', {
                        playervideoid: editorData.playervideoid,
                        query,
                        limit: 20,
                    });
                    data.questions.forEach((question) => {
                        const item = document.createElement('li');
                        item.className = 'list-group-item list-group-item-action';
                        item.style.cursor = 'pointer';
                        item.innerHTML = `<strong>${escapeHtml(question.type)}</strong> — ${question.preview}`;
                        item.addEventListener('click', () => {
                            selectedQuestionId = question.id;
                            selectedQuestionPreview = question.preview;
                            updateSelectedQuestionDisplay();
                        });
                        results.appendChild(item);
                    });
                } catch (error) {
                    showError(error);
                }
            }, 300);
        });
    };

    /**
     * Renders the "Generate by AI" subpanel: optional grounding context + question type, then a
     * Generate button that calls mod_playervideo_generate_question_ai. A successful generation
     * already wrote the question to the bank (same official save path as everywhere else), so
     * selecting it here reuses the exact same footer/save flow the "pull from bank" tab already
     * has — nothing panel-specific to wire beyond setting selectedQuestionId.
     */
    const renderAiSubpanel = async() => {
        const [contextlabel, qtypelabel, mclabel, essaylabel, generatebuttonlabel] = await Promise.all([
            getString('aicontext', 'mod_playervideo'),
            getString('questiontype', 'mod_playervideo'),
            getString('qtypemultichoice', 'mod_playervideo'),
            getString('qtypeessay', 'mod_playervideo'),
            getString('generate', 'mod_playervideo'),
        ]);
        const sub = document.getElementById('playervideo-question-subpanel');
        sub.innerHTML = `
            <label class="playervideo-field-label" for="playervideo-ai-context">${escapeHtml(contextlabel)}</label>
            <textarea class="form-control mb-2" id="playervideo-ai-context" rows="2"></textarea>
            <label class="playervideo-field-label" for="playervideo-ai-qtype">${escapeHtml(qtypelabel)}</label>
            <select class="form-select mb-2" id="playervideo-ai-qtype">
                <option value="multichoice">${escapeHtml(mclabel)}</option>
                <option value="essay">${escapeHtml(essaylabel)}</option>
            </select>
            <button type="button" class="btn btn-primary mb-2" id="playervideo-ai-generate-btn">
                ${escapeHtml(generatebuttonlabel)}
            </button>
        `;

        document.getElementById('playervideo-ai-generate-btn').addEventListener('click', async(event) => {
            const button = event.target;
            button.disabled = true;
            try {
                const timestamp = existing ? existing.timestamp : pendingTimestamp;
                const result = await call('mod_playervideo_generate_question_ai', {
                    playervideoid: editorData.playervideoid,
                    timestamp,
                    context: document.getElementById('playervideo-ai-context').value,
                    qtype: document.getElementById('playervideo-ai-qtype').value,
                });
                selectedQuestionId = result.questionid;
                const answerslist = result.answers.map(
                    (a) => `<li>${a.correct ? '<strong>' : ''}${a.text}${a.correct ? '</strong>' : ''}</li>`
                ).join('');
                selectedQuestionPreview = result.questiontext
                    + (answerslist ? `<ul class="mb-0">${answerslist}</ul>` : '');
                updateSelectedQuestionDisplay();
            } catch (error) {
                showError(error);
            } finally {
                button.disabled = false;
            }
        });
    };

    document.getElementById('playervideo-tab-create').addEventListener('click', function() {
        activetab = 'create';
        this.setAttribute('aria-selected', 'true');
        document.getElementById('playervideo-tab-bank').setAttribute('aria-selected', 'false');
        document.getElementById('playervideo-tab-ai').setAttribute('aria-selected', 'false');
        renderCreateSubpanel();
    });
    document.getElementById('playervideo-tab-bank').addEventListener('click', function() {
        activetab = 'bank';
        this.setAttribute('aria-selected', 'true');
        document.getElementById('playervideo-tab-create').setAttribute('aria-selected', 'false');
        document.getElementById('playervideo-tab-ai').setAttribute('aria-selected', 'false');
        renderBankSubpanel();
    });
    document.getElementById('playervideo-tab-ai').addEventListener('click', function() {
        activetab = 'ai';
        this.setAttribute('aria-selected', 'true');
        document.getElementById('playervideo-tab-create').setAttribute('aria-selected', 'false');
        document.getElementById('playervideo-tab-bank').setAttribute('aria-selected', 'false');
        renderAiSubpanel();
    });

    if (activetab === 'bank') {
        renderBankSubpanel();
    } else {
        await renderCreateSubpanel();
    }
    updateSelectedQuestionDisplay();

    renderFooter(async() => {
        const weight = parseFloat(document.getElementById('playervideo-weight').value) || 1;
        try {
            let questionid = selectedQuestionId;
            if (activetab === 'create') {
                questionid = await createQuestion();
            } else if (questionid === 0) {
                Notification.alert('', await getString('error_noquestionselected', 'mod_playervideo'));
                return;
            }
            await call('mod_playervideo_save_interaction', {
                playervideoid: editorData.playervideoid,
                interactionid: existing ? existing.id : 0,
                timestamp: existing ? existing.timestamp : pendingTimestamp,
                type: 'question',
                questionid,
                weight,
            });
            if (activetab === 'create' || activetab === 'ai') {
                Notification.addNotification({
                    message: await getString(
                        activetab === 'ai' ? 'questiongeneratedandadded' : 'questioncreatedandadded',
                        'mod_playervideo'
                    ),
                    type: 'success',
                });
            }
            await loadInteractions();
            await renderPicker();
        } catch (error) {
            showError(error);
        }
    });
};

/**
 * Dispatches to the right editor form for the given type.
 *
 * @param {string} type 'note' | 'question' | 'poll'.
 * @param {object|null} existing The interaction being edited, or null when creating new.
 * @returns {Promise<void>}
 */
const renderEditor = (type, existing) => {
    if (type === 'note') {
        return renderNoteEditor(existing);
    }
    if (type === 'poll') {
        return renderPollEditor(existing);
    }
    return renderQuestionEditor(existing);
};

/**
 * Computes the video timestamp for a click at the given clientX on the timeline element.
 *
 * @param {number} clientx Pointer clientX.
 * @returns {number} Timestamp, in seconds.
 */
const timestampForClientX = (clientx) => {
    const rect = document.getElementById('playervideo-timeline').getBoundingClientRect();
    const percent = ((clientx - rect.left) / rect.width) * 100;
    return timeForPercent(percent);
};

/**
 * Updates the play/pause button's icon and label to reflect the given state.
 *
 * @param {boolean} playing Whether the video is now playing.
 * @returns {Promise<void>}
 */
const setPlayingState = async(playing) => {
    isPlaying = playing;
    const button = document.getElementById('playervideo-playpause-btn');
    button.classList.toggle('is-playing', playing);
    button.setAttribute('aria-label', await getString(playing ? 'pause' : 'play', 'mod_playervideo'));
};

/**
 * Toggles play/pause on the active adapter — the only play/pause control now that native
 * player chrome is hidden (see player_youtube/vimeo/html5).
 *
 * @returns {Promise<void>}
 */
const togglePlayPause = async() => {
    if (!adapter) {
        return;
    }
    if (isPlaying) {
        adapter.pause();
        await setPlayingState(false);
    } else {
        adapter.play();
        await setPlayingState(true);
    }
};

/**
 * Wires clicking empty timeline space to seek the video there — the timeline bar is the only
 * scrub surface now that the native player controls are hidden (see player_youtube/vimeo/html5).
 * A new marker is placed at the current position via the separate "Add here" button instead of
 * by clicking the bar, so a plain click can mean one thing only.
 */
const initTimelineClick = () => {
    document.getElementById('playervideo-timeline').addEventListener('click', (event) => {
        if (event.target.closest('.playervideo-marker, .playervideo-trim-handle') || !adapter) {
            return;
        }
        const time = timestampForClientX(event.clientX);
        adapter.seek(time);
        document.getElementById('playervideo-playhead').style.left = `${percentForTime(time)}%`;
        document.getElementById('playervideo-ruler-start').textContent = formatTime(time);
    });

    document.getElementById('playervideo-add-here-btn').addEventListener('click', async() => {
        if (adapter) {
            pendingTimestamp = await adapter.getCurrentTime();
        }
        renderPicker();
    });

    document.getElementById('playervideo-generate-batch-btn').addEventListener('click', openBatchGenerateModal);
    document.getElementById('playervideo-manage-captions-btn').addEventListener('click', openCaptionsModal);
    document.getElementById('playervideo-manage-disummary-btn').addEventListener('click', openDiSummaryModal);

    document.getElementById('playervideo-playpause-btn').addEventListener('click', togglePlayPause);
};

/**
 * Wires mouse dragging and keyboard nudging for one trim handle.
 *
 * @param {string} elementid Handle element id.
 * @param {Function} getvalue Returns the handle's current value (trimstart or trimend).
 * @param {Function} setvalue Sets the handle's new value.
 */
const initTrimHandle = (elementid, getvalue, setvalue) => {
    const handle = document.getElementById(elementid);

    handle.addEventListener('mousedown', (downevent) => {
        downevent.preventDefault();
        const startvalue = getvalue();

        const onMove = (moveevent) => {
            const timeline = document.getElementById('playervideo-timeline');
            const rect = timeline.getBoundingClientRect();
            const percent = ((moveevent.clientX - rect.left) / rect.width) * 100;
            setvalue(timeForPercent(percent));
            renderTrim();
        };

        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            if (Math.abs(getvalue() - startvalue) > TRIM_DRAG_EPSILON) {
                saveTrim();
            }
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });

    handle.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }
        event.preventDefault();
        const step = (event.shiftKey ? 5 : 1) * TRIM_KEY_STEP;
        const delta = event.key === 'ArrowRight' ? step : -step;
        setvalue(Math.max(0, Math.min(duration, getvalue() + delta)));
        renderTrim();
        saveTrim();
    });
};

/**
 * Reads the editor data island embedded server-side by interactions.php.
 *
 * @returns {object}
 */
const readEditorData = () => JSON.parse(document.getElementById('playervideo-editor-data').textContent);

/**
 * Initialises the timeline management screen.
 */
export const init = async() => {
    editorData = readEditorData();
    trimstart = editorData.trimstart;
    trimend = editorData.trimend;

    try {
        adapter = await createAdapterForSource();
        await adapter.ready();
        duration = await adapter.getDuration();
    } catch (error) {
        showError(error);
        duration = 0;
    }

    document.getElementById('playervideo-ruler-end').textContent = formatTime(duration);
    await setPlayingState(false);

    if (adapter) {
        adapter.onTimeUpdate((time) => {
            document.getElementById('playervideo-playhead').style.left = `${percentForTime(time)}%`;
            document.getElementById('playervideo-ruler-start').textContent = formatTime(time);
        });
    }

    initTimelineClick();
    initTrimHandle(
        'playervideo-trim-handle-start',
        () => trimstart ?? 0,
        (value) => {
            trimstart = value;
        }
    );
    initTrimHandle(
        'playervideo-trim-handle-end',
        () => trimend ?? duration,
        (value) => {
            trimend = value;
        }
    );

    await loadInteractions();
    await renderPicker();
};
