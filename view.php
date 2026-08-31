<?php
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
 * View a playervideo instance: the start screen and the interactive player.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_playervideo\local\attempt_manager;
use mod_playervideo\local\intro_service;
use mod_playervideo\local\question_service;
use mod_playervideo\local\video_source;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('playervideo', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playervideo', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/playervideo:view', $context);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/playervideo/view.php', ['id' => $cm->id]);
$PAGE->set_title($instance->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/playervideo/styles.css');

$canattempt = has_capability('mod/playervideo:attempt', $context);
$userid = (int) $USER->id;

$fileurl = null;
if ($instance->videotype === 'html5') {
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_playervideo', 'videofile', 0, 'filename', false);
    $file = reset($files);
    if ($file) {
        $fileurl = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_playervideo',
            'videofile',
            0,
            $file->get_filepath(),
            $file->get_filename()
        );
    }
}
$embedurl = video_source::get_embed_url($instance->videotype, $instance->videourl, $fileurl);

$interactionrecords = $DB->get_records('playervideo_interactions', ['playervideoid' => $instance->id], 'timestamp ASC');
$questionids = array_filter(array_map(
    static fn($record) => $record->type === 'question' ? (int) $record->questionid : null,
    $interactionrecords
));
$pollinteractionids = array_filter(array_map(
    static fn($record) => $record->type === 'poll' ? (int) $record->id : null,
    $interactionrecords
));

$polloptionsbyinteraction = [];
if (!empty($pollinteractionids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($pollinteractionids, SQL_PARAMS_NAMED);
    $polloptionrecords = $DB->get_records_select(
        'playervideo_poll_options',
        "interactionid $insql",
        $inparams,
        'interactionid ASC, sortorder ASC'
    );
    foreach ($polloptionrecords as $polloption) {
        $polloptionsbyinteraction[$polloption->interactionid][] = [
            'id' => (int) $polloption->id,
            'text' => $polloption->optiontext,
        ];
    }
}

$interactions = [];
foreach ($interactionrecords as $record) {
    $question = null;
    if ($record->type === 'question' && $record->questionid !== null) {
        $question = question_service::get_question_for_frontend((int) $record->questionid, $context);
    }
    $interactions[] = [
        'id' => (int) $record->id,
        'timestamp' => (float) $record->timestamp,
        'type' => $record->type,
        'notetext' => $record->type !== 'question' ? format_text(
            $record->notetext ?? '',
            $record->notetextformat,
            ['context' => $context]
        ) : '',
        'question' => $question,
        'polloptions' => $polloptionsbyinteraction[$record->id] ?? [],
    ];
}

$previousattempts = [];
$pendingcorrectionnotice = '';
if ($canattempt) {
    $pastattempts = $DB->get_records_select(
        'playervideo_attempts',
        'playervideoid = :playervideoid AND userid = :userid AND status IN (:finished, :pending)',
        ['playervideoid' => $instance->id, 'userid' => $userid, 'finished' => 'finished', 'pending' => 'pendingcorrection'],
        'attemptnumber DESC'
    );
    foreach ($pastattempts as $pastattempt) {
        if ($pastattempt->status === 'pendingcorrection') {
            $pendingcorrectionnotice = get_string('pendingcorrectionnotice', 'mod_playervideo');
        }
        $previousattempts[] = [
            'attemptid' => (int) $pastattempt->id,
            'attemptnumber' => (int) $pastattempt->attemptnumber,
            'gradeline' => $pastattempt->status === 'pendingcorrection'
                ? get_string('correctionpending', 'mod_playervideo')
                : get_string('yourgrade', 'mod_playervideo', format_float((float) $pastattempt->grade, 2)),
        ];
    }
}

$canstart = $canattempt && attempt_manager::can_start_new_attempt($instance->id, $userid, (int) $instance->maxattempts);
$attemptstaken = $DB->count_records('playervideo_attempts', ['playervideoid' => $instance->id, 'userid' => $userid]);
$startbuttonlabel = get_string($attemptstaken > 0 ? 'newattempt' : 'startattempt', 'mod_playervideo');

$shouldautoshowintro = !intro_service::has_seen_intro($userid);
if ($shouldautoshowintro) {
    intro_service::mark_intro_seen($userid);
}

$progress = $DB->get_record('playervideo_progress', ['playervideoid' => $instance->id, 'userid' => $userid]);

$playerdata = [
    'playervideoid' => (int) $instance->id,
    'videotype' => $instance->videotype,
    'embedurl' => $embedurl !== null ? $embedurl->out(false) : null,
    'trimstart' => $instance->trimstart !== null ? (float) $instance->trimstart : null,
    'trimend' => $instance->trimend !== null ? (float) $instance->trimend : null,
    'allowseekahead' => (bool) $instance->allowseekahead,
    'interactions' => $interactions,
    'lastposition' => $progress !== false && $progress->lastposition !== null ? (float) $progress->lastposition : null,
    'segments' => $progress !== false && $progress->segments !== null ? $progress->segments : '[]',
];

echo $OUTPUT->header();
echo html_writer::tag(
    'script',
    json_encode($playerdata, JSON_HEX_TAG | JSON_HEX_AMP),
    ['type' => 'application/json', 'id' => 'playervideo-player-data']
);
echo $OUTPUT->render_from_template('mod_playervideo/view', [
    'intro' => trim($instance->intro ?? '') !== ''
        ? format_module_intro('playervideo', $instance, $cm->id)
        : '',
    'introbody' => get_string('introbody', 'mod_playervideo'),
    'canattempt' => $canattempt,
    'canstart' => $canstart,
    'startbuttonlabel' => $startbuttonlabel,
    'pendingcorrectionnotice' => $pendingcorrectionnotice,
    'previousattempts' => $previousattempts,
]);

$PAGE->requires->js_call_amd('mod_playervideo/onboarding', 'init', [$shouldautoshowintro]);
if ($canattempt) {
    $PAGE->requires->js_call_amd('mod_playervideo/player', 'init', []);
}

echo $OUTPUT->footer();
