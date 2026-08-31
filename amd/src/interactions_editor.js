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
 * Timeline management screen: trim window, and creating/editing/deleting interactions
 * (questions and notes), either pulled from the Question Bank or authored on the spot.
 *
 * @module     mod_playervideo/interactions_editor
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getString} from 'core/str';

let playervideoid = 0;
let selectedQuestionId = 0;
let selectedQuestionPreview = '';

/**
 * Calls a single mod_playervideo Web Service method.
 *
 * @param {string} methodname Web service method name.
 * @param {object} args Arguments.
 * @returns {Promise<object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

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
 * Renders the interactions table body from the given interaction list.
 *
 * @param {Array<object>} interactions Interactions, ordered by timestamp.
 */
const renderTable = (interactions) => {
    const body = document.getElementById('playervideo-interactions-body');
    const empty = document.getElementById('playervideo-empty-list');
    body.innerHTML = '';

    if (interactions.length === 0) {
        empty.hidden = false;
        return;
    }
    empty.hidden = true;

    interactions.forEach((interaction) => {
        const row = document.createElement('tr');

        const preview = interaction.type === 'question' ? interaction.questionpreview : interaction.notetext;
        row.innerHTML = `
            <td>${interaction.timestamp}</td>
            <td>${escapeHtml(interaction.type)}</td>
            <td>${preview}</td>
            <td>${interaction.type === 'question' ? interaction.weight : ''}</td>
            <td>
                <button type="button" class="btn btn-sm btn-secondary" data-action="edit">
                    ${escapeHtml('...')}
                </button>
                <button type="button" class="btn btn-sm btn-danger" data-action="delete">
                    ${escapeHtml('x')}
                </button>
            </td>
        `;
        row.querySelector('[data-action="edit"]').addEventListener('click', () => populateFormForEdit(interaction));
        row.querySelector('[data-action="delete"]').addEventListener('click', () => deleteInteraction(interaction.id));
        body.appendChild(row);
    });
};

/**
 * Loads the trim window and interactions from the server and renders both.
 *
 * @returns {Promise<void>}
 */
const loadInteractions = async() => {
    try {
        const data = await call('mod_playervideo_get_interactions', {playervideoid});
        document.getElementById('playervideo-trimstart').value = data.trimstart ?? '';
        document.getElementById('playervideo-trimend').value = data.trimend ?? '';
        renderTable(data.interactions);
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Resets the interaction form back to "create new", clearing every field.
 */
const resetForm = () => {
    document.getElementById('playervideo-interactionid').value = '0';
    document.getElementById('playervideo-timestamp').value = '';
    document.getElementById('playervideo-type').value = 'note';
    document.getElementById('playervideo-notetext').value = '';
    document.getElementById('playervideo-weight').value = '1';
    document.getElementById('playervideo-questiontext').value = '';
    document.getElementById('playervideo-search').value = '';
    document.getElementById('playervideo-search-results').innerHTML = '';
    document.getElementById('playervideo-answers').innerHTML = '';
    document.getElementById('playervideo-cancel-edit').hidden = true;
    selectedQuestionId = 0;
    selectedQuestionPreview = '';
    updateSelectedQuestionDisplay();
    addAnswerRow();
    addAnswerRow();
    toggleTypeFields();
};

/**
 * Shows/hides the note vs question fields based on the currently selected type.
 */
const toggleTypeFields = () => {
    const type = document.getElementById('playervideo-type').value;
    document.getElementById('playervideo-note-fields').hidden = type !== 'note';
    document.getElementById('playervideo-question-fields').hidden = type !== 'question';
    document.getElementById('playervideo-weight-group').hidden = type !== 'question';
};

/**
 * Adds one empty answer row to the multichoice "create here" form.
 */
const addAnswerRow = () => {
    const container = document.getElementById('playervideo-answers');
    const row = document.createElement('div');
    row.className = 'input-group mb-1 playervideo-answer-row';
    row.innerHTML = `
        <div class="input-group-text">
            <input type="checkbox" class="playervideo-answer-correct" aria-label="correct">
        </div>
        <input type="text" class="form-control playervideo-answer-text">
        <button type="button" class="btn btn-outline-danger" data-action="remove">&times;</button>
    `;
    row.querySelector('[data-action="remove"]').addEventListener('click', () => row.remove());
    container.appendChild(row);
};

/**
 * Updates the read-only preview shown once a question has been picked (from either tab).
 */
const updateSelectedQuestionDisplay = () => {
    const el = document.getElementById('playervideo-selected-question');
    if (selectedQuestionId > 0) {
        el.hidden = false;
        el.innerHTML = selectedQuestionPreview;
    } else {
        el.hidden = true;
        el.innerHTML = '';
    }
};

/**
 * Searches the Question Bank as the teacher types, and renders clickable results.
 *
 * @param {string} query Free-text search.
 * @returns {Promise<void>}
 */
const searchQuestions = async(query) => {
    const results = document.getElementById('playervideo-search-results');
    results.innerHTML = '';
    if (query.trim() === '') {
        return;
    }
    try {
        const data = await call('mod_playervideo_search_questions', {playervideoid, query, limit: 20});
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
        Notification.exception(error);
    }
};

/**
 * Creates a question from the "create here" tab, via mod_playervideo_create_question, and
 * selects it — the teacher still needs to press the main Save button afterwards to actually
 * place it on the timeline as an interaction.
 *
 * @returns {Promise<void>}
 */
const createQuestion = async() => {
    const qtype = document.getElementById('playervideo-qtype').value;
    const questiontext = document.getElementById('playervideo-questiontext').value;

    const args = {
        playervideoid,
        qtype,
        questiontext,
        name: '',
        single: true,
        correctanswer: true,
        answers: [],
    };

    if (qtype === 'truefalse') {
        args.correctanswer = document.getElementById('playervideo-tf-true').checked;
    } else {
        args.single = document.getElementById('playervideo-single').checked;
        args.answers = Array.from(document.querySelectorAll('.playervideo-answer-row')).map((row) => ({
            text: row.querySelector('.playervideo-answer-text').value,
            correct: row.querySelector('.playervideo-answer-correct').checked,
        }));
    }

    try {
        const result = await call('mod_playervideo_create_question', args);
        selectedQuestionId = result.questionid;
        selectedQuestionPreview = escapeHtml(questiontext);
        updateSelectedQuestionDisplay();
        Notification.addNotification({
            message: await getString('questioncreated', 'mod_playervideo'),
            type: 'success',
        });
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Populates the form with an existing interaction's data, for editing.
 *
 * @param {object} interaction Interaction, as returned by mod_playervideo_get_interactions.
 */
const populateFormForEdit = (interaction) => {
    document.getElementById('playervideo-interactionid').value = interaction.id;
    document.getElementById('playervideo-timestamp').value = interaction.timestamp;
    document.getElementById('playervideo-type').value = interaction.type;
    document.getElementById('playervideo-cancel-edit').hidden = false;
    toggleTypeFields();

    if (interaction.type === 'note') {
        document.getElementById('playervideo-notetext').value = interaction.notetext;
    } else {
        document.getElementById('playervideo-weight').value = interaction.weight;
        selectedQuestionId = interaction.questionid;
        selectedQuestionPreview = interaction.questionpreview;
        updateSelectedQuestionDisplay();
    }

    document.getElementById('playervideo-form-heading').scrollIntoView({behavior: 'smooth'});
};

/**
 * Deletes an interaction, after confirming with the teacher.
 *
 * @param {number} interactionid Interaction id.
 * @returns {Promise<void>}
 */
const deleteInteraction = async(interactionid) => {
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
                    playervideoid,
                    interactionid,
                    'delete': true,
                });
                await loadInteractions();
            } catch (error) {
                Notification.exception(error);
            }
        });
        modal.show();
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Handles the main form submit: saves (creates or updates) an interaction.
 *
 * @param {SubmitEvent} event Form submit event.
 * @returns {Promise<void>}
 */
