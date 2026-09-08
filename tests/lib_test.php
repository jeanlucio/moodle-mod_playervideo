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
 * Tests for the mod_playervideo library callbacks.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo;

use mod_playervideo\local\attempt_manager;

/**
 * Tests for playervideo_add_instance(), _update_instance(), _delete_instance(),
 * _grade_item_update(), _update_grades(), _get_coursemodule_info(), _questions_in_use() and
 * _supports().
 *
 * Instances are created via the generic module generator (create_module()), which also wires
 * a real course_modules row — required so grade_item can resolve its module context without
 * triggering core's own "instance does not exist" debugging() notice.
 *
 * @covers ::playervideo_add_instance
 * @covers ::playervideo_update_instance
 * @covers ::playervideo_delete_instance
 * @covers ::playervideo_grade_item_update
 * @covers ::playervideo_update_grades
 * @covers ::playervideo_get_coursemodule_info
 * @covers ::playervideo_questions_in_use
 * @covers ::playervideo_supports
 * @covers ::playervideo_extend_settings_navigation
 * @covers ::playervideo_cm_info_dynamic
 */
final class lib_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playervideo/lib.php');
    }

    /**
     * Creates a playervideo instance (and its course_modules row) via the generic generator.
     *
     * @param int $courseid Course id.
     * @param array $overrides Field overrides.
     * @return \stdClass The instance record, with a cmid property.
     */
    private function create_instance(int $courseid, array $overrides = []): \stdClass {
        return $this->getDataGenerator()->create_module('playervideo', array_merge([
            'course' => $courseid,
            'videotype' => 'youtube',
            'videourl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'grademethod' => attempt_manager::GRADE_HIGHEST,
            'grade' => 100,
            'gradepass' => 0,
        ], $overrides));
    }

    /**
     * Tests that known features return their declared support value, and an unrecognised
     * feature returns null.
     *
     * @return void
     */
    public function test_supports_known_features(): void {
        $this->assertSame(MOD_PURPOSE_CONTENT, playervideo_supports(FEATURE_MOD_PURPOSE));
        $this->assertTrue(playervideo_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(playervideo_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertTrue(playervideo_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(playervideo_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(playervideo_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(playervideo_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertNull(playervideo_supports('unknown_feature'));
    }

    /**
     * Tests that the two capabilities handing back other students' names, grades and free-text
     * answers carry the RISK_PERSONAL flag, so the role-definition UI warns when they are granted.
     *
     * @return void
     */
    public function test_report_capabilities_declare_personal_data_risk(): void {
        foreach (['mod/playervideo:viewreports', 'mod/playervideo:reviewresponses'] as $capability) {
            $info = get_capability_info($capability);
            $this->assertNotNull($info, $capability);
            $this->assertSame(RISK_PERSONAL, (int) $info->riskbitmask & RISK_PERSONAL, $capability);
        }
    }

    /**
     * Tests that mod/playervideo:attempt — which lets a student persist free text
     * (playervideo_responses.responsetext) later shown to a teacher and sent to an AI provider —
     * carries RISK_SPAM, mirroring the equivalent core capability mod/quiz:attempt.
     *
     * @return void
     */
    public function test_attempt_capability_declares_spam_risk(): void {
        $info = get_capability_info('mod/playervideo:attempt');
        $this->assertNotNull($info);
        $this->assertSame(RISK_SPAM, (int) $info->riskbitmask & RISK_SPAM);
    }

    /**
     * Tests that add_instance() persists the submitted fields and creates a grade_item.
     *
     * @return void
     */
    public function test_add_instance_persists_fields_and_grade_item(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, [
            'name' => 'Check instance',
            'grade' => 80,
            'gradepass' => 40,
        ]);

        $record = $DB->get_record('playervideo', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('Check instance', $record->name);
        $this->assertSame('youtube', $record->videotype);
        $this->assertGreaterThan(0, (int) $record->timecreated);

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'playervideo',
            'iteminstance' => $instance->id, 'itemnumber' => 0, 'courseid' => $course->id,
        ]);
        $this->assertNotNull($gradeitem);
        $this->assertSame(80.0, (float) $gradeitem->grademax);
        // Regression guard for the gradepass fix: grade_update() silently drops a
        // 'gradepass' key in $itemdetails, so this must be asserted on the real grade_item,
        // never just on playervideo_grade_item_update()'s GRADE_UPDATE_OK return value —
        // that return is identical whether the fix is present or not.
        $this->assertSame(40.0, (float) $gradeitem->gradepass);
    }

    /**
     * Tests that a videotype of html5 stores a null videourl (the video itself lives in the
     * File API, not this column).
     *
     * @return void
     */
    public function test_add_instance_html5_stores_no_videourl(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, ['videotype' => 'html5', 'videourl' => 'ignored']);

        $this->assertNull($DB->get_field('playervideo', 'videourl', ['id' => $instance->id]));
    }

    /**
     * Tests that update_instance() persists the new field values.
     *
     * @return void
     */
    public function test_update_instance_persists_fields(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id);

        $update = $DB->get_record('playervideo', ['id' => $instance->id], '*', MUST_EXIST);
        $update->instance = $instance->id;
        $update->coursemodule = $instance->cmid;
        $update->name = 'Renamed';
        $update->grade = 50;

        $result = playervideo_update_instance($update);

        $this->assertTrue($result);
        $this->assertSame('Renamed', $DB->get_field('playervideo', 'name', ['id' => $instance->id]));

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'playervideo',
            'iteminstance' => $instance->id, 'itemnumber' => 0, 'courseid' => $course->id,
        ]);
        $this->assertSame(50.0, (float) $gradeitem->grademax);
    }

    /**
     * Tests that swapping the video source clears the reconciled duration — it belonged to the
     * old video and there is no form field to correct it by hand.
     *
     * @return void
     */
    public function test_update_instance_clears_duration_on_source_change(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id);
        $DB->set_field('playervideo', 'duration', 600, ['id' => $instance->id]);

        $update = $DB->get_record('playervideo', ['id' => $instance->id], '*', MUST_EXIST);
        $update->instance = $instance->id;
        $update->coursemodule = $instance->cmid;
        $update->name = 'Same video, new name';

        playervideo_update_instance($update);
        $this->assertSame(600.0, (float) $DB->get_field('playervideo', 'duration', ['id' => $instance->id]));

        $update = $DB->get_record('playervideo', ['id' => $instance->id], '*', MUST_EXIST);
        $update->instance = $instance->id;
        $update->coursemodule = $instance->cmid;
        $update->videourl = 'https://www.youtube.com/watch?v=oHg5SJYRHA0';

        playervideo_update_instance($update);
        $this->assertNull($DB->get_field('playervideo', 'duration', ['id' => $instance->id]));
    }

    /**
     * Tests that deleting an instance also deletes every one of its child tables' rows —
     * every plugin table keyed by the instance's own ID must be cleared, not just the
     * instance's own row.
     *
     * @return void
     */
    public function test_delete_instance_also_deletes_child_tables(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id);
        $id = $instance->id;

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $id, 'timestamp' => 5, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'hi', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $id, 'userid' => 2, 'attemptnumber' => 1, 'status' => 'inprogress',
            'grade' => null, 'hudretrycharged' => 0, 'timestart' => $now, 'timefinish' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $id, 'userid' => 2, 'attemptid' => $attemptid,
            'interactionid' => $interactionid, 'questionid' => null, 'answerid' => null,
            'responsetext' => null, 'iscorrect' => null, 'hudrewarded' => 0, 'aigrade' => null,
            'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'viewed', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_progress', (object) [
            'playervideoid' => $id, 'userid' => 2, 'lastposition' => 5, 'watchedpct' => 50,
            'watchedtoend' => 0, 'segments' => '[]', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_captions', (object) [
            'playervideoid' => $id, 'lang' => 'en', 'source' => 'manual', 'content' => 'WEBVTT',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $pollid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $id, 'timestamp' => 10, 'type' => 'poll', 'weight' => 0,
            'questionid' => null, 'notetext' => 'Pick one', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $pollid, 'optiontext' => 'A', 'sortorder' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_disummaries', (object) [
            'playervideoid' => $id, 'lang' => 'en', 'content' => 'Easy-read summary.',
            'status' => \mod_playervideo\local\di_summary_service::STATUS_PENDING,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $result = playervideo_delete_instance($id);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('playervideo', ['id' => $id]));
        foreach (
            ['playervideo_interactions', 'playervideo_attempts', 'playervideo_responses',
                'playervideo_progress', 'playervideo_captions', 'playervideo_disummaries'] as $table
        ) {
            $this->assertSame(0, $DB->count_records($table, ['playervideoid' => $id]), "$table not cleared");
        }
        $this->assertSame(0, $DB->count_records('playervideo_poll_options', ['interactionid' => $pollid]));
    }

    /**
     * Tests that deleting a non-existent instance returns false without erroring.
     *
     * @return void
     */
    public function test_delete_instance_unknown_id_returns_false(): void {
        $this->assertFalse(playervideo_delete_instance(999999));
    }

    /**
     * Tests that get_coursemodule_info() only populates customdata when completion tracking
     * is automatic, and carries the two rule values otherwise. videotype/videourl/showinline are
     * always present, regardless of completion tracking mode — cm_info_dynamic() relies on them
     * to skip its own database query.
     *
     * @return void
     */
    public function test_get_coursemodule_info_customdata(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, [
            'completionallinteractions' => 1,
            'completionwatchtoend' => 0,
            'videotype' => 'youtube',
            'showinline' => 1,
        ]);

        $automatic = (object) ['instance' => $instance->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC];
        $info = playervideo_get_coursemodule_info($automatic);
        $this->assertSame(1, $info->customdata['customcompletionrules']['completionallinteractions']);
        $this->assertSame(0, $info->customdata['customcompletionrules']['completionwatchtoend']);
        $this->assertSame('youtube', $info->customdata['videotype']);
        $this->assertSame(1, $info->customdata['showinline']);

        $manual = (object) ['instance' => $instance->id, 'completion' => COMPLETION_TRACKING_MANUAL];
        $infomanual = playervideo_get_coursemodule_info($manual);
        $this->assertArrayNotHasKey('customcompletionrules', $infomanual->customdata);
        $this->assertSame(1, $infomanual->customdata['showinline']);
    }

    /**
     * Tests that questions_in_use() delegates to question_service — false with no reference,
     * true once an interaction references the question.
     *
     * @return void
     */
    public function test_questions_in_use_delegates_to_question_service(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id);

        $this->assertFalse(playervideo_questions_in_use([555]));

        $now = time();
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 5, 'type' => 'question', 'weight' => 1,
            'questionid' => 555, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->assertTrue(playervideo_questions_in_use([555]));
    }

    /**
     * Tests that update_grades() aggregates finished attempts per the instance's grademethod
     * and writes the result to the gradebook.
     *
     * @return void
     */
    public function test_update_grades_aggregates_finished_attempts(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, ['grademethod' => attempt_manager::GRADE_HIGHEST]);

        $userid = 2;
        $now = time();
        foreach ([60.0, 90.0] as $grade) {
            $DB->insert_record('playervideo_attempts', (object) [
                'playervideoid' => $instance->id, 'userid' => $userid, 'attemptnumber' => 1,
                'status' => 'finished', 'grade' => $grade, 'hudretrycharged' => 0,
                'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        playervideo_update_grades($instance, $userid);

        $grades = grade_get_grades($course->id, 'mod', 'playervideo', $instance->id, $userid);
        $usergrade = $grades->items[0]->grades[$userid];
        $this->assertSame(90.0, (float) $usergrade->grade);
    }

    /**
     * Tests that update_grades() with no specific userid (a full recompute — e.g. after the
     * teacher edits weights) aggregates and writes the grade for every student with a finished
     * attempt, via the bulk aggregation path.
     *
     * @return void
     */
    public function test_update_grades_recomputes_every_student_when_no_userid_given(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, ['grademethod' => attempt_manager::GRADE_HIGHEST]);

        $now = time();
        foreach ([2 => 60.0, 3 => 90.0] as $userid => $grade) {
            $DB->insert_record('playervideo_attempts', (object) [
                'playervideoid' => $instance->id, 'userid' => $userid, 'attemptnumber' => 1,
                'status' => 'finished', 'grade' => $grade, 'hudretrycharged' => 0,
                'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        playervideo_update_grades($instance);

        $grades = grade_get_grades($course->id, 'mod', 'playervideo', $instance->id, [2, 3]);
        $this->assertSame(60.0, (float) $grades->items[0]->grades[2]->grade);
        $this->assertSame(90.0, (float) $grades->items[0]->grades[3]->grade);
    }

    /**
     * Builds a real settings_navigation object for $cm, the only way to exercise
     * playervideo_extend_settings_navigation() as core actually calls it.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $instance Activity instance (must have a cmid property).
     * @return \settings_navigation
     */
    private function build_settings_navigation(\stdClass $course, \stdClass $instance): \settings_navigation {
        global $PAGE;

        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url('/mod/playervideo/view.php', ['id' => $cm->id]);

        return new \settings_navigation($PAGE);
    }

    /**
     * Tests that a teacher (mod/playervideo:manage) gets a "Manage interactions" node pointing
     * at interactions.php — without this, the timeline editor has no link anywhere in the UI.
     *
     * @return void
     */
    public function test_extend_settings_navigation_adds_link_for_manager(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $settingsnav = $this->build_settings_navigation($course, $instance);
        $node = new \navigation_node('PlayerVideo');

        playervideo_extend_settings_navigation($settingsnav, $node);

        $added = $node->get('mod_playervideo_manageinteractions');
        $this->assertNotFalse($added);
        $this->assertStringContainsString(
            "interactions.php?id={$settingsnav->get_page()->cm->id}",
            $added->action->out(false)
        );
    }

    /**
     * Tests that a student (no mod/playervideo:manage) gets no such node.
     *
     * @return void
     */
    public function test_extend_settings_navigation_skips_students(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $settingsnav = $this->build_settings_navigation($course, $instance);
        $node = new \navigation_node('PlayerVideo');

        playervideo_extend_settings_navigation($settingsnav, $node);

        $this->assertFalse($node->get('mod_playervideo_manageinteractions'));
    }

    /**
     * Builds a real cm_info for the given instance, the type playervideo_cm_info_dynamic()
     * expects.
     *
     * @param \stdClass $instance Instance record, with a cmid property.
     * @return \cm_info
     */
    private function build_cm_info(\stdClass $instance): \cm_info {
        $cm = get_coursemodule_from_id('playervideo', $instance->cmid, 0, true, MUST_EXIST);
        return \cm_info::create($cm);
    }

    /**
     * A "pin to course page" instance renders the video embed as the cm's content.
     *
     * @return void
     */
    public function test_cm_info_dynamic_sets_content_when_showinline_enabled(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, ['showinline' => 1]);
        $cminfo = $this->build_cm_info($instance);

        playervideo_cm_info_dynamic($cminfo);

        $this->assertStringContainsString('ph-video-embed', $cminfo->content);
    }

    /**
     * Regression test: the inline embed must render a real link to the full activity page,
     * and the cm's own URL must stay set (never nulled via set_no_view_link()) — otherwise
     * the activity becomes unreachable everywhere on the site (the "Activities" listing,
     * recent activity, calendar, any "next/previous activity" navigation), not just missing
     * its interactive timeline inline. Found live: a real course with "pin to course page"
     * enabled had no way at all to reach the questions or the grade.
     *
     * @return void
     */
    public function test_cm_info_dynamic_keeps_a_path_to_the_full_activity(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, ['showinline' => 1]);
        $cminfo = $this->build_cm_info($instance);

        playervideo_cm_info_dynamic($cminfo);

        $this->assertNotNull($cminfo->url);
        $this->assertStringContainsString("id={$cminfo->id}", $cminfo->url->out(false));
        $this->assertStringContainsString('/mod/playervideo/view.php', $cminfo->content);
    }

    /**
     * An instance with "pin to course page" left off never sets any content. showinline is
     * already known from customdata (populated by get_coursemodule_info() when modinfo was
     * built), verified directly — not by counting queries here, which is too noisy across a
     * shared PHPUnit run (deferred cache/log writes from unrelated earlier tests land at
     * unpredictable points); see moodle-query-baseline for that measurement instead.
     *
     * @return void
     */
    public function test_cm_info_dynamic_does_nothing_when_showinline_disabled(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id);
        $cminfo = $this->build_cm_info($instance);

        $this->assertSame(0, $cminfo->customdata['showinline']);

        playervideo_cm_info_dynamic($cminfo);

        $this->assertSame('', $cminfo->content);
    }
}
