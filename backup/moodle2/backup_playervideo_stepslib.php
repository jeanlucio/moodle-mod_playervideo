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
 * Backup structure step for mod_playervideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the XML tree structure for a PlayerVideo backup.
 */
class backup_playervideo_activity_structure_step extends backup_activity_structure_step {
    /**
     * Returns the root backup element with all nested children.
     *
     * @return backup_nested_element
     */
    protected function define_structure(): backup_nested_element {
        $userinfo = $this->get_setting_value('userinfo');

        // Root element — mirrors all columns in {playervideo}.
        $playervideo = new backup_nested_element('playervideo', ['id'], [
            'name',
            'intro',
            'introformat',
            'videotype',
            'videourl',
            'trimstart',
            'trimend',
            'showinline',
            'grademethod',
            'grade',
            'gradepass',
            'maxattempts',
            'allowseekahead',
            'hudcorrectitem',
            'hudretrycostitem',
            'hudretrycostqty',
            'completionallinteractions',
            'completionwatchtoend',
            'timecreated',
            'timemodified',
        ]);

        // Timeline: interactions authored by the teacher (not personal data), each with its
        // own poll options when type is 'poll'. Always backed up, regardless of userinfo.
        $interactions = new backup_nested_element('interactions');
        $interaction = new backup_nested_element('interaction', ['id'], [
            'timestamp',
            'type',
            'weight',
            'questionid',
            'notetext',
            'notetextformat',
            'sortorder',
            'timecreated',
            'timemodified',
        ]);
        $polloptions = new backup_nested_element('polloptions');
        $polloption = new backup_nested_element('polloption', ['id'], [
            'optiontext',
            'sortorder',
            'timecreated',
            'timemodified',
        ]);

        // Captions and easy-read summaries: authored content, not personal data.
        $captions = new backup_nested_element('captions');
        $caption = new backup_nested_element('caption', ['id'], [
            'lang',
            'source',
            'content',
            'timecreated',
            'timemodified',
        ]);
        $disummaries = new backup_nested_element('disummaries');
        $disummary = new backup_nested_element('disummary', ['id'], [
            'lang',
            'content',
            'status',
            'timecreated',
            'timemodified',
        ]);

        // Per-student playback progress — personal data, only backed up when userinfo is on.
        $progresses = new backup_nested_element('progresses');
        $progress = new backup_nested_element('progress', ['id'], [
            'userid',
            'lastposition',
            'watchedpct',
            'watchedtoend',
            'segments',
            'timecreated',
            'timemodified',
        ]);

        // Attempts and their responses — personal data, only backed up when userinfo is on.
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid',
            'attemptnumber',
            'status',
            'grade',
            'hudretrycharged',
            'timestart',
            'timefinish',
            'timecreated',
            'timemodified',
        ]);
        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', ['id'], [
            'userid',
            'interactionid',
            'questionid',
            'answerid',
            'polloptionid',
            'responsetext',
            'iscorrect',
            'hudrewarded',
            'aigrade',
            'aifeedback',
            'teachergrade',
            'teacherfeedback',
            'status',
            'timecreated',
            'timemodified',
        ]);

        // Build the tree. Interactions (and their poll options) are added before attempts (and
        // their responses) deliberately: the XML document order this produces is what lets
        // restore_playervideo_activity_structure_step::process_playervideo_response() resolve
        // interactionid/polloptionid mappings that process_playervideo_interaction()/
        // process_playervideo_polloption() must already have registered by then.
        $playervideo->add_child($interactions);
        $interactions->add_child($interaction);
        $interaction->add_child($polloptions);
        $polloptions->add_child($polloption);

        $playervideo->add_child($captions);
        $captions->add_child($caption);

        $playervideo->add_child($disummaries);
        $disummaries->add_child($disummary);

        if ($userinfo) {
            $playervideo->add_child($progresses);
            $progresses->add_child($progress);

            $playervideo->add_child($attempts);
            $attempts->add_child($attempt);
            $attempt->add_child($responses);
            $responses->add_child($response);
        }

        // Connect elements to database tables.
        $playervideo->set_source_table('playervideo', ['id' => backup::VAR_ACTIVITYID]);
        $interaction->set_source_table('playervideo_interactions', ['playervideoid' => backup::VAR_PARENTID]);
        $polloption->set_source_table('playervideo_poll_options', ['interactionid' => backup::VAR_PARENTID]);
        $caption->set_source_table('playervideo_captions', ['playervideoid' => backup::VAR_PARENTID]);
        $disummary->set_source_table('playervideo_disummaries', ['playervideoid' => backup::VAR_PARENTID]);

        if ($userinfo) {
            $progress->set_source_table('playervideo_progress', ['playervideoid' => backup::VAR_PARENTID]);
            $attempt->set_source_table('playervideo_attempts', ['playervideoid' => backup::VAR_PARENTID]);
            $response->set_source_table('playervideo_responses', ['attemptid' => backup::VAR_PARENTID]);
        }

        // Annotate files embedded in the intro editor field, if any.
        $playervideo->annotate_files('mod_playervideo', 'intro', null);

        // Annotate IDs that reference other tables so they are remapped on restore.
        // 'question_created'/'question_answer' are core's own mapping namespaces, populated
        // by the generic question-bank restore step whenever a question/answer is actually
        // part of this backup's scope (see restore_playervideo_stepslib.php's
        // resolve_questionid() for what happens when it is not).
        $interaction->annotate_ids('question_created', 'questionid');

        if ($userinfo) {
            $progress->annotate_ids('user', 'userid');
            $attempt->annotate_ids('user', 'userid');
            $response->annotate_ids('user', 'userid');
            $response->annotate_ids('question_created', 'questionid');
            $response->annotate_ids('question_answer', 'answerid');
            // Intra-plugin references, resolved via the interaction/poll option mapping this
            // same structure registers on restore (never a core mapping namespace) — named
            // after the singular restore_path_element, not the plural table, see
            // restore_playervideo_stepslib.php's process_playervideo_interaction() for why.
            $response->annotate_ids('playervideo_interaction', 'interactionid');
            $response->annotate_ids('playervideo_polloption', 'polloptionid');
        }

        // Wrap the root in the standard activity envelope.
        return $this->prepare_activity_structure($playervideo);
    }
}
