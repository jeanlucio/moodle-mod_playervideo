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
 * Attempt lifecycle and grade aggregation for PlayerVideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use coding_exception;
use stdClass;

/**
 * Opens/closes attempts and aggregates the final activity grade across them.
 *
 * Grading model: each attempt's own
 * grade is the weighted sum of its question interactions, scaled to the instance's maximum
 * grade; the final activity grade is then aggregated across the student's finished attempts
 * according to {@see grademethod}. An attempt with an open question still pending correction is
 * never aggregated — it stays in the pendingcorrection status until the last pending response is
 * reviewed, mirroring mod_quiz's own behaviour of withholding the grade until manual marking is
 * done.
 */
class attempt_manager {
    /** @var int Grade method: the highest grade among all attempts counts. */
    public const GRADE_HIGHEST = 1;

    /** @var int Grade method: the average of all attempts counts. */
    public const GRADE_AVERAGE = 2;

    /** @var int Grade method: only the first attempt counts. */
    public const GRADE_FIRST = 3;

    /** @var int Grade method: only the last attempt counts. */
    public const GRADE_LAST = 4;

    /**
     * Returns the student's attempt still in progress or pending correction, if any.
     *
     * @param int $playervideoid The activity instance id.
     * @param int $userid The student id.
     * @return stdClass|null
     */
    public static function get_open_attempt(int $playervideoid, int $userid): ?stdClass {
        global $DB;

        $attempt = $DB->get_record_select(
            'playervideo_attempts',
            'playervideoid = :playervideoid AND userid = :userid AND status IN (:inprogress, :pending)',
            [
                'playervideoid' => $playervideoid,
                'userid' => $userid,
                'inprogress' => 'inprogress',
                'pending' => 'pendingcorrection',
            ]
        );

        return $attempt !== false ? $attempt : null;
    }

    /**
     * Checks whether the student is allowed to start a new attempt.
     *
     * @param int $playervideoid The activity instance id.
     * @param int $userid The student id.
     * @param int $maxattempts Maximum attempts allowed (0 means unlimited).
     * @return bool
     */
    public static function can_start_new_attempt(int $playervideoid, int $userid, int $maxattempts): bool {
        global $DB;

        if ($maxattempts <= 0) {
            return true;
        }

        $attemptcount = $DB->count_records('playervideo_attempts', [
            'playervideoid' => $playervideoid,
            'userid' => $userid,
        ]);

        return $attemptcount < $maxattempts;
    }

    /**
     * Starts a new attempt for the student.
     *
     * The caller is responsible for checking {@see can_start_new_attempt()} and for charging
     * any PlayerHUD retry cost before calling this.
     *
     * @param int $playervideoid The activity instance id.
     * @param int $userid The student id.
     * @return stdClass The newly created attempt record.
     */
    public static function start_attempt(int $playervideoid, int $userid): stdClass {
        global $DB;

        $open = self::get_open_attempt($playervideoid, $userid);
        // A pendingcorrection attempt has nothing left to resume (the video was already
        // watched through, only the grade is withheld) — it must never block a new one here.
        if ($open !== null && $open->status === 'inprogress') {
            throw new coding_exception('An attempt is already open for this student on this instance.');
        }

        $lastattemptnumber = $DB->get_field_select(
            'playervideo_attempts',
            'MAX(attemptnumber)',
            'playervideoid = :playervideoid AND userid = :userid',
            ['playervideoid' => $playervideoid, 'userid' => $userid]
        );

        $now = time();

        $attempt = new stdClass();
        $attempt->playervideoid = $playervideoid;
        $attempt->userid = $userid;
        $attempt->attemptnumber = ((int) $lastattemptnumber) + 1;
        $attempt->status = 'inprogress';
        $attempt->grade = null;
        $attempt->hudretrycharged = 0;
        $attempt->timestart = $now;
        $attempt->timefinish = null;
        $attempt->timecreated = $now;
        $attempt->timemodified = $now;

        $attempt->id = $DB->insert_record('playervideo_attempts', $attempt);

        return $attempt;
    }

    /**
     * Checks whether the attempt still has an open-question response awaiting correction.
     *
     * @param int $attemptid The attempt id.
     * @return bool
     */
    public static function has_pending_correction(int $attemptid): bool {
        global $DB;

        return $DB->record_exists_select(
            'playervideo_responses',
            'attemptid = :attemptid AND status IN (:pendingai, :pendingreview)',
            ['attemptid' => $attemptid, 'pendingai' => 'pending_ai', 'pendingreview' => 'pending_review']
        );
    }

    /**
     * Computes the weighted grade of one attempt, scaled to the instance's maximum grade.
     *
     * `(points earned / sum of all question weights) x grade` — note/notice interactions never
     * count, and the weights used are whatever is configured on the timeline right now (a
     * finished attempt is never retroactively recalculated if the teacher edits weights later;
     * this method is only meant to be called once, at the moment the attempt finishes).
     *
     * @param int $attemptid The attempt id.
     * @param float $maxgrade The instance's maximum grade (playervideo.grade).
     * @return float|null The computed grade, or null if the instance has no question interactions.
     */
    public static function calculate_attempt_grade(int $attemptid, float $maxgrade): ?float {
        global $DB;

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        $interactions = $DB->get_records(
            'playervideo_interactions',
            ['playervideoid' => $attempt->playervideoid, 'type' => 'question'],
            '',
            'id, weight'
        );

        if (empty($interactions)) {
            return null;
        }

        $totalweight = 0.0;
        foreach ($interactions as $interaction) {
            $totalweight += (float) $interaction->weight;
        }

        if ($totalweight <= 0.0) {
            return null;
        }

        $responses = $DB->get_records('playervideo_responses', ['attemptid' => $attemptid]);
        $responsesbyinteraction = [];
        foreach ($responses as $response) {
            $responsesbyinteraction[$response->interactionid] = $response;
        }

        $pointsearned = 0.0;
        foreach ($interactions as $interaction) {
            $response = $responsesbyinteraction[$interaction->id] ?? null;
            if ($response === null) {
                continue;
            }

            if ($response->teachergrade !== null) {
                $pointsearned += (float) $response->teachergrade;
            } else if ((int) $response->iscorrect === 1) {
                $pointsearned += (float) $interaction->weight;
            }
        }

        return ($pointsearned / $totalweight) * $maxgrade;
    }

