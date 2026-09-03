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
    'mod_playervideo_start_attempt' => [
        'classname' => 'mod_playervideo\external\start_attempt',
        'methodname' => 'execute',
        'description' => 'Starts a new attempt, or resumes the one already in progress.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:attempt',
    ],
    'mod_playervideo_submit_answer' => [
        'classname' => 'mod_playervideo\external\submit_answer',
        'methodname' => 'execute',
        'description' => 'Auto-saves a student\'s answer, or confirms a note was read.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:attempt',
    ],
    'mod_playervideo_save_progress' => [
        'classname' => 'mod_playervideo\external\save_progress',
        'methodname' => 'execute',
        'description' => 'Heartbeats playback position and watched segments, for resume and anti-skip.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:attempt',
    ],
    'mod_playervideo_finish_attempt' => [
        'classname' => 'mod_playervideo\external\finish_attempt',
        'methodname' => 'execute',
        'description' => 'Ends an attempt and, when possible, sends its grade to the Gradebook.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:attempt',
    ],
    'mod_playervideo_get_attempt_review' => [
        'classname' => 'mod_playervideo\external\get_attempt_review',
        'methodname' => 'execute',
        'description' => 'Reads back a finished attempt for the review screen and attempt summary.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:attempt,mod/playervideo:reviewresponses',
    ],
    'mod_playervideo_get_poll_results' => [
        'classname' => 'mod_playervideo\external\get_poll_results',
        'methodname' => 'execute',
        'description' => 'Returns the aggregate vote distribution of a poll interaction.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:attempt',
    ],
    'mod_playervideo_generate_question_ai' => [
        'classname' => 'mod_playervideo\external\generate_question_ai',
        'methodname' => 'execute',
        'description' => 'Generates one question by AI for a given timestamp, pending teacher review.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:manage,moodle/question:add',
    ],
    'mod_playervideo_generate_questions_batch' => [
        'classname' => 'mod_playervideo\external\generate_questions_batch',
        'methodname' => 'execute',
        'description' => 'Generates several questions by AI from a pasted transcript, pending teacher review.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/playervideo:manage,moodle/question:add',
    ],
];
