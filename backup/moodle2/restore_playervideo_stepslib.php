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
 * Restore structure step for mod_playervideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Processes the XML tree produced by backup and rebuilds the database records.
 */
class restore_playervideo_activity_structure_step extends restore_activity_structure_step {
    /**
     * Returns the path elements the restore engine should process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure(): array {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('playervideo', '/activity/playervideo');
        $paths[] = new restore_path_element(
            'playervideo_interaction',
            '/activity/playervideo/interactions/interaction'
        );
        $paths[] = new restore_path_element(
            'playervideo_polloption',
            '/activity/playervideo/interactions/interaction/polloptions/polloption'
        );
        $paths[] = new restore_path_element(
            'playervideo_caption',
            '/activity/playervideo/captions/caption'
        );
        $paths[] = new restore_path_element(
            'playervideo_disummary',
            '/activity/playervideo/disummaries/disummary'
        );

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'playervideo_progress',
                '/activity/playervideo/progresses/progress'
            );
            $paths[] = new restore_path_element(
                'playervideo_attempt',
                '/activity/playervideo/attempts/attempt'
            );
            $paths[] = new restore_path_element(
                'playervideo_response',
                '/activity/playervideo/attempts/attempt/responses/response'
            );
        }

        // Wrap with the generic '/activity' path so the base class's process_activity() runs:
        // it registers the old-to-new context mapping and the old activity id. Without it,
        // restore_calendarevents_structure_step::after_execute() (a generic step that runs for
        // every activity) fails with unknown_context_mapping.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Resolves a backed-up PlayerHUD item ID to the item that should be referenced in the
     * restored copy, or 0 if none applies. Mirrors mod_playerwords's own restore step, the
     * proven pattern already in production for this ecosystem.
     *
     * @param int $oldid Backed-up item ID, 0 if the field was not configured.
     * @return int
     */
    private function resolve_hud_item(int $oldid): int {
        if ($oldid <= 0) {
            return 0;
        }

        $mapped = $this->get_mappingid('playerhud_item', $oldid);
        if ($mapped) {
            return (int) $mapped;
        }

        if (!class_exists('\block_playerhud\local\external_items')) {
            return 0;
        }

        $blockinstanceid = \mod_playervideo\local\hud_service::get_block_instance_id($this->get_courseid());
        if ($blockinstanceid === null) {
            return 0;
        }

        return \block_playerhud\local\external_items::belongs_to_instance($oldid, $blockinstanceid) ? $oldid : 0;
    }

    /**
     * Resolves a backed-up question id, for a plugin that references questions directly rather
     * than through the Question Usage API (see the plugin SCOPE, "Blind JSON").
     *
     * Tries, in order: (1) the 'question_created' mapping, core's own namespace, populated only
     * when the question was actually part of this backup's question-bank scope (its category
     * travelled with the backup — the common case for a category created at this activity's own
     * module context, see question_service::get_or_create_category()); (2) if no mapping was
     * registered — the question lives in a category outside this backup's scope, e.g. a
     * course/system-level bank shared with other activities that a single-activity restore never
     * touches — whether the same id still legitimately exists on this site, since the large
     * majority of restores land on the same site the backup was taken from and the question
     * itself was never duplicated; (3) otherwise, 0 — a cross-site restore without the source
     * question bank, or the question has since been deleted.
     *
     * @param int $oldid Backed-up question id.
     * @return int The resolved question id, or 0 if it cannot be resolved at all.
     */
    private function resolve_questionid(int $oldid): int {
        global $DB;

        if ($oldid <= 0) {
            return 0;
        }

        $mapped = (int) $this->get_mappingid('question_created', $oldid, 0);
        if ($mapped > 0) {
            return $mapped;
        }

        return $DB->record_exists('question', ['id' => $oldid]) ? $oldid : 0;
    }

