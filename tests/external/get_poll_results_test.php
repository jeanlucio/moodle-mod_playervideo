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
 * External function tests for get_poll_results.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_get_poll_results web service.
 *
 * @covers \mod_playervideo\external\get_poll_results
 */
final class get_poll_results_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass PlayerVideo instance used by every test. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param int $interactionid Poll interaction id.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(int $interactionid): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_get_poll_results', [
            'interactionid' => $interactionid,
        ]);
    }

    /**
     * Creates a poll interaction with two options, casts the given votes for each, and returns
     * the interaction id and option ids.
     *
     * @param int $votesforoptiona Number of distinct students voting for option A.
     * @param int $votesforoptionb Number of distinct students voting for option B.
     * @return array{interactionid: int, optionaid: int, optionbid: int}
     */
    private function make_poll_with_votes(int $votesforoptiona, int $votesforoptionb): array {
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

        $userid = 100;
        $castvote = function (int $polloptionid) use (&$userid, $interactionid, $now): void {
            global $DB;
            $userid++;
            $attemptid = $DB->insert_record('playervideo_attempts', (object) [
                'playervideoid' => $this->instance->id, 'userid' => $userid, 'attemptnumber' => 1,
                'status' => 'inprogress', 'grade' => null, 'hudretrycharged' => 0,
                'timestart' => $now, 'timefinish' => null, 'timecreated' => $now, 'timemodified' => $now,
            ]);
            $DB->insert_record('playervideo_responses', (object) [
                'playervideoid' => $this->instance->id, 'userid' => $userid, 'attemptid' => $attemptid,
                'interactionid' => $interactionid, 'questionid' => null, 'answerid' => null,
                'polloptionid' => $polloptionid, 'responsetext' => null, 'iscorrect' => null,
                'hudrewarded' => 0, 'aigrade' => null, 'aifeedback' => null, 'teachergrade' => null,
                'teacherfeedback' => null, 'status' => 'voted', 'timecreated' => $now, 'timemodified' => $now,
            ]);
        };

        for ($i = 0; $i < $votesforoptiona; $i++) {
            $castvote($optionaid);
        }
        for ($i = 0; $i < $votesforoptionb; $i++) {
            $castvote($optionbid);
        }

        return ['interactionid' => $interactionid, 'optionaid' => $optionaid, 'optionbid' => $optionbid];
    }

    /**
     * Creates a student, enrols them in $this->course and sets them as the current user.
     *
     * @return \stdClass The enrolled student.
     */
    private function login_as_enrolled_student(): \stdClass {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);
        return $student;
    }

    /**
     * Tests that vote counts and percentages are computed correctly across both options.
     *
     * @return void
     */
    public function test_returns_vote_distribution(): void {
        $fixture = $this->make_poll_with_votes(3, 1);

        $this->login_as_enrolled_student();
        $result = $this->call($fixture['interactionid']);

        $this->assertFalse($result['error']);
        $options = $result['data']['options'];
        $this->assertCount(2, $options);
        $this->assertSame(3, $options[0]['votes']);
        $this->assertSame(75.0, $options[0]['percent']);
        $this->assertSame(1, $options[1]['votes']);
        $this->assertSame(25.0, $options[1]['percent']);
    }

    /**
     * Tests that an option with zero votes reports 0%, not a division-by-zero error.
     *
     * @return void
     */
    public function test_returns_zero_percent_with_no_votes(): void {
        $fixture = $this->make_poll_with_votes(0, 0);

        $this->login_as_enrolled_student();
        $result = $this->call($fixture['interactionid']);

        $this->assertFalse($result['error']);
        foreach ($result['data']['options'] as $option) {
            $this->assertSame(0, $option['votes']);
            $this->assertSame(0.0, $option['percent']);
        }
    }

    /**
     * Tests that a non-poll interaction is rejected.
     *
     * @return void
     */
    public function test_rejects_a_non_poll_interaction(): void {
        global $DB;

        $now = time();
        $interactionid = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instance->id, 'timestamp' => 5, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'A note', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->login_as_enrolled_student();
        $result = $this->call($interactionid);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidinteractiontype', $result['exception']->errorcode);
    }

    /**
     * Tests that a user without mod/playervideo:attempt is denied.
     *
     * @return void
     */
    public function test_requires_attempt_capability(): void {
        $fixture = $this->make_poll_with_votes(1, 0);

        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($viewer->id, $this->course->id, 'guest');
        $this->setUser($viewer);

        $this->expectException(\required_capability_exception::class);
        get_poll_results::execute($fixture['interactionid']);
    }
}
