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
 * View a playervideo instance.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_playervideo\local\intro_service;

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

$shouldautoshowintro = !intro_service::has_seen_intro((int) $USER->id);
if ($shouldautoshowintro) {
    intro_service::mark_intro_seen((int) $USER->id);
}

$PAGE->requires->js_call_amd('mod_playervideo/onboarding', 'init', [$shouldautoshowintro]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playervideo/view', [
    'intro' => trim($instance->intro ?? '') !== ''
        ? format_module_intro('playervideo', $instance, $cm->id)
        : '',
    'placeholdertext' => get_string('viewplaceholder', 'mod_playervideo'),
    'introbody' => get_string('introbody', 'mod_playervideo'),
]);
echo $OUTPUT->footer();