    /**
     * Restores the root playervideo instance record.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $data->hudcorrectitem = $this->resolve_hud_item((int) ($data->hudcorrectitem ?? 0));
        $data->hudretrycostitem = $this->resolve_hud_item((int) ($data->hudretrycostitem ?? 0));

        $newitemid = $DB->insert_record('playervideo', $data);
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('playervideo', $oldid, $newitemid);
    }

    /**
     * Restores an interaction (timeline marker) belonging to the activity.
     *
     * A question-type interaction whose questionid cannot be resolved at all (dropped rather
     * than kept pointing nowhere) is skipped — no mapping is registered for it, so any response
     * that answered it is skipped too, in process_playervideo_response() below.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo_interaction(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->playervideoid = $this->get_new_parentid('playervideo');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        if ($data->type === 'question') {
            $newquestionid = $this->resolve_questionid((int) $data->questionid);
            if ($newquestionid === 0) {
                debugging(
                    'mod_playervideo: dropped interaction ' . $oldid . ' on restore — its question ' .
                    'could not be resolved on this site.',
                    DEBUG_DEVELOPER
                );
                return;
            }
            $data->questionid = $newquestionid;
        }

        $newitemid = $DB->insert_record('playervideo_interactions', $data);
        // The mapping namespace here is deliberately the singular restore_path_element name
        // ('playervideo_interaction', matching define_structure() above), not the plural table
        // name — set_mapping() only feeds get_new_parentid()'s in-memory tracking (used by
        // process_playervideo_polloption() below) when the two match exactly; get_mappingid()
        // (used by process_playervideo_response()) works with either name, so one consistent
        // name is used everywhere rather than juggling two spellings for the same mapping.
        $this->set_mapping('playervideo_interaction', $oldid, $newitemid);
    }

    /**
     * Restores a poll option belonging to a poll-type interaction.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo_polloption(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        // A poll option is nested directly inside its interaction in the XML tree, so its
        // immediate parent mapping — registered by process_playervideo_interaction() just
        // above — is already the current one here.
        $newinteractionid = $this->get_new_parentid('playervideo_interaction');
        if (empty($newinteractionid)) {
            // The parent interaction was dropped above — cannot happen for a poll in practice
            // (only question-type interactions ever fail to resolve), but stay defensive.
            return;
        }

        $data->interactionid = $newinteractionid;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('playervideo_poll_options', $data);
        $this->set_mapping('playervideo_polloption', $oldid, $newitemid);
    }

    /**
     * Restores a caption track for one language.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo_caption(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $data->playervideoid = $this->get_new_parentid('playervideo');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('playervideo_captions', $data);
    }

    /**
     * Restores an easy-read (DI) summary for one language.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo_disummary(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $data->playervideoid = $this->get_new_parentid('playervideo');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('playervideo_disummaries', $data);
    }

    /**
     * Restores a student's playback progress record (only when userinfo is enabled).
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo_progress(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $data->playervideoid = $this->get_new_parentid('playervideo');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $data->userid = (int) $this->get_mappingid('user', $data->userid);
        if (empty($data->userid)) {
            return;
        }

        $DB->insert_record('playervideo_progress', $data);
    }

    /**
     * Restores a student attempt record (only when userinfo is enabled).
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo_attempt(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->playervideoid = $this->get_new_parentid('playervideo');
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timefinish = $data->timefinish !== null ? $this->apply_date_offset($data->timefinish) : null;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $data->userid = (int) $this->get_mappingid('user', $data->userid);
        if (empty($data->userid)) {
            return;
        }

        $newitemid = $DB->insert_record('playervideo_attempts', $data);
        $this->set_mapping('playervideo_attempt', $oldid, $newitemid);
    }

    /**
     * Restores a student's response to one interaction, within one attempt (only when userinfo
     * is enabled).
     *
     * Skipped entirely (never a partially-restored row) if its attempt's owner, the responding
     * user, or the interaction it answers could not be resolved — an orphaned response would be
     * equally meaningless to keep. questionid, unlike interactionid, is only a historical
     * snapshot (audit trail if the interaction is later deleted, see install.xml) — nulled out
     * rather than dropping the whole response when it cannot be resolved.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playervideo_response(array|object $data): void {
        global $DB;

        $data = (object) $data;

        $newattemptid = $this->get_new_parentid('playervideo_attempt');
        $newuserid = (int) $this->get_mappingid('user', $data->userid);
        $newinteractionid = (int) $this->get_mappingid('playervideo_interaction', $data->interactionid);

        if (empty($newattemptid) || empty($newuserid) || empty($newinteractionid)) {
            return;
        }

        $data->playervideoid = $this->get_new_parentid('playervideo');
        $data->attemptid = $newattemptid;
        $data->userid = $newuserid;
        $data->interactionid = $newinteractionid;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $resolvedquestionid = !empty($data->questionid) ? $this->resolve_questionid((int) $data->questionid) : 0;
        $data->questionid = $resolvedquestionid > 0 ? $resolvedquestionid : null;

        // Unlike questionid, an unresolved answerid/polloptionid has no safe "keep as audit
        // trail" fallback (nulling it would silently discard which option the student actually
        // picked) — but get_mappingid()'s own $ifnotfound default already reproduces the
        // "question was never part of this backup's own scope, so nothing about it changed"
        // case by falling back to the original, still-valid id.
        $data->answerid = !empty($data->answerid)
            ? (int) $this->get_mappingid('question_answer', $data->answerid, $data->answerid)
            : null;

        $newpolloptionid = !empty($data->polloptionid)
            ? (int) $this->get_mappingid('playervideo_polloption', $data->polloptionid, 0)
            : 0;
        $data->polloptionid = $newpolloptionid > 0 ? $newpolloptionid : null;

        $DB->insert_record('playervideo_responses', $data);
    }

    /**
     * Restores files from every file area this activity owns: the intro editor field, an
     * uploaded HTML5 video, and a cover image. videofile was a real, pre-existing gap here
     * (present since Fase 2, only found while adding posterimage in Fase 9) — restored now
     * alongside it, both following annotate_files()'s matching call in the backup step.
     *
     * The grade item itself is not touched here: restore_activity_grades_structure_step (added
     * generically by restore_activity_task for every gradable module) already restores it.
     *
     * @return void
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_playervideo', 'intro', null);
        $this->add_related_files('mod_playervideo', 'videofile', null);
        $this->add_related_files('mod_playervideo', 'posterimage', null);
    }
}
