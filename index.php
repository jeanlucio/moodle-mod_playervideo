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
 * List of all playervideo instances in a course.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$PAGE->set_url('/mod/playervideo/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_playervideo'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$modinfo = get_fast_modinfo($course);
$instances = get_all_instances_in_course('playervideo', $course);

$table = new html_table();
$table->head = [get_string('name'), get_string('sectionname', 'format_' . $course->format)];
$table->attributes['class'] = 'generaltable mod_index';

foreach ($instances as $instance) {
    $cm = $modinfo->get_cm($instance->coursemodule);
    $link = html_writer::link(
        new moodle_url('/mod/playervideo/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name),
        $cm->visible ? [] : ['class' => 'dimmed']
    );
    $table->data[] = [$link, $cm->get_section_name()];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_playervideo'));

if (empty($instances)) {
    notice(get_string('nomodules', 'moodle'), new moodle_url('/course/view.php', ['id' => $course->id]));
} else {
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
