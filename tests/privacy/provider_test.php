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
 * Privacy provider tests for mod_playervideo.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_playervideo\local\intro_service;

/**
 * Tests for the Privacy API provider.
 *
 * @covers \mod_playervideo\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creates a playervideo course module and returns the cm record.
     *
     * @param \stdClass $course Course object.
     * @return \stdClass Course module record.
     */
    private function make_cm(\stdClass $course): \stdClass {
        return $this->getDataGenerator()->create_module('playervideo', ['course' => $course->id]);
    }

    /**
     * Inserts one playback progress row.
     *
     * @param int $userid User id.
     * @param int $playervideoid Activity instance id.
     * @return void
     */
    private function make_progress(int $userid, int $playervideoid): void {
        global $DB;
        $DB->insert_record('playervideo_progress', (object) [
            'playervideoid' => $playervideoid,
            'userid' => $userid,
            'lastposition' => 42.5,
            'watchedpct' => 60.0,
            'watchedtoend' => 0,
            'segments' => '[[0,42.5]]',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts one attempt row.
     *
     * @param int $userid User id.
     * @param int $playervideoid Activity instance id.
     * @param float $grade Grade to store, deliberately distinct per call so a test
     *                     asserting on it cannot be accidentally satisfied by another field.
     * @return int Inserted attempt id.
     */
    private function make_attempt(int $userid, int $playervideoid, float $grade = 80.0): int {
        global $DB;
        return $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $playervideoid,
            'userid' => $userid,
            'attemptnumber' => 1,
            'status' => 'finished',
            'grade' => $grade,
            'hudretrycharged' => 0,
            'timestart' => time(),
            'timefinish' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts one response row.
     *
     * @param int $userid User id.
     * @param int $playervideoid Activity instance id.
     * @param int $attemptid Attempt id this response belongs to.
     * @param int $interactionid Interaction id being responded to.
     * @return int Inserted response id.
     */
    private function make_response(int $userid, int $playervideoid, int $attemptid, int $interactionid): int {
        global $DB;
        return $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $playervideoid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'interactionid' => $interactionid,
            'questionid' => null,
            'answerid' => null,
            'polloptionid' => null,
            'responsetext' => 'Minha resposta',
            'iscorrect' => 1,
            'hudrewarded' => 0,
            'aigrade' => null,
            'aifeedback' => null,
            'teachergrade' => null,
            'teacherfeedback' => '',
            'status' => 'answered',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts one interaction row (catalog data, not personal), to reference from a
     * response row's interactionid.
     *
     * @param int $playervideoid Activity instance id.
     * @return int Inserted interaction id.
     */
    private function make_interaction(int $playervideoid): int {
        global $DB;
        return $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $playervideoid,
            'timestamp' => 5,
            'type' => 'note',
            'weight' => 1,
            'questionid' => null,
            'notetext' => 'Note',
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Tests that get_metadata declares all three personal-data tables and the site-wide
     * "seen intro" user preference.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_playervideo');
        $collection = provider::get_metadata($collection);
        $keys = array_map(fn($item) => $item->get_name(), $collection->get_collection());

        $this->assertContains('playervideo_progress', $keys);
        $this->assertContains('playervideo_attempts', $keys);
        $this->assertContains('playervideo_responses', $keys);
        $this->assertContains(intro_service::get_preference_name(), $keys);
    }

    /**
     * Tests that the declared playervideo_responses field keys match every real column
     * of the table (minus id) — the richest of the three tables. Asserted as a
     * set-equality against $DB->get_columns() rather than checking individual keys one
     * by one, so a future column silently added to install.xml without a matching
     * metadata entry fails this test.
     *
     * @return void
     */
    public function test_get_metadata_playervideo_responses_fields_match_schema(): void {
        global $DB;

        $tableitem = null;
        foreach (provider::get_metadata(new collection('mod_playervideo'))->get_collection() as $item) {
            if ($item->get_name() === 'playervideo_responses') {
                $tableitem = $item;
                break;
            }
        }
        $this->assertNotNull($tableitem);

        $declaredfields = array_keys($tableitem->get_privacy_fields());
        $realcolumns = array_values(array_diff(array_keys($DB->get_columns('playervideo_responses')), ['id']));

        sort($declaredfields);
        sort($realcolumns);
        $this->assertSame($realcolumns, $declaredfields);
    }

    /**
     * A user who never had the intro preference set exports no preference data.
     *
     * @return void
     */
    public function test_export_user_preferences_no_pref(): void {
        $user = $this->getDataGenerator()->create_user();

        provider::export_user_preferences($user->id);

        $this->assertFalse(writer::with_context(\context_system::instance())->has_any_data());
    }

    /**
     * A user who has seen the intro exports exactly that one preference.
     *
     * @return void
     */
    public function test_export_user_preferences_seen(): void {
        $user = $this->getDataGenerator()->create_user();
        intro_service::mark_intro_seen((int) $user->id);

        provider::export_user_preferences($user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());
        $prefs = (array) $writer->get_user_preferences('mod_playervideo');
        $this->assertCount(1, $prefs);
        $this->assertArrayHasKey(intro_service::get_preference_name(), $prefs);
    }

    /**
     * Tests that get_contexts_for_userid finds the context via each of the three
     * personal-data tables independently.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_finds_every_source_table(): void {
        $course = $this->getDataGenerator()->create_course();

        $cmprogress = $this->make_cm($course);
        $userprogress = $this->getDataGenerator()->create_user();
        $this->make_progress($userprogress->id, (int) $cmprogress->id);

        $cmattempt = $this->make_cm($course);
        $userattempt = $this->getDataGenerator()->create_user();
        $this->make_attempt($userattempt->id, (int) $cmattempt->id);

        $cmresponse = $this->make_cm($course);
        $userresponse = $this->getDataGenerator()->create_user();
        $interactionid = $this->make_interaction((int) $cmresponse->id);
        $attemptid = $this->make_attempt($userresponse->id, (int) $cmresponse->id);
        $this->make_response($userresponse->id, (int) $cmresponse->id, $attemptid, $interactionid);

        $this->assertContains(
            (string) \context_module::instance($cmprogress->cmid)->id,
            provider::get_contexts_for_userid($userprogress->id)->get_contextids()
        );
        $this->assertContains(
            (string) \context_module::instance($cmattempt->cmid)->id,
            provider::get_contexts_for_userid($userattempt->id)->get_contextids()
        );
        $this->assertContains(
            (string) \context_module::instance($cmresponse->cmid)->id,
            provider::get_contexts_for_userid($userresponse->id)->get_contextids()
        );
    }

    /**
     * Tests that get_users_in_context returns users found across all three tables.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $progressuser = $this->getDataGenerator()->create_user();
        $attemptuser = $this->getDataGenerator()->create_user();
        $this->make_progress($progressuser->id, (int) $cm->id);
        $this->make_attempt($attemptuser->id, (int) $cm->id);

        $userlist = new userlist(\context_module::instance($cm->cmid), 'mod_playervideo');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();

        $this->assertContains((int) $progressuser->id, $userids);
        $this->assertContains((int) $attemptuser->id, $userids);
    }

    /**
     * Tests that export_user_data writes progress, attempts and responses for the user.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $interactionid = $this->make_interaction((int) $cm->id);
        $this->make_progress($user->id, (int) $cm->id);
        $attemptid = $this->make_attempt($user->id, (int) $cm->id, 90.0);
        $this->make_response($user->id, (int) $cm->id, $attemptid, $interactionid);

        $context = \context_module::instance($cm->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playervideo', [$context->id]);
        provider::export_user_data($contextlist);

        $progressdata = writer::with_context($context)->get_data([
            get_string('pluginname', 'mod_playervideo'),
            get_string('privacy:progress', 'mod_playervideo'),
        ]);
        $this->assertEquals(42.5, (float) $progressdata->lastposition);

        $attemptsdata = writer::with_context($context)->get_data([
            get_string('pluginname', 'mod_playervideo'),
            get_string('privacy:attempts', 'mod_playervideo'),
        ]);
        $this->assertNotEmpty($attemptsdata->attempts);
        $this->assertSame(90.0, (float) $attemptsdata->attempts[0]['grade']);

        $responsesdata = writer::with_context($context)->get_data([
            get_string('pluginname', 'mod_playervideo'),
            get_string('privacy:responses', 'mod_playervideo'),
        ]);
        $this->assertNotEmpty($responsesdata->responses);
        $this->assertSame('Minha resposta', $responsesdata->responses[0]['responsetext']);
        $this->assertSame($interactionid, (int) $responsesdata->responses[0]['interactionid']);
    }

    /**
     * Tests that export_user_data is a no-op for an empty approved contextlist.
     *
     * @return void
     */
    public function test_export_user_data_empty_contextlist_is_noop(): void {
        $user = $this->getDataGenerator()->create_user();
        provider::export_user_data(new approved_contextlist($user, 'mod_playervideo', []));
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that delete_data_for_user removes only that user's rows, keeping another
     * user's data in the same instance untouched.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();
        $interactionid = $this->make_interaction((int) $cm->id);

        $this->make_progress($user->id, (int) $cm->id);
        $attemptid = $this->make_attempt($user->id, (int) $cm->id);
        $this->make_response($user->id, (int) $cm->id, $attemptid, $interactionid);

        $this->make_progress($otheruser->id, (int) $cm->id);
        $otherattemptid = $this->make_attempt($otheruser->id, (int) $cm->id);
        $this->make_response($otheruser->id, (int) $cm->id, $otherattemptid, $interactionid);

        $context = \context_module::instance($cm->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playervideo', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $DB->count_records('playervideo_progress', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('playervideo_attempts', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('playervideo_responses', ['userid' => $user->id]));

        $this->assertSame(1, $DB->count_records('playervideo_progress', ['userid' => $otheruser->id]));
        $this->assertSame(1, $DB->count_records('playervideo_attempts', ['userid' => $otheruser->id]));
        $this->assertSame(1, $DB->count_records('playervideo_responses', ['userid' => $otheruser->id]));
    }

    /**
     * Tests that delete_data_for_user is a no-op for an empty approved contextlist.
     *
     * @return void
     */
    public function test_delete_data_for_user_empty_contextlist_is_noop(): void {
        $user = $this->getDataGenerator()->create_user();
        provider::delete_data_for_user(new approved_contextlist($user, 'mod_playervideo', []));
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that delete_data_for_users removes data for the listed users only.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->make_attempt($user1->id, (int) $cm->id);
        $this->make_attempt($user2->id, (int) $cm->id);

        $context = \context_module::instance($cm->cmid);
        $approvedlist = new approved_userlist($context, 'mod_playervideo', [$user1->id]);
        provider::delete_data_for_users($approvedlist);

        $this->assertSame(0, $DB->count_records('playervideo_attempts', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('playervideo_attempts', ['userid' => $user2->id]));
    }

    /**
     * Tests that delete_data_for_all_users_in_context clears every user's data within
     * that context only, leaving another activity's data untouched.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cmtarget = $this->make_cm($course);
        $cmother = $this->make_cm($course);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $this->make_attempt($user1->id, (int) $cmtarget->id);
        $this->make_attempt($user2->id, (int) $cmtarget->id);
        $this->make_attempt($user1->id, (int) $cmother->id);

        provider::delete_data_for_all_users_in_context(\context_module::instance($cmtarget->cmid));

        $this->assertSame(0, $DB->count_records('playervideo_attempts', ['playervideoid' => (int) $cmtarget->id]));
        $this->assertSame(1, $DB->count_records('playervideo_attempts', ['playervideoid' => (int) $cmother->id]));
    }

    /**
     * Tests that delete_data_for_all_users_in_context is a silent no-op for a
     * non-module context.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context_ignores_non_module_context(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($user->id, (int) $cm->id);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(1, $DB->count_records('playervideo_attempts', ['playervideoid' => (int) $cm->id]));
    }

    /**
     * Tests that get_users_in_context is a silent no-op for a non-module context.
     *
     * @return void
     */
    public function test_get_users_in_context_ignores_non_module_context(): void {
        $userlist = new userlist(\context_system::instance(), 'mod_playervideo');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }
}
