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
 * Correction queue and analytics report for a PlayerVideo instance.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('playervideo', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playervideo', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);

$canreview = has_capability('mod/playervideo:reviewresponses', $context);
$canviewreports = has_capability('mod/playervideo:viewreports', $context);
if (!$canreview && !$canviewreports) {
    // Neither capability is present — let require_capability() throw its own, correctly
    // worded exception rather than rolling a custom one for this "either/or" gate.
    require_capability('mod/playervideo:viewreports', $context);
}

$PAGE->set_url('/mod/playervideo/report.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('reportheader', 'mod_playervideo') . ': ' . $instance->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/playervideo/styles.css');

if ($canreview) {
    $PAGE->requires->js_call_amd('mod_playervideo/grading', 'init', [(int) $instance->id]);
}
if ($canviewreports) {
    $PAGE->requires->js_call_amd('mod_playervideo/report', 'init', [(int) $instance->id]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reportheader', 'mod_playervideo'));
echo $OUTPUT->render_from_template('mod_playervideo/report', [
    'canreview' => $canreview,
    'canviewreports' => $canviewreports,
]);
echo $OUTPUT->footer();
