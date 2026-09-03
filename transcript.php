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
 * Text-only linear document alternative to the video player, for a student using a screen
 * reader — a first-class route, not an adaptation of the video screen (see the plugin SCOPE,
 * "Modo texto-only").
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_playervideo\local\transcript_service;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('playervideo', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playervideo', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/playervideo:attempt', $context);

$PAGE->set_url('/mod/playervideo/transcript.php', ['id' => $cm->id]);
$PAGE->set_title($instance->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/playervideo/styles.css');

$blocks = transcript_service::build_document($instance, $context);

$transcriptdata = [
    'playervideoid' => (int) $instance->id,
    'blocks' => $blocks,
];

echo $OUTPUT->header();
echo html_writer::tag(
    'script',
    json_encode($transcriptdata, JSON_HEX_TAG | JSON_HEX_AMP),
    ['type' => 'application/json', 'id' => 'playervideo-transcript-data']
);
echo $OUTPUT->render_from_template('mod_playervideo/transcript', [
    'backtovideourl' => (new moodle_url('/mod/playervideo/view.php', ['id' => $cm->id]))->out(false),
]);

$PAGE->requires->js_call_amd('mod_playervideo/transcript', 'init', []);

echo $OUTPUT->footer();
