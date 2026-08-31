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
 * Unit tests for the attempt lifecycle and grade aggregation service.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for attempt_manager.
 *
 * @covers \mod_playervideo\local\attempt_manager
 */
final class attempt_manager_test extends \advanced_testcase {
    /** @var int Fake instance id used throughout these tests; no real playervideo row is needed. */
    private const PLAYERVIDEOID = 1;

    /** @var int Fake student id used throughout these tests. */
    private const USERID = 2;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Inserts a playervideo_interactions row and returns its id.
     *
     * @param string $type 'question' or 'note'.
     * @param float $weight Grading weight (ignored for notes).
     * @return int The new interaction id.
     */
    private function create_interaction(string $type, float $weight = 1.0): int {
        global $DB;

        $now = time();

        return $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => self::PLAYERVIDEOID,
            'timestamp' => 10.0,
            'type' => $type,
            'weight' => $weight,
            'questionid' => $type === 'question' ? 999 : null,
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Inserts a playervideo_responses row for the given attempt/interaction and returns its id.
     *
     * @param int $attemptid The attempt id.
     * @param int $interactionid The interaction id.
     * @param array $overrides Field overrides (e.g. iscorrect, teachergrade, status).
     * @return int The new response id.
     */
    private function create_response(int $attemptid, int $interactionid, array $overrides = []): int {
        global $DB;

        $now = time();

        $record = array_merge([
            'playervideoid' => self::PLAYERVIDEOID,
            'userid' => self::USERID,
            'attemptid' => $attemptid,
            'interactionid' => $interactionid,
            'questionid' => null,
            'answerid' => null,
            'responsetext' => null,
            'iscorrect' => null,
            'hudrewarded' => 0,
            'aigrade' => null,
            'aifeedback' => null,
            'teachergrade' => null,
            'teacherfeedback' => null,
            'status' => 'answered',
            'timecreated' => $now,
            'timemodified' => $now,
        ], $overrides);

        return $DB->insert_record('playervideo_responses', (object) $record);
    }

    /**
     * Tests that attempts are numbered sequentially per student/instance, and that an attempt
     * cannot be started while another one is still open.
     *
     * @return void
     */
    public function test_start_attempt_numbers_sequentially_and_blocks_a_second_open_one(): void {
        $first = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $this->assertSame(1, $first->attemptnumber);
        $this->assertSame('inprogress', $first->status);

        $this->expectException(\coding_exception::class);
        attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
    }

    /**
     * Tests that a second attempt is numbered 2 once the first one is finished.
     *
     * @return void
     */
    public function test_start_attempt_after_finishing_the_previous_one(): void {
        $first = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        attempt_manager::finish_attempt((int) $first->id, 100.0);

        $second = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);

        $this->assertSame(2, $second->attemptnumber);
    }

    /**
     * Tests get_open_attempt(): null with no attempts, the attempt while inprogress, and null
     * again once it is finished.
     *
     * @return void
     */
    public function test_get_open_attempt(): void {
        $this->assertNull(attempt_manager::get_open_attempt(self::PLAYERVIDEOID, self::USERID));

        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $open = attempt_manager::get_open_attempt(self::PLAYERVIDEOID, self::USERID);
        $this->assertNotNull($open);
        $this->assertSame((int) $attempt->id, (int) $open->id);

        attempt_manager::finish_attempt((int) $attempt->id, 100.0);
        $this->assertNull(attempt_manager::get_open_attempt(self::PLAYERVIDEOID, self::USERID));
    }

    /**
     * Tests can_start_new_attempt(): unlimited when maxattempts is 0, and blocked once the
     * total attempt count (of any status) reaches maxattempts. It only counts attempts already
     * taken — it is not responsible for open-attempt exclusivity, which is the caller's job
     * (playervideo_start_attempt resumes an open attempt via get_open_attempt() instead of
     * calling start_attempt() again).
     *
     * @return void
     */
    public function test_can_start_new_attempt_respects_maxattempts(): void {
        $this->assertTrue(attempt_manager::can_start_new_attempt(self::PLAYERVIDEOID, self::USERID, 0));

        $this->assertTrue(attempt_manager::can_start_new_attempt(self::PLAYERVIDEOID, self::USERID, 2));
        $first = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);

        // One attempt taken so far (still open), limit is 2: one slot remains.
        $this->assertTrue(attempt_manager::can_start_new_attempt(self::PLAYERVIDEOID, self::USERID, 2));

