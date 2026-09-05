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
 * External function to auto-save a student's answer, or confirm a note was read.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playervideo\local\attempt_lock;
use mod_playervideo\local\hud_service;
use mod_playervideo\local\question_service;
use moodle_exception;
use stdClass;

/**
 * Records exactly one response per (interaction, attempt) — called once, at the moment the
 * student confirms an answer or dismisses a note; a second call for the same pair is refused,
 * which is what locks a closed interaction against being answered again in the same attempt.
 */
class submit_answer extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'interactionid' => new external_value(PARAM_INT, 'Interaction id'),
            'answerid' => new external_value(PARAM_INT, 'Chosen answer id, for multichoice/truefalse', VALUE_DEFAULT, 0),
            'responsetext' => new external_value(PARAM_RAW, 'Free-text response, for an open question', VALUE_DEFAULT, ''),
            'polloptionid' => new external_value(PARAM_INT, 'Chosen poll option id, when type is poll', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Records the student's response to one interaction.
     *
     * @param int $attemptid Attempt id.
     * @param int $interactionid Interaction id.
     * @param int $answerid Chosen answer id, for multichoice/truefalse.
     * @param string $responsetext Free-text response, for an open question.
     * @param int $polloptionid Chosen poll option id, when type is poll.
     * @return array Whether the answer is correct (when known) and the response status.
     */
    public static function execute(
        int $attemptid,
        int $interactionid,
        int $answerid,
        string $responsetext,
        int $polloptionid
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'attemptid' => $attemptid,
            'interactionid' => $interactionid,
            'answerid' => $answerid,
            'responsetext' => $responsetext,
            'polloptionid' => $polloptionid,
        ]);

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('playervideo', $attempt->playervideoid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:attempt', $context);

        if ((int) $attempt->userid !== (int) $USER->id) {
            throw new moodle_exception('error_notyourattempt', 'mod_playervideo');
        }
        if ($attempt->status !== 'inprogress') {
            throw new moodle_exception('error_attemptnotinprogress', 'mod_playervideo');
        }

        // Never trust interactionid alone — re-bind it to the attempt's own instance.
        $interaction = $DB->get_record('playervideo_interactions', [
            'id' => $params['interactionid'],
            'playervideoid' => $attempt->playervideoid,
        ]);
        if (!$interaction) {
            throw new moodle_exception('error_interactionnotfound', 'mod_playervideo');
        }

        $instance = $DB->get_record('playervideo', ['id' => $attempt->playervideoid], '*', MUST_EXIST);

        // The already-answered/already-rewarded checks below and the response insert must run
        // as one atomic sequence: without this lock, two concurrent requests for the same
        // attempt+interaction can both pass both checks before either writes, granting the
        // PlayerHUD item twice for one correct answer.
        $lock = attempt_lock::acquire('answer_' . $attempt->id . '_' . $interaction->id);
        try {
            $alreadyanswered = $DB->record_exists('playervideo_responses', [
                'interactionid' => $interaction->id,
                'attemptid' => $attempt->id,
            ]);
            if ($alreadyanswered) {
                throw new moodle_exception('error_interactionalreadyanswered', 'mod_playervideo');
            }

            $now = time();
            $response = new stdClass();
            $response->playervideoid = $attempt->playervideoid;
            $response->userid = $attempt->userid;
            $response->attemptid = $attempt->id;
            $response->interactionid = $interaction->id;
            $response->questionid = $interaction->questionid;
            $response->answerid = null;
            $response->polloptionid = null;
            $response->responsetext = null;
            $response->iscorrect = null;
            $response->hudrewarded = 0;
            $response->timecreated = $now;
            $response->timemodified = $now;

            $iscorrect = null;

            if ($interaction->type === 'note') {
                $response->status = 'viewed';
            } else if ($interaction->type === 'poll') {
                $optionbelongstopoll = $DB->record_exists('playervideo_poll_options', [
                    'id' => $params['polloptionid'],
                    'interactionid' => $interaction->id,
                ]);
                if ($params['polloptionid'] <= 0 || !$optionbelongstopoll) {
                    throw new moodle_exception('error_invalidpolloption', 'mod_playervideo');
                }
                $response->polloptionid = $params['polloptionid'];
                $response->status = 'voted';
            } else {
                $qtype = question_service::get_question_type((int) $interaction->questionid);
                if ($qtype === null) {
                    throw new moodle_exception('error_questionnotfound', 'mod_playervideo');
                }

                if ($qtype === 'multichoice' || $qtype === 'truefalse') {
                    $answerbelongstoquestion = $DB->record_exists('question_answers', [
                        'id' => $params['answerid'],
                        'question' => $interaction->questionid,
                    ]);
                    if ($params['answerid'] <= 0 || !$answerbelongstoquestion) {
                        throw new moodle_exception('error_invalidanswer', 'mod_playervideo');
                    }
                    $response->answerid = $params['answerid'];
                    $iscorrect = question_service::is_answer_correct((int) $interaction->questionid, $params['answerid']);
                    $response->iscorrect = $iscorrect ? 1 : 0;
                    $response->status = 'answered';
                } else {
                    if (trim($params['responsetext']) === '') {
                        throw new moodle_exception('error_responsetextrequired', 'mod_playervideo');
                    }
                    $response->responsetext = $params['responsetext'];
                    // Not 'pending_review' yet — no AI suggestion has been generated at this
                    // point (see generate_response_correction). attempt_manager::
                    // has_pending_correction() already treats both statuses as "still needs a
                    // teacher decision".
                    $response->status = 'pending_ai';
                }
            }

            $shouldreward = false;
            if ($iscorrect === true && (int) $instance->hudcorrectitem > 0) {
                $alreadyrewarded = $DB->record_exists('playervideo_responses', [
                    'interactionid' => $interaction->id,
                    'userid' => $attempt->userid,
                    'hudrewarded' => 1,
                ]);
                $shouldreward = !$alreadyrewarded;
                $response->hudrewarded = $shouldreward ? 1 : 0;
            }

            $DB->insert_record('playervideo_responses', $response);

            $hudrewardname = null;
            if ($shouldreward) {
                $blockinstanceid = hud_service::resolve_block_instance_id($instance);
                hud_service::grant_items($blockinstanceid, $attempt->userid, (int) $instance->hudcorrectitem, 1);
                $hudrewardname = hud_service::get_item_name($blockinstanceid, (int) $instance->hudcorrectitem);
            }
        } finally {
            $lock->release();
        }

        $course = get_course($cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
        }

        return [
            'iscorrect' => $iscorrect,
            'status' => $response->status,
            'hudrewarded' => $shouldreward,
            'hudrewardname' => $hudrewardname,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'iscorrect' => new external_value(
                PARAM_BOOL,
                'True/false for multichoice-type answers, absent for notes/open questions',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'answered | viewed | voted | pending_ai'),
            'hudrewarded' => new external_value(PARAM_BOOL, 'Whether this answer just granted a PlayerHUD item'),
            'hudrewardname' => new external_value(
                PARAM_TEXT,
                'Display name of the granted PlayerHUD item, absent when hudrewarded is false',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
