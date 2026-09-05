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
 * interaction / auto-save / resume / anti-skip / finish / review flow. The three player_*.js
 * modules only know how to talk to their own provider API; this module is the only one that
 * knows the mod_playervideo attempt lifecycle.
 *
 * @module     mod_playervideo/player
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Modal from 'core/modal';
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

/** @var {number} Video duration, in seconds, once known — drives the timeline bar's math. */
let duration = 0;

/** @var {boolean} Whether the video is currently playing, tracked locally to drive the play/pause button. */
let isPlaying = false;

/** @var {boolean} Whether the timeline's click-to-seek should ignore anti-skip (review mode). */
let seekUnrestricted = false;

/**
 * @var {boolean} True while startReview() is driving playback. attemptId stays 0 throughout a
 * review started before the student's own attempt began (the common case, since the review
 * buttons and the not-yet-started player are visible together) — this flag is what keeps
 * togglePlayPause() from mistaking a reviewer's play/pause click for the first-play trigger
 * that opens a real attempt.
 */
let reviewing = false;

/**
 * @var {Array} Manually authored captions for this instance, parsed once when the player loads
 * — each entry shaped {lang, cues: [{start, end, text}, ...]}.
 */
let manualCaptions = [];

/** @var {object|null} The manual caption currently selected, or null when off/native is active. */
let activeManualCaption = null;

/**
 * Announces a short status change to screen-reader users via the polite aria-live region,
 * without stealing visual focus.
 *
 * @param {string} message Text to announce.
 */
const announce = (message) => {
    document.getElementById('playervideo-announcer').textContent = message;
};

/**
 * Hides the pre-attempt info panel (previous attempts, notices, accessibility links) once the
 * student's first play click actually opens an attempt — the player screen itself is visible
 * from the first page render and is never toggled by this.
 */
const hideIdleInfo = () => {
    const panel = document.getElementById('playervideo-idle-info');
    if (panel) {
        panel.hidden = true;
    }
};

/**
 * Shows the player screen or the attempt summary screen, hiding the other one.
 *
 * @param {string} name One of 'player', 'summary'.
 */
const showScreen = (name) => {
    document.getElementById('playervideo-player-screen').hidden = name !== 'player';
    document.getElementById('playervideo-summary-screen').hidden = name !== 'summary';
};

/**
 * Shows a business-rule error (a deliberate moodle_exception, using this plugin's own
 * error_* string convention) via a plain alert, or an unexpected one via the generic
 * AJAX-exception dialog.
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
 * Formats a number of seconds as m:ss, for the timeline ruler.
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
 * Converts a video position to a percentage along the timeline bar.
 *
 * @param {number} seconds Video position, in seconds.
 * @returns {number}
 */
const percentForTime = (seconds) => {
    if (duration <= 0) {
        return 0;
    }
    return Math.min(100, Math.max(0, (seconds / duration) * 100));
};

/**
 * Converts a click's clientX on the timeline bar to a video position, in seconds.
 *
 * @param {number} clientx Mouse event clientX.
 * @returns {number}
 */
const timestampForClientX = (clientx) => {
    const rect = document.getElementById('playervideo-timeline').getBoundingClientRect();
    const percent = ((clientx - rect.left) / rect.width) * 100;
    return (Math.min(100, Math.max(0, percent)) / 100) * duration;
};

/**
 * Renders every activity interaction as a non-interactive marker on the timeline — a preview of
 * what's ahead, matching the EdPuzzle convention the plugin's design was aligned on with the
 * author. Unlike the teacher editor's markers, these never open anything on click.
 */
