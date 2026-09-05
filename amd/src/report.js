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
 * Analytics dashboard: per-question (% correct, correction status) and per-student (attempts,
 * final grade, time watched, completion) — see the plugin SCOPE, "Analytics".
 *
 * @module     mod_playervideo/report
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString} from 'core/str';

/**
 * Calls one mod_playervideo Web Service method directly.
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
 * Renders the per-question table.
 *
 * @param {Array} rows mod_playervideo_get_report's "byquestion" array.
 * @returns {Promise<void>}
 */
const renderQuestionTable = async(rows) => {
    const container = document.getElementById('playervideo-report-questions');

    if (rows.length === 0) {
        container.textContent = await getString('noquestionreport', 'mod_playervideo');
        return;
    }

    const [coltime, colquestion, coltype, colresponses, colcorrect, colpending, colgraded] = await Promise.all([
        getString('columntime', 'mod_playervideo'),
        getString('columnquestion', 'mod_playervideo'),
        getString('interactiontype', 'mod_playervideo'),
        getString('columnresponses', 'mod_playervideo'),
        getString('columncorrect', 'mod_playervideo'),
        getString('columnpending', 'mod_playervideo'),
        getString('columngraded', 'mod_playervideo'),
    ]);

    const ismultichoicetype = (qtype) => qtype === 'multichoice' || qtype === 'truefalse';

    const bodyrows = rows.map((row) => `
        <tr>
            <td class="mono">${formatTime(row.timestamp)}</td>
            <td>${row.questiontext}</td>
            <td>${escapeHtml(row.qtype)}</td>
            <td>${row.totalresponses}</td>
            <td>${ismultichoicetype(row.qtype) ? `${row.percentcorrect}%` : '—'}</td>
            <td>${row.pendingcount}</td>
            <td>${row.gradedcount}</td>
        </tr>
    `).join('');

    container.innerHTML = `
        <table class="table table-sm">
            <thead>
                <tr>
                    <th scope="col">${escapeHtml(coltime)}</th>
                    <th scope="col">${escapeHtml(colquestion)}</th>
                    <th scope="col">${escapeHtml(coltype)}</th>
                    <th scope="col">${escapeHtml(colresponses)}</th>
                    <th scope="col">${escapeHtml(colcorrect)}</th>
                    <th scope="col">${escapeHtml(colpending)}</th>
                    <th scope="col">${escapeHtml(colgraded)}</th>
                </tr>
            </thead>
            <tbody>${bodyrows}</tbody>
        </table>
    `;
};

/**
 * Renders the per-student table.
 *
 * @param {Array} rows mod_playervideo_get_report's "bystudent" array.
 * @returns {Promise<void>}
 */
const renderStudentTable = async(rows) => {
    const container = document.getElementById('playervideo-report-students');

    if (rows.length === 0) {
        container.textContent = await getString('nostudentreport', 'mod_playervideo');
        return;
    }

    const [colstudent, colattempts, gradelabel, colwatched, colcompleted, yesstr, nostr] = await Promise.all([
        getString('columnstudent', 'mod_playervideo'),
        getString('columnattempts', 'mod_playervideo'),
        getString('gradelabel', 'mod_playervideo'),
        getString('columnwatched', 'mod_playervideo'),
        getString('columncompleted', 'mod_playervideo'),
        getString('yes', 'moodle'),
        getString('no', 'moodle'),
    ]);

    const bodyrows = rows.map((row) => `
        <tr>
            <td>${escapeHtml(row.fullname)}</td>
            <td>${row.attemptscount}</td>
            <td>${row.finalgrade !== null ? Math.round(row.finalgrade * 100) / 100 : '—'}</td>
            <td>${Math.round(row.watchedpct)}%</td>
            <td>${row.completed ? escapeHtml(yesstr) : escapeHtml(nostr)}</td>
        </tr>
    `).join('');

    container.innerHTML = `
        <table class="table table-sm">
            <thead>
                <tr>
                    <th scope="col">${escapeHtml(colstudent)}</th>
                    <th scope="col">${escapeHtml(colattempts)}</th>
                    <th scope="col">${escapeHtml(gradelabel)}</th>
                    <th scope="col">${escapeHtml(colwatched)}</th>
                    <th scope="col">${escapeHtml(colcompleted)}</th>
                </tr>
            </thead>
            <tbody>${bodyrows}</tbody>
        </table>
    `;
};

/**
 * Formats a bucket index as the m:ss range of video it represents.
 *
 * @param {object} engagement mod_playervideo_get_report's "engagement" object.
 * @param {number} index Bucket index.
 * @returns {string}
 */
const formatBucketRange = (engagement, index) => {
    const start = engagement.windowstart + (index * engagement.bucketlength);
    const end = start + engagement.bucketlength;
    return `${formatTime(start)}–${formatTime(end)}`;
};

/**
 * Renders the class-wide engagement timeline (Fase 10b): a bar per region of the playback
 * window, plus a plain-text summary of the three highlighted regions — the highlight is never
 * conveyed by colour alone, matching the plugin's own accessibility rules.
 *
 * @param {object} engagement mod_playervideo_get_report's "engagement" object.
 * @returns {Promise<void>}
 */
const renderEngagement = async(engagement) => {
    const container = document.getElementById('playervideo-report-engagement');

    if (engagement.mostwatchedbucket === null) {
        container.textContent = await getString('noengagementreport', 'mod_playervideo');
        return;
    }

    const barlabel = await getString('engagementbarlabel', 'mod_playervideo');
    const peak = Math.max(...engagement.buckets) || 1;

    const bars = engagement.buckets.map((seconds, index) => {
        const heightpct = Math.round((seconds / peak) * 100);
        const classes = ['playervideo-engagement-bar'];
        if (index === engagement.mostwatchedbucket) {
            classes.push('is-mostwatched');
        }
        if (index === engagement.leastwatchedbucket) {
            classes.push('is-leastwatched');
        }
        if (index === engagement.dropoffbucket) {
            classes.push('is-dropoff');
        }
        return `<div class="${classes.join(' ')}" style="height: ${heightpct}%"
            title="${formatBucketRange(engagement, index)}"></div>`;
    }).join('');

    const summaryitems = [];
    summaryitems.push(await getString(
        'engagementmostwatched', 'mod_playervideo', formatBucketRange(engagement, engagement.mostwatchedbucket)
    ));
    summaryitems.push(await getString(
        'engagementleastwatched', 'mod_playervideo', formatBucketRange(engagement, engagement.leastwatchedbucket)
    ));
    if (engagement.dropoffbucket !== null) {
        summaryitems.push(await getString(
            'engagementdropoff', 'mod_playervideo', formatBucketRange(engagement, engagement.dropoffbucket)
        ));
    }

    container.innerHTML = `
        <div class="playervideo-engagement-bars" role="img" aria-label="${escapeHtml(barlabel)}">${bars}</div>
        <ul class="playervideo-engagement-summary">
            ${summaryitems.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}
        </ul>
    `;
};

/**
 * Initialises the analytics dashboard for one instance.
 *
 * @param {number} instanceid PlayerVideo instance id.
 * @returns {Promise<void>}
 */
export const init = async(instanceid) => {
    try {
        const result = await call('mod_playervideo_get_report', {playervideoid: instanceid});
        await renderQuestionTable(result.byquestion);
        await renderStudentTable(result.bystudent);
        await renderEngagement(result.engagement);
    } catch (error) {
        Notification.exception(error);
    }
};
