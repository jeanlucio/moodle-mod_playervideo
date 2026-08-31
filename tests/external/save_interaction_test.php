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
 * External function tests for save_interaction.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_save_interaction web service.
 *
 * @covers \mod_playervideo\external\save_interaction
 */
final class save_interaction_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Editing teacher, enrolled in $course. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_save_interaction', array_merge([
            'interactionid' => 0,
            'timestamp' => 0.0,
            'type' => '',
            'questionid' => 0,
            'notetext' => '',
            'polloptions' => [],
            'weight' => 1.0,
            'delete' => false,
        ], $args));
    }

    /**
     * Creates a playervideo instance in $this->course.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        return $generator->create_instance(array_merge(['course' => $this->course->id], $overrides));
    }

    /**
     * Tests that a note interaction is created with the expected fields.
     *
     * @return void
     */
    public function test_creates_a_note(): void {
        global $DB;

        $instance = $this->make_instance();

        $result = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 12.5,
            'type' => 'note',
            'notetext' => 'Pay attention',
        ]);

        $this->assertFalse($result['error']);
        $id = $result['data']['interactionid'];
        $record = $DB->get_record('playervideo_interactions', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('note', $record->type);
        $this->assertSame('Pay attention', $record->notetext);
        $this->assertNull($record->questionid);
    }

    /**
     * Tests that a question interaction requires a real, existing question id.
     *
     * @return void
     */
    public function test_question_requires_a_real_question(): void {
        $instance = $this->make_instance();

        $result = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 5,
            'type' => 'question',
            'questionid' => 999999,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_questionnotfound', $result['exception']->errorcode);
    }

    /**
     * Tests that an empty note is rejected.
     *
     * @return void
     */
    public function test_empty_note_is_rejected(): void {
        $instance = $this->make_instance();

        $result = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 5,
            'type' => 'note',
            'notetext' => '   ',
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_notetextrequired', $result['exception']->errorcode);
    }

    /**
     * Tests that updating an existing interaction changes its fields in place, without
     * creating a new row.
     *
     * @return void
     */
    public function test_updates_an_existing_interaction(): void {
        global $DB;

        $instance = $this->make_instance();
        $create = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 5,
            'type' => 'note',
            'notetext' => 'Original',
        ]);
        $interactionid = $create['data']['interactionid'];

        $update = $this->call([
            'playervideoid' => $instance->id,
            'interactionid' => $interactionid,
            'timestamp' => 8,
            'type' => 'note',
            'notetext' => 'Updated',
        ]);

        $this->assertFalse($update['error']);
        $this->assertSame($interactionid, $update['data']['interactionid']);
        $this->assertSame(1, $DB->count_records('playervideo_interactions', ['playervideoid' => $instance->id]));
        $this->assertSame('Updated', $DB->get_field('playervideo_interactions', 'notetext', ['id' => $interactionid]));
    }

    /**
     * Tests that an interaction with no responses can be deleted.
     *
     * @return void
     */
    public function test_deletes_an_interaction_without_responses(): void {
        global $DB;

        $instance = $this->make_instance();
        $create = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 5,
            'type' => 'note',
            'notetext' => 'Temp',
        ]);
        $interactionid = $create['data']['interactionid'];

        $result = $this->call([
            'playervideoid' => $instance->id,
            'interactionid' => $interactionid,
            'delete' => true,
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse($DB->record_exists('playervideo_interactions', ['id' => $interactionid]));
    }

    /**
     * Tests that an interaction with existing student responses cannot be deleted — the
     * antifraude/history-preserving rule (see the plugin SCOPE).
     *
     * @return void
     */
    public function test_cannot_delete_an_interaction_with_responses(): void {
        global $DB;

        $instance = $this->make_instance();
        $create = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 5,
            'type' => 'note',
            'notetext' => 'Temp',
        ]);
        $interactionid = $create['data']['interactionid'];

        $now = time();
        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => 2, 'attemptnumber' => 1, 'status' => 'inprogress',
            'grade' => null, 'hudretrycharged' => 0, 'timestart' => $now, 'timefinish' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $instance->id, 'userid' => 2, 'attemptid' => $attemptid,
            'interactionid' => $interactionid, 'questionid' => null, 'answerid' => null,
            'responsetext' => null, 'iscorrect' => null, 'hudrewarded' => 0, 'aigrade' => null,
            'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'viewed', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $result = $this->call([
            'playervideoid' => $instance->id,
            'interactionid' => $interactionid,
            'delete' => true,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_interactionhasresponses', $result['exception']->errorcode);
        $this->assertTrue($DB->record_exists('playervideo_interactions', ['id' => $interactionid]));
    }

    /**
     * Tests instance isolation: an interactionid from a different playervideo instance is
     * never accepted, even though the id itself exists in the database.
     *
     * @return void
     */
    public function test_cross_instance_interactionid_is_rejected(): void {
        $instancea = $this->make_instance();
        $instanceb = $this->make_instance();

        $created = $this->call([
            'playervideoid' => $instancea->id,
            'timestamp' => 5,
            'type' => 'note',
            'notetext' => 'From A',
        ]);
        $interactionid = $created['data']['interactionid'];

        $result = $this->call([
            'playervideoid' => $instanceb->id,
            'interactionid' => $interactionid,
            'timestamp' => 5,
            'type' => 'note',
            'notetext' => 'Hijacked',
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_interactionnotfound', $result['exception']->errorcode);
    }

    /**
     * Tests that a poll interaction is created with its options, in order.
     *
     * @return void
     */
    public function test_creates_a_poll_with_options(): void {
        global $DB;

        $instance = $this->make_instance();

        $result = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'What is your favourite colour?',
            'polloptions' => ['Red', 'Blue', 'Green'],
        ]);

        $this->assertFalse($result['error']);
        $interactionid = $result['data']['interactionid'];
        $this->assertSame(
            'What is your favourite colour?',
            $DB->get_field('playervideo_interactions', 'notetext', ['id' => $interactionid])
        );
        $options = array_values($DB->get_records(
            'playervideo_poll_options',
            ['interactionid' => $interactionid],
            'sortorder ASC'
        ));
        $this->assertCount(3, $options);
        $this->assertSame(['Red', 'Blue', 'Green'], array_map(static fn($o) => $o->optiontext, $options));
    }

    /**
     * Tests that a poll needs at least 2 options.
     *
     * @return void
     */
    public function test_poll_rejects_too_few_options(): void {
        $instance = $this->make_instance();

        $result = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['Only one'],
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidpolloptioncount', $result['exception']->errorcode);
    }

    /**
     * Tests that a poll cannot have more than 6 options.
     *
     * @return void
     */
    public function test_poll_rejects_too_many_options(): void {
        $instance = $this->make_instance();

        $result = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidpolloptioncount', $result['exception']->errorcode);
    }

    /**
     * Tests that a poll's options can be freely replaced while it has no votes yet.
     *
     * @return void
     */
    public function test_editing_a_poll_before_any_votes_replaces_options_freely(): void {
        global $DB;

        $instance = $this->make_instance();
        $created = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['Red', 'Blue'],
        ]);
        $interactionid = $created['data']['interactionid'];

        $this->call([
            'playervideoid' => $instance->id,
            'interactionid' => $interactionid,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['Yellow', 'Purple', 'Orange'],
        ]);

        $options = array_values($DB->get_records(
            'playervideo_poll_options',
            ['interactionid' => $interactionid],
            'sortorder ASC'
        ));
        $this->assertSame(['Yellow', 'Purple', 'Orange'], array_map(static fn($o) => $o->optiontext, $options));
    }

    /**
     * Tests that a poll's options are entirely frozen once it has any votes — reconciling by
     * position instead would risk silently reassigning an existing vote to a different option
     * text, which is worse than refusing the edit outright.
     *
     * @return void
     */
    public function test_cannot_change_poll_options_once_voted(): void {
        global $DB;

        $instance = $this->make_instance();
        $created = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['Red', 'Blue'],
        ]);
        $interactionid = $created['data']['interactionid'];
        $blueid = $DB->get_field('playervideo_poll_options', 'id', [
            'interactionid' => $interactionid,
            'optiontext' => 'Blue',
        ]);

        $now = time();
        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => 2, 'attemptnumber' => 1, 'status' => 'inprogress',
            'grade' => null, 'hudretrycharged' => 0, 'timestart' => $now, 'timefinish' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $instance->id, 'userid' => 2, 'attemptid' => $attemptid,
            'interactionid' => $interactionid, 'questionid' => null, 'answerid' => null,
            'polloptionid' => $blueid, 'responsetext' => null, 'iscorrect' => null, 'hudrewarded' => 0,
            'aigrade' => null, 'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'voted', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $result = $this->call([
            'playervideoid' => $instance->id,
            'interactionid' => $interactionid,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['Red', 'Green'],
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_pollhasvotes', $result['exception']->errorcode);
        $this->assertTrue($DB->record_exists('playervideo_poll_options', ['id' => $blueid]));

        // Resubmitting the exact same options (e.g. only the timestamp changed) is a harmless
        // no-op, not an error — nothing about the option set actually changed.
        $unchanged = $this->call([
            'playervideoid' => $instance->id,
            'interactionid' => $interactionid,
            'timestamp' => 45,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['Red', 'Blue'],
        ]);
        $this->assertFalse($unchanged['error']);
        $this->assertSame(2, $DB->count_records('playervideo_poll_options', ['interactionid' => $interactionid]));
    }

    /**
     * Tests that deleting a poll interaction also deletes its options.
     *
     * @return void
     */
    public function test_deleting_a_poll_deletes_its_options(): void {
        global $DB;

        $instance = $this->make_instance();
        $created = $this->call([
            'playervideoid' => $instance->id,
            'timestamp' => 30,
            'type' => 'poll',
            'notetext' => 'Poll prompt?',
            'polloptions' => ['Red', 'Blue'],
        ]);
        $interactionid = $created['data']['interactionid'];

        $result = $this->call([
            'playervideoid' => $instance->id,
            'interactionid' => $interactionid,
            'delete' => true,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(0, $DB->count_records('playervideo_poll_options', ['interactionid' => $interactionid]));
    }
}