const renderMarkers = () => {
    const container = document.getElementById('playervideo-markers');
    container.innerHTML = '';
    playerData.interactions.forEach((interaction) => {
        const marker = document.createElement('div');
        marker.className = `playervideo-marker playervideo-marker-readonly playervideo-marker-${interaction.type}`;
        marker.style.left = `${percentForTime(interaction.timestamp)}%`;
        container.appendChild(marker);
    });
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
 * player chrome is hidden (see player_youtube/vimeo/html5). Before any attempt exists yet, the
 * video is already loaded and paused (see preparePlayer()); this first press is what actually
 * opens the attempt, via beginAttempt(), instead of a separate "start" button.
 *
 * @returns {Promise<void>}
 */
const togglePlayPause = async() => {
    if (!adapter) {
        return;
    }
    if (attemptId === 0 && !reviewing) {
        await beginAttempt();
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
 * Parses a VTT document into a flat list of cues — deliberately minimal (cue identifiers and
 * styling are ignored; a cue is exactly {start, end, text}), since this only ever reads back
 * what caption_service.php itself wrote or passed through, never an arbitrary third-party file.
 *
 * @param {string} content A VTT document.
 * @returns {Array} Cues, in file order.
 */
const parseVtt = (content) => {
    const timeline = /(\d{2}):(\d{2}):(\d{2})[.,](\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})[.,](\d{3})/;
    const toSeconds = (h, m, s, ms) => (Number(h) * 3600) + (Number(m) * 60) + Number(s) + (Number(ms) / 1000);

    const cues = [];
    content.replace(/\r/g, '').split(/\n\n+/).forEach((block) => {
        const lines = block.split('\n').filter((line) => line.trim() !== '');
        const timelineindex = lines.findIndex((line) => timeline.test(line));
        if (timelineindex === -1) {
            return;
        }
        const match = lines[timelineindex].match(timeline);
        const text = lines.slice(timelineindex + 1).join(' ').trim();
        if (text === '') {
            return;
        }
        cues.push({
            start: toSeconds(match[1], match[2], match[3], match[4]),
            end: toSeconds(match[5], match[6], match[7], match[8]),
            text,
        });
    });
    return cues;
};

/**
 * Shows the caption text active at the given time, for whichever manual caption is currently
 * selected — a no-op while a native track is active (the provider renders that one itself) or
 * while captions are off.
 *
 * @param {number} time Current playback position, in seconds.
 */
const updateCaptionOverlay = (time) => {
    const overlay = document.getElementById('playervideo-caption-overlay');
    if (!overlay) {
        return;
    }
    const cue = activeManualCaption
        ? activeManualCaption.cues.find((item) => time >= item.start && time <= item.end)
        : null;
    overlay.textContent = cue ? cue.text : '';
    overlay.hidden = !cue;
};

/**
 * Applies a caption selector choice: "" (off), "native:<code>" (a track the source adapter
 * itself renders) or "manual:<lang>" (rendered by this module's own overlay, via
 * updateCaptionOverlay). Only one of the two rendering paths is ever active at a time.
 *
 * @param {string} value The selected <option> value.
 */
const setCaptionSelection = (value) => {
    if (adapter.setCaptionTrack) {
        adapter.setCaptionTrack(null);
    }
    activeManualCaption = null;
    document.getElementById('playervideo-caption-overlay').hidden = true;

    if (value.startsWith('native:') && adapter.setCaptionTrack) {
        adapter.setCaptionTrack(value.slice('native:'.length));
    } else if (value.startsWith('manual:')) {
        const lang = value.slice('manual:'.length);
        activeManualCaption = manualCaptions.find((caption) => caption.lang === lang) ?? null;
    }

    // The transcript panel always mirrors whichever caption is actually selected, so a search
    // already typed never silently keeps showing stale lines from a caption the student just
    // switched away from.
    refreshTranscriptPanel();
};

/**
 * Populates the caption selector with the merge of native tracks (read live from the source
 * adapter) and manually authored ones (mod_playervideo_get_captions) — never combined into one
 * stored list, each read from where it already lives. A source with no captions at all (e.g.
 * HTML5) still gets the "off" option alone.
 *
 * @returns {Promise<void>}
 */
const loadCaptionOptions = async() => {
    const select = document.getElementById('playervideo-caption-select');
    if (!select) {
        return;
    }

    const [captionresult, nativetracks] = await Promise.all([
        call('mod_playervideo_get_captions', {playervideoid: playerData.playervideoid}).catch(() => ({captions: []})),
        adapter.getCaptionTracks ? adapter.getCaptionTracks().catch(() => []) : Promise.resolve([]),
    ]);

    manualCaptions = captionresult.captions.map((caption) => ({
        lang: caption.lang,
        cues: parseVtt(caption.content),
    }));

    const offlabel = await getString('subtitlesoff', 'mod_playervideo');
    const nativeoptions = nativetracks.map(
        (track) => `<option value="native:${escapeHtmlAttribute(track.code)}">${escapeHtmlAttribute(track.label)}</option>`
    );
    const manualoptions = manualCaptions.map(
        (caption) => `<option value="manual:${escapeHtmlAttribute(caption.lang)}">${escapeHtmlAttribute(caption.lang)}</option>`
    );
    select.innerHTML = [`<option value="">${offlabel}</option>`, ...nativeoptions, ...manualoptions].join('');
    select.value = '';

    select.addEventListener('change', () => setCaptionSelection(select.value));
};

/**
 * Returns the cues currently backing the transcript panel: whichever manual caption is
 * actively selected, or the first available one if none is (captions off, or a native track
 * selected) — the panel always has something to show as long as at least one manual caption
 * exists, mirroring blind_mode_service::pick_caption_cues()'s own graceful fallback.
 *
 * @returns {Array} Cues, or an empty array if no manual caption exists at all.
 */
const getTranscriptCues = () => (activeManualCaption ?? manualCaptions[0])?.cues ?? [];

/**
 * Wraps every case-insensitive match of query inside text with <mark>, HTML-escaping
 * everything else first — text is always an already-known cue from this plugin's own captions,
 * never arbitrary third-party markup.
 *
 * @param {string} text Cue text.
 * @param {string} query Search query, already trimmed.
 * @returns {string} HTML-safe markup with matches wrapped in <mark>.
 */
const highlightMatch = (text, query) => {
    const escaped = escapeHtmlAttribute(text);
    if (query === '') {
        return escaped;
    }
    const pattern = new RegExp(query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'ig');
    return escaped.replace(pattern, (match) => `<mark>${match}</mark>`);
};

/**
 * Renders the transcript panel's cue list, filtered by the given search query — a cue whose
 * text does not contain the query (case-insensitive) is omitted outright, not just dimmed.
 *
 * @param {string} query Search query, as typed (not yet trimmed).
 * @returns {Promise<void>}
 */
const renderTranscriptList = async(query) => {
    const container = document.getElementById('playervideo-transcript-list');
    if (!container) {
        return;
    }
    const trimmed = query.trim();
    const cues = getTranscriptCues().filter(
        (cue) => trimmed === '' || cue.text.toLowerCase().includes(trimmed.toLowerCase())
    );

    if (cues.length === 0) {
        container.textContent = await getString('notranscriptmatches', 'mod_playervideo');
        return;
    }

    container.innerHTML = cues.map((cue) => `
        <button type="button" class="playervideo-transcript-line" data-start="${cue.start}">
            <span class="mono">${formatTime(cue.start)}</span>
            <span>${highlightMatch(cue.text, trimmed)}</span>
        </button>
    `).join('');
};

/**
 * Re-renders the transcript panel with whatever search query is currently typed — called
 * whenever the underlying caption changes, so the panel never keeps showing stale lines from a
 * caption the student just switched away from.
 *
 * @returns {Promise<void>}
 */
const refreshTranscriptPanel = () => {
    const search = document.getElementById('playervideo-transcript-search');
    return renderTranscriptList(search ? search.value : '');
};

/**
 * Attempts to move playback to targetTime, respecting the same anti-skip gate as the timeline
 * bar's own click-to-seek — shared by the timeline and the transcript panel's cue rows so the
 * two can never drift on what counts as an allowed seek.
 *
 * @param {number} targetTime Requested position, in seconds.
 * @returns {Promise<void>}
 */
const attemptSeek = async(targetTime) => {
    if (!seekUnrestricted && tracker && !tracker.canSeekTo(targetTime, playerData.allowseekahead)) {
        announce(await getString('error_seekaheadblocked', 'mod_playervideo'));
        return;
    }
    adapter.seek(targetTime);
    document.getElementById('playervideo-playhead').style.left = `${percentForTime(targetTime)}%`;
    document.getElementById('playervideo-ruler-start').textContent = formatTime(targetTime);
};

/**
 * Wires the transcript panel's toggle button and search box, and shows the toggle only when at
 * least one manual caption exists — there is nothing to search for a source with only native
 * (or no) captions, since this plugin never has access to a native track's own cue text.
 */
const initTranscriptPanel = () => {
    const toggle = document.getElementById('playervideo-transcript-toggle');
    const panel = document.getElementById('playervideo-transcript-panel');
    const search = document.getElementById('playervideo-transcript-search');
    if (!toggle || !panel || !search) {
        return;
    }

    if (manualCaptions.length === 0) {
        toggle.hidden = true;
        panel.hidden = true;
        return;
    }

    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        panel.hidden = expanded;
    });

    search.addEventListener('input', () => renderTranscriptList(search.value));

    document.getElementById('playervideo-transcript-list').addEventListener('click', (event) => {
        const line = event.target.closest('.playervideo-transcript-line');
        if (line) {
            attemptSeek(parseFloat(line.dataset.start));
        }
    });

    renderTranscriptList('');
};

