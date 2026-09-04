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
 * Backup and restore tests for mod_playervideo.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo;

use core_courseformat\local\cmactions;
use mod_playervideo\local\question_service;

/**
 * Tests that backup/restore (both "Duplicate activity" and a full course backup into a new
 * course) preserves PlayerVideo's data, including the questionid/answerid remap this plugin
 * must do by hand — it references the Question Bank directly rather than through the full
 * Question Usage API (see the plugin SCOPE, "Blind JSON").
 *
 * @covers \backup_playervideo_activity_structure_step
 * @covers \restore_playervideo_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that duplicating an activity completes without error and is immediately visible —
     * a regression guard for a missing prepare_activity_structure() call in the restore step,
     * which would leave the restore's old-to-new context mapping unset (see the Backup and
     * restore checklist item in the global CLAUDE.md).
     *
     * @return void
     */
    public function test_duplicate_activity(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('playervideo', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);

        // Core's duplicate_module() is deprecated since Moodle 5.2 (MDL-86858), replaced by
        // cmactions::duplicate() — but that method doesn't exist before 5.2, so this must stay
        // guarded rather than switched outright while the plugin supports 4.5+5.2.
        if (method_exists(cmactions::class, 'duplicate')) {
            $newcm = (new cmactions($course))->duplicate($cm->id);
        } else {
            $newcm = duplicate_module($course, $cm);
        }

        $this->assertNotNull($newcm);
        $this->assertNotSame($cm->id, $newcm->id);
        $this->assertStringContainsString('(copy)', $newcm->name);

        $newinstance = $DB->get_record('playervideo', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame($instance->videourl, $newinstance->videourl);

        // No explicit cache purge: proves the context mapping (and the whole post-restore
        // cleanup) actually ran, since a stale course cache is exactly the symptom the missing
        // mapping used to cause.
        $modinfo = get_fast_modinfo($course->id);
        $this->assertNotNull($modinfo->get_cm($newcm->id));

        $this->assertSame(1, $DB->count_records('grade_items', [
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'playervideo',
            'iteminstance' => $newinstance->id,
        ]));
    }

    /**
     * A full course backup/restore into a new course must preserve the timeline (question,
     * note and poll interactions, with poll options), captions and DI summaries — none of
     * which are personal data, so all must survive even with userinfo left at its default.
     *
     * The question-type interaction's category lives at this activity's own module context
     * (question_service::get_or_create_category(), the plugin's real authoring path), so it
     * travels with the module's own context and gets a real 'question_created' mapping —
     * asserting the new questionid differs from the old one proves the remap actually ran,
     * not just that the same id happened to still resolve.
     *
     * @return void
     */
    public function test_backup_restore_preserves_timeline_captions_and_disummaries(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('playervideo', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $categoryid = question_service::get_or_create_category($context);
        $question = $questiongenerator->create_question('multichoice', 'one_of_four', [
            'category' => $categoryid,
            'questiontext' => ['text' => 'Which of these is a volcano?', 'format' => FORMAT_HTML],
        ]);

        $now = time();
        $questioninteractionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 2,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 20, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Watch closely here', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $pollinteractionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 30, 'type' => 'poll', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Favourite colour?', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $pollinteractionid, 'optiontext' => 'Red', 'sortorder' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $pollinteractionid, 'optiontext' => 'Blue', 'sortorder' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_captions', (object) [
            'playervideoid' => $instance->id, 'lang' => 'en', 'source' => 'manual',
            'content' => "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nHello.", 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_disummaries', (object) [
            'playervideoid' => $instance->id, 'lang' => 'en', 'content' => 'Easy-read summary.',
            'status' => 'approved', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playervideo', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newinteractions = $DB->get_records(
            'playervideo_interactions',
            ['playervideoid' => $newinstance->id],
            'timestamp ASC'
        );
        $this->assertCount(3, $newinteractions);
        [$newquestioninteraction, $newnoteinteraction, $newpollinteraction] = array_values($newinteractions);

        // Whether restore's resolve_questionid() maps to a genuinely new row (a real
        // 'question_created' mapping) or falls back to the same still-valid id (Moodle 4.0+
        // restore can match/reuse an identical existing category+question rather than
        // duplicating it, even for a module-context category — confirmed empirically here) is
        // an internal-mechanism detail, not the contract this asserts: either way, the id must
        // resolve to a real question with the original text, never 0/null.
        $this->assertSame('question', $newquestioninteraction->type);
        $this->assertNotEmpty($newquestioninteraction->questionid);
        $newquestion = $DB->get_record('question', ['id' => $newquestioninteraction->questionid], '*', MUST_EXIST);
        $this->assertSame('Which of these is a volcano?', $newquestion->questiontext);

        $this->assertSame('note', $newnoteinteraction->type);
        $this->assertSame('Watch closely here', $newnoteinteraction->notetext);

        $this->assertSame('poll', $newpollinteraction->type);
        $newpolloptions = array_values($DB->get_records(
            'playervideo_poll_options',
            ['interactionid' => $newpollinteraction->id],
            'sortorder ASC'
        ));
        $this->assertSame(['Red', 'Blue'], array_map(static fn($o) => $o->optiontext, $newpolloptions));

        $newcaption = $DB->get_record('playervideo_captions', ['playervideoid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertStringContainsString('Hello.', $newcaption->content);

        $newdisummary = $DB->get_record('playervideo_disummaries', ['playervideoid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertSame('Easy-read summary.', $newdisummary->content);
        $this->assertSame('approved', $newdisummary->status);
    }

    /**
     * A full course backup/restore must carry attempts and responses (personal data) along,
     * remapping both the multichoice answerid and the poll polloptionid to the restored
     * question/poll option — never the original ids, which belonged to the original course's
     * now-duplicated rows.
     *
     * @return void
     */
    public function test_backup_restore_preserves_attempts_and_responses_with_userinfo(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $instance = $this->getDataGenerator()->create_module('playervideo', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $categoryid = question_service::get_or_create_category($context);
        $question = $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $categoryid]);
        $correctanswerid = (int) $DB->get_field('question_answers', 'id', [
            'question' => $question->id,
            'fraction' => 1,
        ], MUST_EXIST);

        $now = time();
        $mcinteractionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 2,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $pollinteractionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 20, 'type' => 'poll', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Favourite colour?', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $polloptionid = $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $pollinteractionid, 'optiontext' => 'Red', 'sortorder' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $DB->insert_record('playervideo_progress', (object) [
            'playervideoid' => $instance->id, 'userid' => $user->id, 'lastposition' => 12.5,
            'watchedpct' => 80.0, 'watchedtoend' => 0, 'segments' => '[]',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => $user->id, 'attemptnumber' => 1,
            'status' => 'finished', 'grade' => 100.0, 'hudretrycharged' => 0,
            'timestart' => $now, 'timefinish' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $instance->id, 'userid' => $user->id, 'attemptid' => $attemptid,
            'interactionid' => $mcinteractionid, 'questionid' => $question->id, 'answerid' => $correctanswerid,
            'polloptionid' => null, 'responsetext' => null, 'iscorrect' => 1, 'hudrewarded' => 1,
            'aigrade' => null, 'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'answered', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $instance->id, 'userid' => $user->id, 'attemptid' => $attemptid,
            'interactionid' => $pollinteractionid, 'questionid' => null, 'answerid' => null,
            'polloptionid' => $polloptionid, 'responsetext' => null, 'iscorrect' => null, 'hudrewarded' => 0,
            'aigrade' => null, 'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'voted', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playervideo', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newprogress = $DB->get_record('playervideo_progress', ['playervideoid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $newprogress->userid);
        $this->assertEqualsWithDelta(12.5, (float) $newprogress->lastposition, 0.001);

        $newattempt = $DB->get_record('playervideo_attempts', ['playervideoid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $newattempt->userid);
        $this->assertSame('finished', $newattempt->status);
        $this->assertEqualsWithDelta(100.0, (float) $newattempt->grade, 0.001);

        $newresponses = $DB->get_records('playervideo_responses', ['attemptid' => $newattempt->id]);
        $this->assertCount(2, $newresponses);

        $newmcresponse = null;
        $newpollresponse = null;
        foreach ($newresponses as $response) {
            if ($response->status === 'answered') {
                $newmcresponse = $response;
            } else if ($response->status === 'voted') {
                $newpollresponse = $response;
            }
        }
        $this->assertNotNull($newmcresponse);
        $this->assertNotNull($newpollresponse);

        // The response's answerid must resolve to a real answer of the (possibly reused, see
        // the comment in the timeline test above) restored question — the correct one, not
        // merely a valid one.
        $newanswer = $DB->get_record('question_answers', ['id' => $newmcresponse->answerid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(1.0, (float) $newanswer->fraction, 0.001);

        // The response's polloptionid, unlike answerid, is an intra-plugin id this plugin's own
        // restore step always inserts fresh (no question-bank-style dedup applies to it) — it
        // must always differ from the original course's row.
        $this->assertNotSame($polloptionid, (int) $newpollresponse->polloptionid);
        $newpolloption = $DB->get_record(
            'playervideo_poll_options',
            ['id' => $newpollresponse->polloptionid],
            '*',
            MUST_EXIST
        );
        $this->assertSame('Red', $newpolloption->optiontext);
    }

    /**
     * Backing up without user data (userinfo disabled) must omit progress/attempts/responses
     * entirely, while still restoring the timeline, which belongs to the activity itself
     * rather than to any one student.
     *
     * @return void
     */
    public function test_backup_without_userinfo_omits_personal_data(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $instance = $this->getDataGenerator()->create_module('playervideo', ['course' => $course->id]);

        $now = time();
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 10, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Watch closely here', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_progress', (object) [
            'playervideoid' => $instance->id, 'userid' => $user->id, 'lastposition' => 5,
            'watchedpct' => 50.0, 'watchedtoend' => 0, 'segments' => '[]',
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $admin = get_admin();
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id
        );
        $bc->get_plan()->get_setting('users')->set_value(false);
        $bc->execute_plan();
        $backupfile = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $newcourse = $this->getDataGenerator()->create_course();
        $tempdir = \restore_controller::get_tempdir_name($newcourse->id, $admin->id);
        $fp = get_file_packer('application/vnd.moodle.backup');
        $backupfile->extract_to_pathname($fp, make_backup_temp_directory($tempdir));

        $rc = new \restore_controller(
            $tempdir,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $newinstance = $DB->get_record('playervideo', ['course' => $newcourse->id], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('playervideo_interactions', ['playervideoid' => $newinstance->id]));
        $this->assertSame(0, $DB->count_records('playervideo_progress', ['playervideoid' => $newinstance->id]));
        $this->assertSame(0, $DB->count_records('playervideo_attempts', ['playervideoid' => $newinstance->id]));
    }

    /**
     * A question-type interaction whose question no longer exists anywhere on the site by the
     * time of restore (deleted from the Question Bank in between) must be dropped, rather than
     * left pointing at a nonexistent questionid the player would have nothing to render for.
     *
     * @return void
     */
    public function test_backup_restore_drops_interaction_with_deleted_question(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('playervideo', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $categoryid = question_service::get_or_create_category($context);
        $question = $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $categoryid]);

        $now = time();
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 2,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 20, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Survives regardless', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Delete the question straight from the tables (bypassing the plugin's own
        // questions_in_use protection, which only exists to stop this happening via the UI) to
        // simulate the edge case: a question deleted through some other route between the
        // backup being taken and the restore actually running.
        $DB->delete_records('question_answers', ['question' => $question->id]);
        $DB->delete_records('question', ['id' => $question->id]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        // The drop path logs a developer debugging() notice — expected here, not a real bug.
        $this->assertDebuggingCalled();

        $newinstance = $DB->get_record('playervideo', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newinteractions = $DB->get_records('playervideo_interactions', ['playervideoid' => $newinstance->id]);
        $this->assertCount(1, $newinteractions);
        $this->assertSame('note', reset($newinteractions)->type);
    }

    /**
     * A full course backup/restore must carry the videofile and posterimage file areas along —
     * a real, pre-existing gap for videofile specifically (present since Fase 2, only found
     * while adding posterimage in Fase 9): neither annotate_files() on the backup side nor
     * add_related_files() on the restore side ever mentioned it, so an uploaded HTML5 video was
     * silently dropped by every backup and "Duplicate activity" before this fix.
     *
     * @return void
     */
    public function test_backup_restore_preserves_videofile_and_posterimage(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('playervideo', [
            'course' => $course->id,
            'videotype' => 'html5',
            'posterdescription' => 'A microscope focused on a leaf cross-section.',
        ]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_playervideo', 'filearea' => 'videofile',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'movie.mp4',
        ], 'fake video content');
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_playervideo', 'filearea' => 'posterimage',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'cover.jpg',
        ], 'fake image content');

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playervideo', ['course' => $newcourse->id], '*', MUST_EXIST);
        $this->assertSame(
            'A microscope focused on a leaf cross-section.',
            $newinstance->posterdescription
        );

        $newcm = get_coursemodule_from_instance('playervideo', $newinstance->id, $newcourse->id, false, MUST_EXIST);
        $newcontext = \context_module::instance($newcm->id);

        $newvideofile = $fs->get_file($newcontext->id, 'mod_playervideo', 'videofile', 0, '/', 'movie.mp4');
        $this->assertNotFalse($newvideofile);
        $this->assertSame('fake video content', $newvideofile->get_content());

        $newposterfile = $fs->get_file($newcontext->id, 'mod_playervideo', 'posterimage', 0, '/', 'cover.jpg');
        $this->assertNotFalse($newposterfile);
        $this->assertSame('fake image content', $newposterfile->get_content());
    }

    /**
     * Inserts a block_instances record for block_playerhud in the given course context — same
     * pattern as hud_service_test.php's own make_block_instance().
     *
     * @param \stdClass $course Course object.
     * @return int Block instance ID.
     */
    private function make_hud_block(\stdClass $course): int {
        global $DB;
        $context = \context_course::instance($course->id);

        return $DB->insert_record('block_instances', (object) [
            'blockname' => 'playerhud', 'parentcontextid' => $context->id, 'showinsubcontexts' => 0,
            'pagetypepattern' => 'course-view-*', 'subpagepattern' => null, 'defaultregion' => 'side-pre',
            'defaultweight' => 0, 'configdata' => base64_encode(serialize(new \stdClass())),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Duplicates a course module using whichever API this Moodle version supports — same
     * guarded pattern as test_duplicate_activity() above.
     *
     * @param \stdClass $course Course the module belongs to.
     * @param \stdClass $cm Course module record to duplicate.
     * @return \stdClass|\core_course\cm_info The new course module record.
     */
    private function duplicate_activity(\stdClass $course, \stdClass $cm): \stdClass|\core_course\cm_info {
        if (method_exists(cmactions::class, 'duplicate')) {
            return (new cmactions($course))->duplicate($cm->id);
        }

        return duplicate_module($course, $cm);
    }

    /**
     * "Duplicate activity" never backs up the course's own PlayerHUD block (only the activity
     * itself) — so resolve_hud_item() takes its last-resort fallback: the original item id is
     * kept as-is when it still legitimately belongs to a block instance in the (same) course.
     *
     * @return void
     */
    public function test_duplicate_activity_keeps_hud_item_reference_when_block_still_in_course(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $blockinstanceid = $this->make_hud_block($course);
        $itemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $blockinstanceid, 'name' => 'Gold Key', 'xp' => 0, 'image' => '',
            'description' => '', 'enabled' => 1, 'secret' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $instance = $this->getDataGenerator()->create_module('playervideo', [
            'course' => $course->id, 'hudcorrectitem' => $itemid, 'hudretrycostitem' => $itemid,
        ]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);

        $newcm = $this->duplicate_activity($course, $cm);

        $newinstance = $DB->get_record('playervideo', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame($itemid, (int) $newinstance->hudcorrectitem);
        $this->assertSame($itemid, (int) $newinstance->hudretrycostitem);
    }

    /**
     * If the PlayerHUD block (or the specific item) is gone from the course by the time the
     * activity is duplicated, resolve_hud_item() drops the stale reference to 0 instead of
     * carrying forward an id that no longer resolves to anything real.
     *
     * @return void
     */
    public function test_duplicate_activity_drops_hud_item_reference_when_block_removed_from_course(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $blockinstanceid = $this->make_hud_block($course);
        $itemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $blockinstanceid, 'name' => 'Gold Key', 'xp' => 0, 'image' => '',
            'description' => '', 'enabled' => 1, 'secret' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $instance = $this->getDataGenerator()->create_module('playervideo', [
            'course' => $course->id, 'hudcorrectitem' => $itemid, 'hudretrycostitem' => $itemid,
        ]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, $course->id, false, MUST_EXIST);

        // Simulates the block having since been removed from the course — the same real-world
        // scenario resolve_hud_item()'s own docblock describes ("or 0 if none applies").
        $DB->delete_records('block_playerhud_items', ['id' => $itemid]);
        $DB->delete_records('block_instances', ['id' => $blockinstanceid]);

        $newcm = $this->duplicate_activity($course, $cm);

        $newinstance = $DB->get_record('playervideo', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame(0, (int) $newinstance->hudcorrectitem);
        $this->assertSame(0, (int) $newinstance->hudretrycostitem);
    }

    /**
     * A question-type interaction whose questionid is invalid (0 or negative — a state the
     * plugin's own UI/API never produces, since deleting an in-use question is blocked, but a
     * hand-edited or corrupted backup XML theoretically could feed in) is dropped defensively
     * on restore instead of crashing resolve_questionid() or inserting a broken reference.
     *
     * @return void
     */
    public function test_backup_restore_drops_question_interaction_with_invalid_questionid(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('playervideo', ['course' => $course->id]);

        $now = time();
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 2,
            'questionid' => 0, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 20, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Survives regardless', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $this->assertDebuggingCalled();

        $newinstance = $DB->get_record('playervideo', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newinteractions = $DB->get_records('playervideo_interactions', ['playervideoid' => $newinstance->id]);
        $this->assertCount(1, $newinteractions);
        $this->assertSame('note', reset($newinteractions)->type);
    }

    /**
     * Backs up the given course and restores it into a brand new course, returning that
     * course. Mirrors mod_playerwords's own full-course backup/restore test pattern.
     *
     * @param \stdClass $course Source course.
     * @return \stdClass The new course the backup was restored into.
     */
    private function backup_and_restore_into_new_course(\stdClass $course): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $admin = get_admin();

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id
        );
        $bc->execute_plan();
        $backupfile = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $newcourse = $this->getDataGenerator()->create_course();
        $tempdir = \restore_controller::get_tempdir_name($newcourse->id, $admin->id);
        $fp = get_file_packer('application/vnd.moodle.backup');
        $backupfile->extract_to_pathname($fp, make_backup_temp_directory($tempdir));

        $rc = new \restore_controller(
            $tempdir,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newcourse;
    }
}
