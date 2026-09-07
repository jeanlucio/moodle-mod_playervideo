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
 * final grade, time watched, completion).
 *
 * @module     mod_playervideo/report
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';
import {escapeHtml} from 'mod_playervideo/escape';

/**
 * Calls one mod_playervideo Web Service method directly.
 *
 * @param {string} methodname Web service method name.
 * @param {object} args Arguments.
 * @returns {Promise<object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

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
 * Renders the per-question table via the mod_playervideo/report_questions template — the escape
 * decision lives in the template ({{{questiontext}}} is the one trusted-HTML field, format_text
 * '\''d server-side), not in a hand-built string.
 *
 * @param {Array} rows mod_playervideo_get_report's "byquestion" array.
 * @returns {Promise<void>}
 */
const renderQuestionTable = async(rows) => {
    const ismultichoicetype = (qtype) => qtype === 'multichoice' || qtype === 'truefalse';
    const context = {
        rows: rows.map((row) => ({
            timedisplay: formatTime(row.timestamp),
            questiontext: row.questiontext,
            qtype: row.qtype,
            totalresponses: row.totalresponses,
            correctdisplay: ismultichoicetype(row.qtype) ? `${row.percentcorrect}%` : '\u2014',
            pendingcount: row.pendingcount,
            gradedcount: row.gradedcount,
        })),
    };
    const {html} = await Templates.renderForPromise('mod_playervideo/report_questions', context);
    document.getElementById('playervideo-report-questions').innerHTML = html;
};

/**
 * Renders the per-student table.
 *
 * @param {Array} rows mod_playervideo_get_report's "bystudent" array.
 * @returns {Promise<void>}
 */
const renderStudentTable = async(rows) => {
    const [yesstr, nostr] = await Promise.all([
        getString('yes', 'moodle'),
        getString('no', 'moodle'),
    ]);
    const context = {
        rows: rows.map((row) => ({
            fullname: row.fullname,
            attemptscount: row.attemptscount,
            gradedisplay: row.finalgrade !== null ? Math.round(row.finalgrade * 100) / 100 : '\u2014',
            watcheddisplay: `${Math.round(row.watchedpct)}%`,
            completeddisplay: row.completed ? yesstr : nostr,
        })),
    };
    const {html} = await Templates.renderForPromise('mod_playervideo/report_students', context);
    document.getElementById('playervideo-report-students').innerHTML = html;
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
 * Renders the class-wide engagement timeline: a bar per region of the playback
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