/**
 * Wires the playback-speed selector to the active adapter. Always reset to 1x on load — a
 * chosen speed is a per-viewing convenience, not something remembered across attempts or
 * activities, so a returning student is never confused by a video restarting faster/slower
 * than they left it.
 */
const initSpeedControl = () => {
    const select = document.getElementById('playervideo-speed-select');
    if (!select) {
        return;
    }
    select.value = '1';
    select.addEventListener('change', () => adapter.setRate(parseFloat(select.value)));
};

/**
 * Toggles full-screen presentation of the player stage (the video plus its playback controls,
 * not just the video element alone) — falls back to the WebKit-prefixed API, still required by
 * Safari versions that predate the unprefixed Fullscreen API.
 *
 * @returns {Promise<void>}
 */
const toggleFullscreen = async() => {
    if (document.fullscreenElement || document.webkitFullscreenElement) {
        if (document.exitFullscreen) {
            await document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        }
        return;
    }

    const stage = document.getElementById('playervideo-stage');
    if (stage.requestFullscreen) {
        await stage.requestFullscreen();
    } else if (stage.webkitRequestFullscreen) {
        stage.webkitRequestFullscreen();
    }
};

/**
 * Updates the full-screen button's icon and label to reflect the current state — driven by the
 * fullscreenchange event so it also stays correct when the browser itself exits full screen
 * (e.g. the Esc key), not only when the button itself is clicked.
 *
 * @returns {Promise<void>}
 */