const onSubmit = async(event) => {
    event.preventDefault();

    const type = document.getElementById('playervideo-type').value;
    if (type === 'question' && selectedQuestionId === 0) {
        Notification.alert('', await getString('error_noquestionselected', 'mod_playervideo'));
        return;
    }

    const args = {
        playervideoid,
        interactionid: parseInt(document.getElementById('playervideo-interactionid').value, 10),
        timestamp: parseFloat(document.getElementById('playervideo-timestamp').value),
        type,
        questionid: type === 'question' ? selectedQuestionId : 0,
        notetext: type === 'note' ? document.getElementById('playervideo-notetext').value : '',
        weight: type === 'question' ? parseFloat(document.getElementById('playervideo-weight').value) : 1,
        'delete': false,
    };

    try {
        await call('mod_playervideo_save_interaction', args);
        resetForm();
        await loadInteractions();
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Saves the trim window.
 *
 * @returns {Promise<void>}
 */
const onSaveTrim = async() => {
    const startvalue = document.getElementById('playervideo-trimstart').value;
    const endvalue = document.getElementById('playervideo-trimend').value;

    try {
        await call('mod_playervideo_save_trim', {
            playervideoid,
            trimstart: startvalue === '' ? null : parseFloat(startvalue),
            trimend: endvalue === '' ? null : parseFloat(endvalue),
        });
        Notification.addNotification({
            message: await getString('trimsaved', 'mod_playervideo'),
            type: 'success',
        });
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Initialises the timeline management screen.
 *
 * @param {number} instanceid PlayerVideo instance id.
 */
export const init = (instanceid) => {
    playervideoid = instanceid;

    document.getElementById('playervideo-type').addEventListener('change', toggleTypeFields);
    document.getElementById('playervideo-qtype').addEventListener('change', () => {
        const qtype = document.getElementById('playervideo-qtype').value;
        document.getElementById('playervideo-multichoice-fields').hidden = qtype !== 'multichoice';
        document.getElementById('playervideo-truefalse-fields').hidden = qtype !== 'truefalse';
    });
    document.getElementById('playervideo-add-answer').addEventListener('click', addAnswerRow);
    document.getElementById('playervideo-create-question-btn').addEventListener('click', createQuestion);
    document.getElementById('playervideo-save-trim').addEventListener('click', onSaveTrim);
    document.getElementById('playervideo-interaction-form').addEventListener('submit', onSubmit);
    document.getElementById('playervideo-cancel-edit').addEventListener('click', resetForm);

    let searchTimeout = null;
    document.getElementById('playervideo-search').addEventListener('input', (event) => {
        clearTimeout(searchTimeout);
        const query = event.target.value;
        searchTimeout = setTimeout(() => searchQuestions(query), 300);
    });

    resetForm();
    loadInteractions();
};
