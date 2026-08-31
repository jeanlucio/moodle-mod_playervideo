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
 * External function definitions for PlayerVideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_playervideo_get_interactions' => [
        'classname' => 'mod_playervideo\external\get_interactions',
        'methodname' => 'execute',
        'description' => 'Lists the trim window and every interaction of a PlayerVideo instance.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:manage',
    ],
    'mod_playervideo_save_interaction' => [
        'classname' => 'mod_playervideo\external\save_interaction',
        'methodname' => 'execute',
        'description' => 'Creates, updates or deletes a timeline marker.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:manage',
    ],
    'mod_playervideo_save_trim' => [
        'classname' => 'mod_playervideo\external\save_trim',
        'methodname' => 'execute',
        'description' => 'Sets the playback trim window (start/end) of a PlayerVideo instance.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:manage',
    ],
    'mod_playervideo_search_questions' => [
        'classname' => 'mod_playervideo\external\search_questions',
        'methodname' => 'execute',
        'description' => 'Searches the Question Bank for the "pull from bank" timeline picker.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:manage',
    ],
    'mod_playervideo_create_question' => [
        'classname' => 'mod_playervideo\external\create_question',
        'methodname' => 'execute',
        'description' => 'Creates a multichoice/truefalse question in the activity\'s own category.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:manage',
    ],
];