const updateFullscreenButton = async() => {
    const button = document.getElementById('playervideo-fullscreen-btn');
    if (!button) {
        return;
    }
    const active = Boolean(document.fullscreenElement || document.webkitFullscreenElement);
    button.setAttribute('aria-label', await getString(active ? 'exitfullscreen' : 'fullscreen', 'mod_playervideo'));
    const icon = button.querySelector('i');
    icon.classList.toggle('fa-expand', !active);
    icon.classList.toggle('fa-compress', active);
};

/**
 * Wires the full-screen toggle button, hiding it outright on a browser with neither the
 * unprefixed nor the WebKit-prefixed Fullscreen API (e.g. iPhone Safari, which only supports
 * full screen on a bare <video> element, not an arbitrary container like the player stage).
 *
 * Called once from init(), not from initTimelineControls(): the button is visible as soon as
 * the player screen shows, which happens well before adapter.getDuration() resolves (the
 * source adapter is still loading its iframe/video element at that point) — wiring the click
 * handler only after that would leave the visible button non-functional for however long the
 * source takes to finish loading.
 */
const initFullscreenControl = () => {
    const button = document.getElementById('playervideo-fullscreen-btn');
    const stage = document.getElementById('playervideo-stage');
    if (!button || !stage) {
        return;
    }
    if (!stage.requestFullscreen && !stage.webkitRequestFullscreen) {
        button.hidden = true;
        return;
    }

    button.addEventListener('click', toggleFullscreen);
    document.addEventListener('fullscreenchange', updateFullscreenButton);
    document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
};

