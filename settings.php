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
 * Plugin administration settings.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    require_once(__DIR__ . '/lib.php');

    $settings->add(new admin_setting_configselect(
        'mod_playervideo/grademethod',
        get_string('grademethod', 'mod_playervideo'),
        '',
        \mod_playervideo\local\attempt_manager::GRADE_HIGHEST,
        playervideo_get_grademethod_options()
    ));

    if (\mod_playervideo\local\hud_service::is_outdated()) {
        $settings->add(new admin_setting_heading(
            'mod_playervideo/hudoutdated',
            get_string('hud_outdated_heading', 'mod_playervideo'),
            get_string('hud_outdated_desc', 'mod_playervideo')
        ));
    } else if (!\mod_playervideo\local\hud_service::is_installed()) {
        $settings->add(new admin_setting_heading(
            'mod_playervideo/hudnotinstalled',
            get_string('hud_notinstalled_heading', 'mod_playervideo'),
            get_string('hud_notinstalled_desc', 'mod_playervideo')
        ));
    }
}
