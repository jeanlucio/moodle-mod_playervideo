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

        $result = playervideo_delete_instance($id);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('playervideo', ['id' => $id]));
        foreach (
            ['playervideo_interactions', 'playervideo_attempts', 'playervideo_responses',
                'playervideo_progress', 'playervideo_captions'] as $table
        ) {
            $this->assertSame(0, $DB->count_records($table, ['playervideoid' => $id]), "$table not cleared");
        }
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
     * is automatic, and carries the two rule values otherwise.
     *
     * @return void
     */
    public function test_get_coursemodule_info_customdata(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->create_instance($course->id, [
            'completionallinteractions' => 1,
            'completionwatchtoend' => 0,
        ]);

        $automatic = (object) ['instance' => $instance->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC];
        $info = playervideo_get_coursemodule_info($automatic);
        $this->assertSame(1, $info->customdata['customcompletionrules']['completionallinteractions']);
        $this->assertSame(0, $info->customdata['customcompletionrules']['completionwatchtoend']);

        $manual = (object) ['instance' => $instance->id, 'completion' => COMPLETION_TRACKING_MANUAL];
        $infomanual = playervideo_get_coursemodule_info($manual);
        $this->assertNull($infomanual->customdata);
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
}