/**
 * Escapes a string for safe insertion as an HTML attribute value or text content. The
 * textContent/innerHTML round-trip alone only escapes &, < and > — quotes must be escaped
 * separately or a value placed inside a double- or single-quoted attribute can break out of it.
 *
 * @param {string} text Raw text.
 * @returns {string}
 */
const escapeHtmlAttribute = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
};

/**
 * Wires the timeline bar's click-to-seek and the play/pause button. Shared by the live attempt
 * (gated by the anti-skip tracker) and review mode (unrestricted — the attempt is already over).
 *
 * @returns {Promise<void>}
 */
const initTimelineControls = async() => {
    duration = await adapter.getDuration();
    document.getElementById('playervideo-ruler-end').textContent = formatTime(duration);
    renderMarkers();
    await setPlayingState(false);
    initSpeedControl();
    await loadCaptionOptions();
    initTranscriptPanel();

    adapter.onTimeUpdate((time) => {
        document.getElementById('playervideo-playhead').style.left = `${percentForTime(time)}%`;
        document.getElementById('playervideo-ruler-start').textContent = formatTime(time);
    });
    adapter.onTimeUpdate(updateCaptionOverlay);

    document.getElementById('playervideo-timeline').addEventListener('click', (event) => {
        attemptSeek(timestampForClientX(event.clientX));
    });

    document.getElementById('playervideo-playpause-btn').addEventListener('click', togglePlayPause);
};

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
        voted: ['result_viewed', 'badge-secondary'],
        'pending_ai': ['result_pending', 'badge-warning'],
        'pending_review': ['result_pending', 'badge-warning'],
        graded: ['result_correct', 'badge-success'],
        notreached: ['result_notreached', 'badge-secondary'],
    };
    return resultmap[row.status] ?? ['result_notreached', 'badge-secondary'];
};

/**
 * Maps an interaction type to its lang string key, used for the attempt summary table.
 *
 * @type {object}
 */
const TYPE_LABEL_KEYS = {
    note: 'typenote',
    question: 'typequestion',
    poll: 'typepoll',
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
        ispoll: row.type === 'poll',
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
        reviewing = true;
        const data = await call('mod_playervideo_get_attempt_review', {attemptid: reviewattemptid});
        hideIdleInfo();
        showScreen('player');
        document.getElementById('playervideo-poster')?.setAttribute('hidden', '');
        adapter = await createAdapterForSource();
        await adapter.ready();
        seekUnrestricted = true;
        await initTimelineControls();

        for (const row of data.interactions) {
            adapter.seek(row.timestamp);
            await showReviewOverlay(row);
        }

        // Simplest way back to a clean idle state (matching the summary screen's own "back"
        // button): a fresh render re-derives canstart/previousattempts and re-preps the player.
        window.location.reload();
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
        duration,
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
            review.interactions.map((row) => getString(TYPE_LABEL_KEYS[row.type], 'mod_playervideo'))
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
    await setPlayingState(false);
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
    setPlayingState(true);
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
 * question, which shows feedback before resuming.
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
 * Renders one poll option's aggregated vote bar (a per-render dynamic width, hence the inline
 * style).
 *
 * @param {object} option One option from mod_playervideo_get_poll_results.
 * @param {number} selectedid The option id the student voted for.
 * @returns {string} HTML for one result row.
 */
