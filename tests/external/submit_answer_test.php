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
 * External function tests for submit_answer.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;
use moodle_database;

/**
 * Tests for the mod_playervideo_submit_answer web service.
 *
 * @covers \mod_playervideo\external\submit_answer
 */
final class submit_answer_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Student, enrolled in $course. */
    private \stdClass $student;

    /** @var \stdClass PlayerVideo instance used by every test. */
    private \stdClass $instance;

    /** @var int Open attempt id for $this->student on $this->instance. */
    private int $attemptid;

    /** @var ?moodle_database Second, independent connection used to simulate a concurrent request. */
    private ?moodle_database $seconddb = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);

        $this->setUser($this->student);
        $started = $this->call_start_attempt();
        $this->attemptid = $started['data']['attemptid'];
    }

    #[\Override]
    protected function tearDown(): void {
        if ($this->seconddb !== null) {
            $this->seconddb->dispose();
            $this->seconddb = null;
        }
        parent::tearDown();
    }

    /**
     * Starts an attempt through the real web service.
     *
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_start_attempt(): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_start_attempt', [
            'playervideoid' => $this->instance->id,
        ]);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_submit_answer', array_merge([
            'attemptid' => $this->attemptid,
            'answerid' => 0,
            'responsetext' => '',
            'polloptionid' => 0,
        ], $args));
    }

    /**
     * Creates a note interaction on $this->instance.
     *
     * @return int Interaction id.
     */
    private function make_note_interaction(): int {
        global $DB;

        $now = time();
        return $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 5, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'A note', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Creates a true/false question interaction on $this->instance, returning both the
     * interaction id and the correct/incorrect answer ids.
     *
     * @return array{interactionid: int, correctanswerid: int, wronganswerid: int}
     */
    private function make_truefalse_interaction(): array {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'correctanswer' => true,
        ]);

        $answers = $DB->get_records('question_answers', ['question' => $question->id]);
        $correctanswerid = 0;
        $wronganswerid = 0;
        foreach ($answers as $answer) {
            if ((float) $answer->fraction >= 1.0) {
                $correctanswerid = (int) $answer->id;
            } else {
                $wronganswerid = (int) $answer->id;
            }
        }

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 1,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        return ['interactionid' => $interactionid, 'correctanswerid' => $correctanswerid, 'wronganswerid' => $wronganswerid];
    }

    /**
     * Creates a poll interaction with two options on $this->instance.
     *
     * @return array{interactionid: int, optionaid: int, optionbid: int}
     */
    private function make_poll_interaction(): array {
        global $DB;

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 15, 'type' => 'poll', 'weight' => 1,
            'questionid' => null, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $optionaid = $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $interactionid, 'optiontext' => 'Option A', 'sortorder' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $optionbid = $DB->insert_record('playervideo_poll_options', (object) [
            'interactionid' => $interactionid, 'optiontext' => 'Option B', 'sortorder' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        return ['interactionid' => $interactionid, 'optionaid' => $optionaid, 'optionbid' => $optionbid];
    }

    /**
     * Tests that voting on a poll records status 'voted' with no correctness, and never grants
     * a PlayerHUD item even when the instance has one configured.
     *
     * @return void
     */
    public function test_records_a_poll_vote(): void {
        global $DB;

        // A PlayerHUD item configured on the instance must still never be granted for a poll.
        $DB->set_field('playervideo', 'hudcorrectitem', 999, ['id' => $this->instance->id]);
        $fixture = $this->make_poll_interaction();

        $result = $this->call(['interactionid' => $fixture['interactionid'], 'polloptionid' => $fixture['optionaid']]);

        $this->assertFalse($result['error']);
        $this->assertNull($result['data']['iscorrect']);
        $this->assertSame('voted', $result['data']['status']);

        $response = $DB->get_record('playervideo_responses', ['interactionid' => $fixture['interactionid']], '*', MUST_EXIST);
        $this->assertSame((int) $fixture['optionaid'], (int) $response->polloptionid);
        $this->assertNull($response->iscorrect);
        $this->assertSame(0, (int) $response->hudrewarded);
    }

    /**
     * Tests that a poll option id from a different interaction is rejected, even though the
     * row itself exists in the database (instance isolation).
     *
     * @return void
     */
    public function test_rejects_a_poll_option_from_another_interaction(): void {
        $first = $this->make_poll_interaction();
        $second = $this->make_poll_interaction();

        $result = $this->call(['interactionid' => $second['interactionid'], 'polloptionid' => $first['optionaid']]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidpolloption', $result['exception']->errorcode);
    }

    /**
     * Tests that dismissing a note records a 'viewed' response with no correctness.
     *
     * @return void
     */
    public function test_records_a_note_as_viewed(): void {
        global $DB;

        $interactionid = $this->make_note_interaction();

        $result = $this->call(['interactionid' => $interactionid]);

        $this->assertFalse($result['error']);
        $this->assertNull($result['data']['iscorrect']);
        $this->assertSame('viewed', $result['data']['status']);
        $this->assertSame('viewed', $DB->get_field('playervideo_responses', 'status', ['interactionid' => $interactionid]));
    }

    /**
     * Tests that a correct true/false answer is recorded as correct.
     *
     * @return void
     */
    public function test_records_a_correct_answer(): void {
        $fixture = $this->make_truefalse_interaction();

        $result = $this->call([
            'interactionid' => $fixture['interactionid'],
            'answerid' => $fixture['correctanswerid'],
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['iscorrect']);
        $this->assertSame('answered', $result['data']['status']);
        // No hudcorrectitem configured on this instance — nothing to grant or announce.
        $this->assertFalse($result['data']['hudrewarded']);
        $this->assertNull($result['data']['hudrewardname']);
    }

    /**
     * Tests that a wrong true/false answer is recorded as incorrect.
     *
     * @return void
     */
    public function test_records_an_incorrect_answer(): void {
        $fixture = $this->make_truefalse_interaction();

        $result = $this->call([
            'interactionid' => $fixture['interactionid'],
            'answerid' => $fixture['wronganswerid'],
        ]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['iscorrect']);
        $this->assertFalse($result['data']['hudrewarded']);
    }

    /**
     * Tests that a correct answer on an instance with a configured hudcorrectitem is reported
     * back as rewarded, with the item's real display name — the overlay's reward toast (Fase
     * 11) needs both to show "+1 <name>" instead of a placeholder.
     *
     * @return void
     */
    public function test_reports_hud_reward_on_a_correct_answer(): void {
        global $DB;

        $DB->set_field('playervideo', 'hudcorrectitem', 999, ['id' => $this->instance->id]);
        $fixture = $this->make_truefalse_interaction();

        $result = $this->call([
            'interactionid' => $fixture['interactionid'],
            'answerid' => $fixture['correctanswerid'],
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['hudrewarded']);
        // The block_playerhud plugin is not installed in this test environment, so the real
        // display name degrades to an empty string (hud_service::get_item_name()) rather than
        // erroring — the assertion that matters here is that hudrewarded/hudrewardname are
        // always present together, never one without the other.
        $this->assertSame('', $result['data']['hudrewardname']);

        $response = $DB->get_record(
            'playervideo_responses',
            ['interactionid' => $fixture['interactionid']],
            '*',
            MUST_EXIST
        );
        $this->assertSame(1, (int) $response->hudrewarded);
    }

    /**
     * Tests that a second submission for the same interaction, in the same attempt, is
     * refused — the antifraude lock enforced by attempt_lock.
     *
     * @return void
     */
    public function test_rejects_a_second_submission(): void {
        $interactionid = $this->make_note_interaction();

        $this->call(['interactionid' => $interactionid]);
        $result = $this->call(['interactionid' => $interactionid]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_interactionalreadyanswered', $result['exception']->errorcode);
    }

    /**
     * Tests that an attempt belonging to a different student is refused.
     *
     * @return void
     */
    public function test_rejects_someone_elses_attempt(): void {
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $this->course->id, 'student');

        $interactionid = $this->make_note_interaction();

        $this->setUser($otherstudent);
        $result = $this->call(['interactionid' => $interactionid]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_notyourattempt', $result['exception']->errorcode);
    }

    /**
     * Tests that an attempt that is no longer in progress refuses new responses.
     *
     * @return void
     */
    public function test_rejects_when_attempt_is_not_in_progress(): void {
        global $DB;

        $DB->set_field('playervideo_attempts', 'status', 'finished', ['id' => $this->attemptid]);
        $interactionid = $this->make_note_interaction();

        $result = $this->call(['interactionid' => $interactionid]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_attemptnotinprogress', $result['exception']->errorcode);
    }

    /**
     * Regression test for the PlayerHUD double-reward race: a
     * second, genuinely concurrent request for the same attempt+interaction must be refused
     * outright by the attempt_lock, instead of being allowed to re-run the already-answered/
     * already-rewarded checks and grant the item a second time. Simulates concurrency with a
     * second, independent database connection holding the same lock key submit_answer uses.
     *
     * @return void
     */
    public function test_concurrent_submission_for_the_same_interaction_is_locked_out(): void {
        $interactionid = $this->make_note_interaction();
        $lockkey = 'answer_' . $this->attemptid . '_' . $interactionid;
        $otherlock = $this->acquire_on_second_connection($lockkey);

        try {
            $result = $this->call(['interactionid' => $interactionid]);
        } finally {
            $otherlock->release();
        }

        $this->assertTrue($result['error']);
        $this->assertSame('error_attemptlockbusy', $result['exception']->errorcode);
    }

    /**
     * Acquires the mod_playervideo lock factory's lock for the given resource key from a
     * second, independently-connected database session, so contention against it reflects a
     * genuinely different session rather than this test's own (self-reentrant) connection.
     * Advisory locks are reentrant per connection, so a second lock_factory instance on the
     * same connection would never actually block.
     *
     * @param string $resourcekey The resource key to lock.
     * @return \core\lock\lock The lock, held on the second connection; the caller must release it.
     */
    private function acquire_on_second_connection(string $resourcekey): \core\lock\lock {
        global $DB;

        $cfg = $DB->export_dbconfig();
        if (!isset($cfg->dboptions)) {
            $cfg->dboptions = [];
        }

        $this->seconddb = moodle_database::get_driver_instance($cfg->dbtype, $cfg->dblibrary);
        $this->seconddb->connect($cfg->dbhost, $cfg->dbuser, $cfg->dbpass, $cfg->dbname, $cfg->prefix, $cfg->dboptions);

        $original = $GLOBALS['DB'];
        $GLOBALS['DB'] = $this->seconddb;
        try {
            $factory = \core\lock\lock_config::get_lock_factory('mod_playervideo');
            $lock = $factory->get_lock($resourcekey, 1);
        } finally {
            $GLOBALS['DB'] = $original;
        }

        if (!$lock) {
            $this->fail('Precondition: the second connection must be able to acquire the lock.');
        }

        return $lock;
    }
}
