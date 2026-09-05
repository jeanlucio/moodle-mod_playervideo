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
 * External function to build the analytics report of a PlayerVideo instance.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playervideo\local\attempt_manager;
use mod_playervideo\local\engagement_aggregator;
use mod_playervideo\local\group_access;
use mod_playervideo\local\question_service;

/**
 * Three aggregate views, all built from batch queries (one per aggregate, never one per row in a
 * loop — see the project's own rule against `$DB` calls inside loops): per-question (% correct
 * for multichoice, correction status counts for open questions), per-student (attempts taken,
 * final grade, completion, time watched), and a class-wide engagement timeline.
 */
class get_report extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
        ]);
    }

    /**
     * Builds the report.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @return array The per-question and per-student aggregates.
     */
    public static function execute(int $playervideoid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['playervideoid' => $playervideoid]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playervideo:viewreports', $context);

        $students = self::eligible_students($cm, $context);
        $userids = array_map(static fn($user): int => (int) $user->id, array_values($students));

        return [
            'byquestion' => self::build_question_stats($params['playervideoid'], $context),
            'bystudent' => self::build_student_stats($params['playervideoid'], $cm, $students, $userids),
            'engagement' => self::build_engagement($params['playervideoid'], $userids),
        ];
    }

    /**
     * Resolves the students eligible to appear in this report: enrolled with the attempt
     * capability, then narrowed by the current group restriction, if any.
     *
     * @param \stdClass $cm The course module record.
     * @param \context $context The activity's context.
     * @return array Student records (id, name fields), keyed by userid.
     */
    private static function eligible_students(\stdClass $cm, \context $context): array {
        $students = get_enrolled_users(
            $context,
            'mod/playervideo:attempt',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename'
        );

        $restricteduserids = group_access::restricted_userids($cm, $context);
        if ($restricteduserids !== null) {
            $students = array_filter(
                $students,
                static fn($user): bool => in_array((int) $user->id, $restricteduserids, true)
            );
        }

        return $students;
    }

    /**
     * Builds the per-question aggregate: % correct for multichoice/truefalse, correction status
     * counts for open questions.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param \context $context The activity's context, for formatting question text.
     * @return array Per-question rows, in timestamp order.
     */
    private static function build_question_stats(int $playervideoid, \context $context): array {
        global $DB;

        $interactions = $DB->get_records(
            'playervideo_interactions',
            ['playervideoid' => $playervideoid, 'type' => 'question'],
            'timestamp ASC'
        );
        if (empty($interactions)) {
            return [];
        }

        $questionids = array_map(static fn($record): int => (int) $record->questionid, $interactions);
        $questiontexts = question_service::get_question_texts($questionids, $context);
        $qtypes = $DB->get_records_list('question', 'id', $questionids, '', 'id, qtype');

        $interactionids = array_map(static fn($record): int => (int) $record->id, array_values($interactions));
        [$insql, $inparams] = $DB->get_in_or_equal($interactionids, SQL_PARAMS_NAMED, 'ids');

        // A recordset, not get_records_sql(), because the grouping key (interactionid + status)
        // is not the single first column — get_records_sql() would key the array by interactionid
        // alone and silently drop every status but the last one for a given interaction.
        $statusrows = $DB->get_recordset_sql(
            "SELECT interactionid, status, COUNT(id) AS total
               FROM {playervideo_responses}
              WHERE interactionid $insql
           GROUP BY interactionid, status",
            $inparams
        );
        $countsbyinteraction = [];
        foreach ($statusrows as $row) {
            $countsbyinteraction[(int) $row->interactionid][$row->status] = (int) $row->total;
        }
        $statusrows->close();

        $correctcounts = $DB->get_records_sql(
            "SELECT interactionid, COUNT(id) AS total
               FROM {playervideo_responses}
              WHERE interactionid $insql AND iscorrect = :iscorrect
           GROUP BY interactionid",
            array_merge($inparams, ['iscorrect' => 1])
        );
        $correctcountbyinteraction = [];
        foreach ($correctcounts as $row) {
            $correctcountbyinteraction[(int) $row->interactionid] = (int) $row->total;
        }

        $result = [];
        foreach ($interactions as $interaction) {
            $qtype = $qtypes[(int) $interaction->questionid]->qtype ?? '';
            $counts = $countsbyinteraction[(int) $interaction->id] ?? [];
            $totalresponses = array_sum($counts);

            $ismultichoice = $qtype === 'multichoice' || $qtype === 'truefalse';
            $correctcount = $ismultichoice ? ($correctcountbyinteraction[(int) $interaction->id] ?? 0) : 0;

            $result[] = [
                'interactionid' => (int) $interaction->id,
                'timestamp' => (float) $interaction->timestamp,
                'questiontext' => $questiontexts[(int) $interaction->questionid] ?? '',
                'qtype' => $qtype,
                'totalresponses' => $totalresponses,
                'correctcount' => $correctcount,
                'percentcorrect' => $ismultichoice && $totalresponses > 0
                    ? round(($correctcount / $totalresponses) * 100, 1) : 0.0,
                'pendingcount' => ($counts['pending_ai'] ?? 0) + ($counts['pending_review'] ?? 0),
                'gradedcount' => $counts['graded'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Builds the per-student aggregate: attempts taken, final grade, completion, time watched.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param \stdClass $cm The course module record.
     * @param array $students Eligible student records, from eligible_students().
     * @param array $userids Eligible student ids, from eligible_students().
     * @return array Per-student rows, ordered by name.
     */
    private static function build_student_stats(int $playervideoid, \stdClass $cm, array $students, array $userids): array {
        global $DB;

        if (empty($students)) {
            return [];
        }

        $instance = $DB->get_record('playervideo', ['id' => $playervideoid], '*', MUST_EXIST);

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $attemptcounts = $DB->get_records_sql(
            "SELECT userid, COUNT(id) AS total
               FROM {playervideo_attempts}
              WHERE playervideoid = :playervideoid AND userid $insql
           GROUP BY userid",
            array_merge(['playervideoid' => $playervideoid], $inparams)
        );
        $attemptcountbyuser = [];
        foreach ($attemptcounts as $row) {
            $attemptcountbyuser[(int) $row->userid] = (int) $row->total;
        }

        $finalgrades = attempt_manager::aggregate_final_grades_bulk($playervideoid, $userids, (int) $instance->grademethod);

        $progressrecords = $DB->get_records_sql(
            "SELECT userid, watchedpct
               FROM {playervideo_progress}
              WHERE playervideoid = :playervideoid AND userid $insql",
            array_merge(['playervideoid' => $playervideoid], $inparams)
        );
        $watchedpctbyuser = [];
        foreach ($progressrecords as $row) {
            $watchedpctbyuser[(int) $row->userid] = $row->watchedpct !== null ? (float) $row->watchedpct : 0.0;
        }

        $completionrecords = $DB->get_records_sql(
            "SELECT userid, completionstate
               FROM {course_modules_completion}
              WHERE coursemoduleid = :cmid AND userid $insql",
            array_merge(['cmid' => $cm->id], $inparams)
        );
        $completedbyuser = [];
        foreach ($completionrecords as $row) {
            $completedbyuser[(int) $row->userid] = in_array(
                (int) $row->completionstate,
                [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS],
                true
            );
        }

        $result = [];
        foreach ($students as $student) {
            $userid = (int) $student->id;
            $result[] = [
                'userid' => $userid,
                'fullname' => fullname($student),
                'attemptscount' => $attemptcountbyuser[$userid] ?? 0,
                'finalgrade' => $finalgrades[$userid] ?? null,
                'watchedpct' => $watchedpctbyuser[$userid] ?? 0.0,
                'completed' => $completedbyuser[$userid] ?? false,
            ];
        }

        usort($result, static fn(array $a, array $b): int => strcasecmp($a['fullname'], $b['fullname']));

        return $result;
    }

    /**
     * Builds the class-wide engagement timeline: how much of each region of the video was
     * watched across every eligible student, never broken down by individual student.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param array $userids Eligible student ids, from eligible_students().
     * @return array{
     *     buckets: float[],
     *     mostwatchedbucket: int|null,
     *     leastwatchedbucket: int|null,
     *     dropoffbucket: int|null
     * }
     */
    private static function build_engagement(int $playervideoid, array $userids): array {
        global $DB;

        $instance = $DB->get_record('playervideo', ['id' => $playervideoid], '*', MUST_EXIST);
        $windowstart = $instance->trimstart !== null ? (float) $instance->trimstart : 0.0;
        $windowend = $instance->trimend !== null ? (float) $instance->trimend : (float) $instance->duration;

        $segmentsbyuser = [];
        if ($userids !== []) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $progressrecords = $DB->get_records_sql(
                "SELECT userid, segments
                   FROM {playervideo_progress}
                  WHERE playervideoid = :playervideoid AND userid $insql",
                array_merge(['playervideoid' => $playervideoid], $inparams)
            );
            foreach ($progressrecords as $row) {
                $decoded = json_decode((string) $row->segments, true);
                $segmentsbyuser[(int) $row->userid] = is_array($decoded) ? $decoded : [];
            }
        }

        return engagement_aggregator::build($segmentsbyuser, $windowstart, $windowend);
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'byquestion' => new external_multiple_structure(
                new external_single_structure([
                    'interactionid' => new external_value(PARAM_INT, 'Interaction id'),
                    'timestamp' => new external_value(PARAM_FLOAT, 'Video second where the interaction fires'),
                    'questiontext' => new external_value(PARAM_RAW, 'Formatted question text'),
                    'qtype' => new external_value(PARAM_ALPHANUMEXT, 'Question type'),
                    'totalresponses' => new external_value(PARAM_INT, 'Number of responses recorded'),
                    'correctcount' => new external_value(PARAM_INT, 'Correct responses (multichoice/truefalse only)'),
                    'percentcorrect' => new external_value(PARAM_FLOAT, 'Percentage correct (multichoice/truefalse only)'),
                    'pendingcount' => new external_value(PARAM_INT, 'Open-question responses awaiting correction'),
                    'gradedcount' => new external_value(PARAM_INT, 'Open-question responses already graded'),
                ]),
                'Per-question aggregate, in timeline order'
            ),
            'bystudent' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'fullname' => new external_value(PARAM_RAW, 'Student full name'),
                    'attemptscount' => new external_value(PARAM_INT, 'Attempts taken so far'),
                    'finalgrade' => new external_value(
                        PARAM_FLOAT,
                        'Aggregated final grade, null if no finished attempt yet',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'watchedpct' => new external_value(PARAM_FLOAT, 'Percentage of the video actually watched'),
                    'completed' => new external_value(PARAM_BOOL, 'Whether the activity is marked complete'),
                ]),
                'Per-student aggregate, ordered by name'
            ),
            'engagement' => new external_single_structure([
                'buckets' => new external_multiple_structure(
                    new external_value(PARAM_FLOAT, 'Seconds watched by the class in this region'),
                    'Class-wide watched seconds per equal-width region of the playback window'
                ),
                'windowstart' => new external_value(PARAM_FLOAT, 'Playback window start, in seconds'),
                'bucketlength' => new external_value(PARAM_FLOAT, 'Width of one region, in seconds'),
                'mostwatchedbucket' => new external_value(
                    PARAM_INT,
                    'Index of the most-watched region',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
                'leastwatchedbucket' => new external_value(
                    PARAM_INT,
                    'Index of the least-watched region',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
                'dropoffbucket' => new external_value(
                    PARAM_INT,
                    'Index of the region with the largest drop in viewership from the one before it',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
            ]),
        ]);
    }
}