const buildPollResultRow = (option, selectedid) => `
    <div class="playervideo-poll-result-row ${option.polloptionid === selectedid ? 'fw-bold' : ''}">
        <div class="d-flex justify-content-between">
            <span>${option.optiontext}</span>
            <span>${option.percent}%</span>
        </div>
        <div class="playervideo-poll-result-bar">
            <div class="playervideo-poll-result-fill" style="width: ${option.percent}%"></div>
        </div>
    </div>
`;

/**
 * Shows the aggregated class result after the student votes, then a Continue button.
 *
 * @param {number} selectedid The option id the student voted for.
 * @returns {Promise<void>}
 */
const showPollFeedback = async(selectedid) => {
    const overlay = document.getElementById('playervideo-interaction-overlay');
    const continuestr = await getString('continuewatching', 'mod_playervideo');
    let resultshtml = '';
    try {
        const results = await call('mod_playervideo_get_poll_results', {interactionid: currentInteraction.id});
        resultshtml = results.options.map((option) => buildPollResultRow(option, selectedid)).join('');
    } catch (error) {
        // The vote itself is already recorded; the aggregated breakdown is a nice-to-have.
        resultshtml = '';
    }
    overlay.innerHTML = `
        <div class="card-body">
            ${resultshtml}
            <button type="button" class="btn btn-primary mt-2" id="playervideo-overlay-continue">${continuestr}</button>
        </div>
    `;
    announce(await getString('typepoll', 'mod_playervideo'));
    overlay.querySelector('#playervideo-overlay-continue').addEventListener('click', resumeAfterInteraction);
    overlay.querySelector('button').focus();
};

/**
 * Submits the student's vote for the currently open poll interaction.
 *
 * @returns {Promise<void>}
 */
const submitPollVote = async() => {
    const selected = document.querySelector('input[name="playervideo-poll-option"]:checked');
    if (!selected) {
        Notification.alert('', await getString('error_invalidpolloption', 'mod_playervideo'));
        return;
    }
    const selectedid = parseInt(selected.value, 10);

    await Autosave.callWithRetry('mod_playervideo_submit_answer', {
        attemptid: attemptId,
        interactionid: currentInteraction.id,
        answerid: 0,
        responsetext: '',
        polloptionid: selectedid,
    });

    treatedInteractionIds.add(currentInteraction.id);
    await showPollFeedback(selectedid);
};

/**
 * Pauses the video and shows the live, editable overlay for one interaction.
 *
 * @param {object} interaction Interaction from playerData.interactions.
 * @returns {Promise<void>}
 */
const pauseForInteraction = async(interaction) => {
    adapter.pause();
    await setPlayingState(false);
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
    } else if (interaction.type === 'poll') {
        const confirmstr = await getString('confirmanswer', 'mod_playervideo');
        const body = document.createElement('div');
        body.className = 'card-body';
        const prompt = document.createElement('div');
        prompt.innerHTML = interaction.notetext;
        body.appendChild(prompt);

        const optionscontainer = document.createElement('div');
        optionscontainer.className = 'mb-2';
        interaction.polloptions.forEach((option) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'form-check';
            wrapper.innerHTML = `
                <input class="form-check-input" type="radio" name="playervideo-poll-option" value="${option.id}"
                    id="playervideo-poll-option-${option.id}">
                <label class="form-check-label" for="playervideo-poll-option-${option.id}">${option.text}</label>
            `;
            optionscontainer.appendChild(wrapper);
        });
        body.appendChild(optionscontainer);

        const confirmbutton = document.createElement('button');
        confirmbutton.type = 'button';
        confirmbutton.className = 'btn btn-primary';
        confirmbutton.id = 'playervideo-overlay-confirm';
        confirmbutton.textContent = confirmstr;
        confirmbutton.addEventListener('click', submitPollVote);
        body.appendChild(confirmbutton);

        overlay.innerHTML = '';
        overlay.appendChild(body);
        announce(await getString('typepoll', 'mod_playervideo'));
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
 * Loads the video source and wires the timeline/controls immediately on page load, so the
 * activity is visible and scrubbable before any attempt exists. The attempt itself only opens
 * on the student's first play click, via beginAttempt() — see togglePlayPause().
 *
 * @returns {Promise<void>}
 */
