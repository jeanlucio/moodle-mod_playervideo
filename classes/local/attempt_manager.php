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
 * Grading model (see the plugin SCOPE, "Cálculo da nota da tentativa"): each attempt's own
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