    /**
     * Finishes an attempt: computes and stores its grade, or marks it pending correction.
     *
     * @param int $attemptid The attempt id.
     * @param float $maxgrade The instance's maximum grade (playervideo.grade).
     * @return stdClass The updated attempt record.
     */
    public static function finish_attempt(int $attemptid, float $maxgrade): stdClass {
        global $DB;

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        $attempt->timefinish = time();
        $attempt->timemodified = time();

        if (self::has_pending_correction($attemptid)) {
            $attempt->status = 'pendingcorrection';
            $attempt->grade = null;
        } else {
            $attempt->status = 'finished';
            $attempt->grade = self::calculate_attempt_grade($attemptid, $maxgrade);
        }

        $DB->update_record('playervideo_attempts', $attempt);

        return $attempt;
    }

    /**
     * Recomputes an attempt's status/grade after a pending open-question correction is resolved.
     *
     * Deliberately separate from {@see finish_attempt()} rather than calling it a second time:
     * finish_attempt() unconditionally stamps `timefinish` to "now", which is correct the first
     * time (the student really did just finish) but would be wrong here — it would overwrite the
     * attempt's real finish time with whatever moment the teacher happened to grade it, days
     * later. This method only ever touches `status`/`grade`/`timemodified`.
     *
     * @param int $attemptid The attempt id.
     * @param float $maxgrade The instance's maximum grade (playervideo.grade).
     * @return stdClass The updated attempt record.
     */
    public static function recalculate_after_review(int $attemptid, float $maxgrade): stdClass {
        global $DB;

        $attempt = $DB->get_record('playervideo_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        if ($attempt->status !== 'pendingcorrection' || self::has_pending_correction($attemptid)) {
            // Either nothing was actually awaiting this (already finished), or another response
            // is still pending — the attempt stays pendingcorrection either way.
            return $attempt;
        }

        $attempt->status = 'finished';
        $attempt->grade = self::calculate_attempt_grade($attemptid, $maxgrade);
        $attempt->timemodified = time();

        $DB->update_record('playervideo_attempts', $attempt);

        return $attempt;
    }

    /**
     * Aggregates the student's final activity grade across all their finished attempts.
     *
     * @param int $playervideoid The activity instance id.
     * @param int $userid The student id.
     * @param int $grademethod One of the GRADE_* constants.
     * @return float|null The aggregated grade, or null if the student has no finished attempts.
     */
    public static function aggregate_final_grade(int $playervideoid, int $userid, int $grademethod): ?float {
        global $DB;

        $attempts = $DB->get_records(
            'playervideo_attempts',
            ['playervideoid' => $playervideoid, 'userid' => $userid, 'status' => 'finished'],
            'attemptnumber ASC',
            'id, attemptnumber, grade'
        );

        if (empty($attempts)) {
            return null;
        }

        $grades = array_map(static fn(stdClass $attempt): float => (float) $attempt->grade, array_values($attempts));

        return self::apply_grademethod($grades, $grademethod);
    }

    /**
     * Bulk counterpart of {@see aggregate_final_grade()} for a report listing every student at
     * once — a single query for every student's finished attempts, instead of one query per
     * student in a loop (see the project's own rule against `$DB` calls inside loops).
     *
     * @param int $playervideoid The activity instance id.
     * @param int[] $userids Student ids to aggregate for.
     * @param int $grademethod One of the GRADE_* constants.
     * @return array Student id (int) => aggregated grade (float), or null if no finished attempt.
     */
    public static function aggregate_final_grades_bulk(int $playervideoid, array $userids, int $grademethod): array {
        global $DB;

        $result = array_fill_keys($userids, null);
        if (empty($userids)) {
            return $result;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'playervideo_attempts',
            "playervideoid = :playervideoid AND status = :finished AND userid $insql",
            array_merge(['playervideoid' => $playervideoid, 'finished' => 'finished'], $inparams),
            'userid ASC, attemptnumber ASC',
            'id, userid, attemptnumber, grade'
        );

        $gradesbyuser = [];
        foreach ($records as $record) {
            $gradesbyuser[(int) $record->userid][] = (float) $record->grade;
        }

        foreach ($gradesbyuser as $userid => $grades) {
            $result[$userid] = self::apply_grademethod($grades, $grademethod);
        }

        return $result;
    }

    /**
     * Applies one of the GRADE_* aggregation methods to an already-fetched list of grades.
     *
     * @param float[] $grades Grades, in attempt order (oldest first); never empty.
     * @param int $grademethod One of the GRADE_* constants.
     * @return float
     */
    private static function apply_grademethod(array $grades, int $grademethod): float {
        switch ($grademethod) {
            case self::GRADE_AVERAGE:
                return array_sum($grades) / count($grades);
            case self::GRADE_FIRST:
                return reset($grades);
            case self::GRADE_LAST:
                return end($grades);
            case self::GRADE_HIGHEST:
            default:
                return max($grades);
        }
    }
}
