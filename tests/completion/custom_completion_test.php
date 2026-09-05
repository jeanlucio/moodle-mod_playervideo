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
 * Tests for the mod_playervideo custom completion rules.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\completion;

/**
 * Tests for custom_completion — the two rules covering the "no grade" case:
 * completionallinteractions counts participation across any attempt, completionwatchtoend
 * reflects the player's own native ended event.
 *
 * @covers \mod_playervideo\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creates a course + playervideo instance with both completion rules enabled, and returns
     * its cm_info.
     *
     * @return \cm_info
     */
    private function create_cm(): \cm_info {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->getDataGenerator()->create_module('playervideo', [
            'course' => $course->id,
            'videotype' => 'youtube',
            'videourl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionallinteractions' => 1,
            'completionwatchtoend' => 1,
        ]);

        $modinfo = get_fast_modinfo($course->id);
        return $modinfo->get_cm($cm->cmid);
    }

    /**
     * Tests that get_defined_custom_rules() and get_custom_rule_descriptions() report both
     * rules.
     *
     * @return void
     */
    public function test_defined_rules_and_descriptions(): void {
        $this->assertSame(
            ['completionallinteractions', 'completionwatchtoend'],
            custom_completion::get_defined_custom_rules()
        );

        $cminfo = $this->create_cm();
        $completion = new custom_completion($cminfo, 2);
        $descriptions = $completion->get_custom_rule_descriptions();
        $this->assertArrayHasKey('completionallinteractions', $descriptions);
        $this->assertArrayHasKey('completionwatchtoend', $descriptions);
    }

    /**
     * Tests completionallinteractions: incomplete with an empty timeline, incomplete until
     * every interaction has at least one response (regardless of correctness), complete once
     * all of them do — even mixing a wrong multiple-choice answer with a viewed note.
     *
     * @return void
     */
    public function test_completionallinteractions_state(): void {
        global $DB;

        $cminfo = $this->create_cm();
        $userid = 2;
        $completion = new custom_completion($cminfo, $userid);

        // Empty timeline: never complete, regardless of how the rule is phrased.
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionallinteractions'));

        $now = time();
        $question = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $cminfo->instance, 'timestamp' => 5, 'type' => 'question', 'weight' => 1,
            'questionid' => 999, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $note = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $cminfo->instance, 'timestamp' => 10, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'hi', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Two interactions exist, only one answered: still incomplete.
        $attemptid = $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $cminfo->instance, 'userid' => $userid, 'attemptnumber' => 1,
            'status' => 'inprogress', 'grade' => null, 'hudretrycharged' => 0,
            'timestart' => $now, 'timefinish' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $cminfo->instance, 'userid' => $userid, 'attemptid' => $attemptid,
            'interactionid' => $question, 'questionid' => 999, 'answerid' => 1,
            'responsetext' => null, 'iscorrect' => 0, 'hudrewarded' => 0, 'aigrade' => null,
            'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'answered', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $completionpartial = new custom_completion($cminfo, $userid);
        $this->assertSame(COMPLETION_INCOMPLETE, $completionpartial->get_state('completionallinteractions'));

        // The wrong answer above still counts as "done" once the note is viewed too — the
        // rule cares about participation, never correctness.
        $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $cminfo->instance, 'userid' => $userid, 'attemptid' => $attemptid,
            'interactionid' => $note, 'questionid' => null, 'answerid' => null,
            'responsetext' => null, 'iscorrect' => null, 'hudrewarded' => 0, 'aigrade' => null,
            'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'viewed', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $completionfull = new custom_completion($cminfo, $userid);
        $this->assertSame(COMPLETION_COMPLETE, $completionfull->get_state('completionallinteractions'));
    }

    /**
     * Tests completionwatchtoend: incomplete with no progress row, incomplete while
     * watchedtoend is 0 even with a high watchedpct, complete only once the flag flips to 1 —
     * never a percentage threshold.
     *
     * @return void
     */
    public function test_completionwatchtoend_state(): void {
        global $DB;

        $cminfo = $this->create_cm();
        $userid = 2;

        $this->assertSame(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cminfo, $userid))->get_state('completionwatchtoend')
        );

        $now = time();
        $DB->insert_record('playervideo_progress', (object) [
            'playervideoid' => $cminfo->instance, 'userid' => $userid, 'lastposition' => 590,
            'watchedpct' => 98, 'watchedtoend' => 0, 'segments' => '[]',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cminfo, $userid))->get_state('completionwatchtoend'),
            'A high watched percentage must never complete this rule on its own.'
        );

        $DB->set_field('playervideo_progress', 'watchedtoend', 1, ['playervideoid' => $cminfo->instance, 'userid' => $userid]);
        $this->assertSame(
            COMPLETION_COMPLETE,
            (new custom_completion($cminfo, $userid))->get_state('completionwatchtoend')
        );
    }

    /**
     * Tests that each rule's completion is isolated per user.
     *
     * @return void
     */
    public function test_completion_is_per_user(): void {
        global $DB;

        $cminfo = $this->create_cm();
        $now = time();
        $DB->insert_record('playervideo_progress', (object) [
            'playervideoid' => $cminfo->instance, 'userid' => 2, 'lastposition' => 600,
            'watchedpct' => 100, 'watchedtoend' => 1, 'segments' => '[]',
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->assertSame(
            COMPLETION_COMPLETE,
            (new custom_completion($cminfo, 2))->get_state('completionwatchtoend')
        );
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cminfo, 3))->get_state('completionwatchtoend')
        );
    }
}