const preparePlayer = async() => {
    try {
        adapter = await createAdapterForSource();
        tracker = createTracker(JSON.parse(playerData.segments));
        await adapter.ready();
        await initTimelineControls();

        if (playerData.lastposition !== null) {
            adapter.seek(playerData.lastposition);
        } else if (playerData.trimstart !== null) {
            adapter.seek(playerData.trimstart);
        }
    } catch (error) {
        showError(error);
    }
};

/**
 * Opens (or resumes) the attempt on the student's first play click, then starts playback.
 * Kept separate from preparePlayer() so the PlayerHUD retry cost (start_attempt.php) is only
 * ever charged at the moment the student actually presses play, never speculatively on page
 * load.
 *
 * @returns {Promise<void>}
 */
const beginAttempt = async() => {
    try {
        const started = await call('mod_playervideo_start_attempt', {playervideoid: playerData.playervideoid});
        attemptId = started.attemptid;
        attemptNumber = started.attemptnumber;
        treatedInteractionIds = new Set(started.treatedinteractionids);
        attemptFinishing = false;
        seekUnrestricted = false;

        hideIdleInfo();
        document.getElementById('playervideo-poster')?.setAttribute('hidden', '');
        document.getElementById('playervideo-finish-btn').hidden = false;

        adapter.onTimeUpdate(onTick);
        adapter.onEnded(() => finishAttempt(true));

        heartbeatTimer = window.setInterval(() => heartbeat(false), HEARTBEAT_INTERVAL_MS);
        adapter.play();
        await setPlayingState(true);
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
 * Shows the approved DI easy-read summary in a modal.
 *
 * The stored summary text is AI-sourced content and must never be trusted as HTML (see the
 * project's rule against {{{triple-mustache}}}-style trust for stored/AI content) — escaped
 * here via a throwaway element's textContent/innerHTML round-trip before it ever reaches
 * {@see Modal.create}'s body string.
 *
 * @returns {Promise<void>}
 */
const showDiSummary = async() => {
    const title = await getString('disummary', 'mod_playervideo');
    const escaped = document.createElement('div');
    escaped.textContent = playerData.disummary;
    await Modal.create({
        title,
        body: `<p class="playervideo-disummary-text">${escaped.innerHTML}</p>`,
        removeOnClose: true,
        show: true,
    });
};

/**
 * Initialises the activity page: loads the player right away when the student is eligible to
 * attempt (view.php's showplayer decides whether #playervideo-player-screen starts hidden — see
 * preparePlayer()), and wires every "review this attempt" button on the info panel.
 */
export const init = () => {
    playerData = readPlayerData();
    Autosave.init();

    const playerScreen = document.getElementById('playervideo-player-screen');
    if (playerScreen && !playerScreen.hidden) {
        preparePlayer();
    }

    document.querySelectorAll('.playervideo-review-btn').forEach((button) => {
        button.addEventListener('click', () => startReview(parseInt(button.dataset.attemptid, 10)));
    });

    document.getElementById('playervideo-finish-btn')?.addEventListener('click', () => finishAttempt(false));
    document.getElementById('playervideo-disummary-btn')?.addEventListener('click', showDiSummary);
    initFullscreenControl();
};