        attempt_manager::finish_attempt((int) $first->id, 100.0);
        attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);

        // Two attempts taken, limit is 2: no slots remain.
        $this->assertFalse(attempt_manager::can_start_new_attempt(self::PLAYERVIDEOID, self::USERID, 2));
    }

    /**
     * Tests has_pending_correction(): false with no responses, true while an open-question
     * response is pending AI or teacher review, false again once graded.
     *
     * @return void
     */
    public function test_has_pending_correction(): void {
        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $interaction = $this->create_interaction('question');

        $this->assertFalse(attempt_manager::has_pending_correction((int) $attempt->id));

        $responseid = $this->create_response((int) $attempt->id, $interaction, ['status' => 'pending_review']);
        $this->assertTrue(attempt_manager::has_pending_correction((int) $attempt->id));

        global $DB;
        $DB->set_field('playervideo_responses', 'status', 'graded', ['id' => $responseid]);
        $this->assertFalse(attempt_manager::has_pending_correction((int) $attempt->id));
    }

    /**
     * Tests calculate_attempt_grade() with two equally-weighted questions, one correct and one
     * unanswered: half of the maximum grade.
     *
     * @return void
     */
    public function test_calculate_attempt_grade_with_equal_weights(): void {
        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $correct = $this->create_interaction('question', 1.0);
        $this->create_interaction('question', 1.0);

        $this->create_response((int) $attempt->id, $correct, ['iscorrect' => 1]);

        $grade = attempt_manager::calculate_attempt_grade((int) $attempt->id, 100.0);

        $this->assertSame(50.0, $grade);
    }

    /**
     * Tests calculate_attempt_grade() with unequal weights: a heavier correct question
     * outweighs a lighter incorrect one proportionally.
     *
     * @return void
     */
    public function test_calculate_attempt_grade_with_custom_weights(): void {
        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $heavy = $this->create_interaction('question', 3.0);
        $light = $this->create_interaction('question', 1.0);

        $this->create_response((int) $attempt->id, $heavy, ['iscorrect' => 1]);
        $this->create_response((int) $attempt->id, $light, ['iscorrect' => 0]);

        $grade = attempt_manager::calculate_attempt_grade((int) $attempt->id, 100.0);

        $this->assertSame(75.0, $grade);
    }

    /**
     * Tests that an open question's teachergrade (already scaled within its own weight) is used
     * directly as points earned, and that a note interaction never enters the weight sum.
     *
     * @return void
     */
    public function test_calculate_attempt_grade_uses_teachergrade_and_ignores_notes(): void {
        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $open = $this->create_interaction('question', 2.0);
        $this->create_interaction('question', 2.0);
        $this->create_interaction('note');

        $this->create_response((int) $attempt->id, $open, ['teachergrade' => 1.5, 'status' => 'graded']);

        $grade = attempt_manager::calculate_attempt_grade((int) $attempt->id, 100.0);

        // Weight sum is 4 (the note never counts); points earned is 1.5.
        $this->assertSame(37.5, $grade);
    }

    /**
     * Tests that calculate_attempt_grade() returns null when the instance has no question
     * interactions at all.
     *
     * @return void
     */
    public function test_calculate_attempt_grade_returns_null_without_questions(): void {
        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);

        $this->assertNull(attempt_manager::calculate_attempt_grade((int) $attempt->id, 100.0));
    }

    /**
     * Tests finish_attempt(): an attempt with no pending correction is marked finished with its
     * computed grade; one with a pending open-question response is marked pendingcorrection with
     * no grade at all.
     *
     * @return void
     */
    public function test_finish_attempt_gates_on_pending_correction(): void {
        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $interaction = $this->create_interaction('question', 1.0);
        $this->create_response((int) $attempt->id, $interaction, ['iscorrect' => 1]);

        $finished = attempt_manager::finish_attempt((int) $attempt->id, 100.0);
        $this->assertSame('finished', $finished->status);
        $this->assertSame(100.0, $finished->grade);
        $this->assertNotNull($finished->timefinish);

        $secondattempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $secondinteraction = $this->create_interaction('question', 1.0);
        $this->create_response((int) $secondattempt->id, $secondinteraction, ['status' => 'pending_review']);

        $pending = attempt_manager::finish_attempt((int) $secondattempt->id, 100.0);
        $this->assertSame('pendingcorrection', $pending->status);
        $this->assertNull($pending->grade);
    }

    /**
     * Tests aggregate_final_grade(): null with no finished attempts, and each GRADE_* method
     * picking the right value across three finished attempts (60, 80, 100).
     *
     * @return void
     */
    public function test_aggregate_final_grade_per_method(): void {
        $this->assertNull(attempt_manager::aggregate_final_grade(
            self::PLAYERVIDEOID,
            self::USERID,
            attempt_manager::GRADE_HIGHEST
        ));

        global $DB;
        foreach ([60.0, 100.0, 80.0] as $grade) {
            $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
            $attempt->status = 'finished';
            $attempt->grade = $grade;
            $attempt->timefinish = time();
            $DB->update_record('playervideo_attempts', $attempt);
        }

        // Attempt order was 60 (1st), 100 (2nd), 80 (3rd, last).
        $this->assertSame(100.0, attempt_manager::aggregate_final_grade(
            self::PLAYERVIDEOID,
            self::USERID,
            attempt_manager::GRADE_HIGHEST
        ));
        $this->assertSame(80.0, attempt_manager::aggregate_final_grade(
            self::PLAYERVIDEOID,
            self::USERID,
            attempt_manager::GRADE_AVERAGE
        ));
        $this->assertSame(60.0, attempt_manager::aggregate_final_grade(
            self::PLAYERVIDEOID,
            self::USERID,
            attempt_manager::GRADE_FIRST
        ));
        $this->assertSame(80.0, attempt_manager::aggregate_final_grade(
            self::PLAYERVIDEOID,
            self::USERID,
            attempt_manager::GRADE_LAST
        ));
    }

    /**
     * Tests that a pendingcorrection attempt is excluded from aggregation, even if it has a
     * (provisional) grade value lingering from before it was reopened for review.
     *
     * @return void
     */
    public function test_aggregate_final_grade_excludes_pending_correction(): void {
        global $DB;

        $attempt = attempt_manager::start_attempt(self::PLAYERVIDEOID, self::USERID);
        $attempt->status = 'pendingcorrection';
        $attempt->grade = null;
        $DB->update_record('playervideo_attempts', $attempt);

        $this->assertNull(attempt_manager::aggregate_final_grade(
            self::PLAYERVIDEOID,
            self::USERID,
            attempt_manager::GRADE_HIGHEST
        ));
    }
}
