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
 * Timeline management screen: trim window, questions and notes.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_playervideo\local\video_source;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('playervideo', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playervideo', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/playervideo:manage', $context);

$PAGE->set_url('/mod/playervideo/interactions.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('manageinteractions', 'mod_playervideo') . ': ' . $instance->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/playervideo/styles.css');

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

$PAGE->requires->js_call_amd('mod_playervideo/interactions_editor', 'init', [(int) $instance->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageinteractions', 'mod_playervideo'));
echo $OUTPUT->render_from_template('mod_playervideo/interactions_editor', [
    'playervideoid' => (int) $instance->id,
    'embedurl' => $embedurl !== null ? $embedurl->out(false) : null,
    'embedvideo' => $embedurl !== null && $instance->videotype === 'html5',
    'embediframe' => $embedurl !== null && $instance->videotype !== 'html5',
]);
echo $OUTPUT->footer();
