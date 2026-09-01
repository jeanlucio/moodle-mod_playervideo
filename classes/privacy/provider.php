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
 * Privacy API provider for mod_playervideo.
 *
 * Personal data stored:
 *   - playervideo_progress: per-student playback progress, for resume and anti-skip (userid).
 *   - playervideo_attempts: one record per attempt a student makes at the activity (userid).
 *   - playervideo_responses: one record per response to a timeline interaction (userid),
 *     including free-text answers and AI/teacher feedback.
 *
 * playervideo_interactions and playervideo_poll_options are teacher-authored catalog content
 * (the question/note/poll definitions themselves), never keyed by a responding student, so
 * they carry no personal data of their own and are not declared here.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_playervideo\local\intro_service;

/**
 * Privacy provider implementation.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'playervideo_progress',
            [
                'userid' => 'privacy:metadata:playervideo_progress:userid',
                'playervideoid' => 'privacy:metadata:playervideo_progress:playervideoid',
                'lastposition' => 'privacy:metadata:playervideo_progress:lastposition',
                'watchedpct' => 'privacy:metadata:playervideo_progress:watchedpct',
                'watchedtoend' => 'privacy:metadata:playervideo_progress:watchedtoend',
                'segments' => 'privacy:metadata:playervideo_progress:segments',
                'timecreated' => 'privacy:metadata:playervideo_progress:timecreated',
                'timemodified' => 'privacy:metadata:playervideo_progress:timemodified',
            ],
            'privacy:metadata:playervideo_progress'
        );

        $collection->add_database_table(
            'playervideo_attempts',
            [
                'userid' => 'privacy:metadata:playervideo_attempts:userid',
                'playervideoid' => 'privacy:metadata:playervideo_attempts:playervideoid',
                'attemptnumber' => 'privacy:metadata:playervideo_attempts:attemptnumber',
                'status' => 'privacy:metadata:playervideo_attempts:status',
                'grade' => 'privacy:metadata:playervideo_attempts:grade',
                'hudretrycharged' => 'privacy:metadata:playervideo_attempts:hudretrycharged',
                'timestart' => 'privacy:metadata:playervideo_attempts:timestart',
                'timefinish' => 'privacy:metadata:playervideo_attempts:timefinish',
                'timecreated' => 'privacy:metadata:playervideo_attempts:timecreated',
                'timemodified' => 'privacy:metadata:playervideo_attempts:timemodified',
            ],
            'privacy:metadata:playervideo_attempts'
        );

        $collection->add_database_table(
            'playervideo_responses',
            [
                'userid' => 'privacy:metadata:playervideo_responses:userid',
                'playervideoid' => 'privacy:metadata:playervideo_responses:playervideoid',
                'attemptid' => 'privacy:metadata:playervideo_responses:attemptid',
                'interactionid' => 'privacy:metadata:playervideo_responses:interactionid',
                'questionid' => 'privacy:metadata:playervideo_responses:questionid',
                'answerid' => 'privacy:metadata:playervideo_responses:answerid',
                'polloptionid' => 'privacy:metadata:playervideo_responses:polloptionid',
                'responsetext' => 'privacy:metadata:playervideo_responses:responsetext',
                'iscorrect' => 'privacy:metadata:playervideo_responses:iscorrect',
                'hudrewarded' => 'privacy:metadata:playervideo_responses:hudrewarded',
                'aigrade' => 'privacy:metadata:playervideo_responses:aigrade',
                'aifeedback' => 'privacy:metadata:playervideo_responses:aifeedback',
                'teachergrade' => 'privacy:metadata:playervideo_responses:teachergrade',
                'teacherfeedback' => 'privacy:metadata:playervideo_responses:teacherfeedback',
                'status' => 'privacy:metadata:playervideo_responses:status',
                'timecreated' => 'privacy:metadata:playervideo_responses:timecreated',
                'timemodified' => 'privacy:metadata:playervideo_responses:timemodified',
            ],
            'privacy:metadata:playervideo_responses'
        );

        $collection->add_user_preference(
            intro_service::get_preference_name(),
            'privacy:metadata:preference:seenintro'
        );

        return $collection;
    }

    #[\Override]
    public static function export_user_preferences(int $userid): void {
        if (!intro_service::has_seen_intro($userid)) {
            return;
        }

        writer::export_user_preference(
            'mod_playervideo',
            intro_service::get_preference_name(),
            transform::yesno(true),
            get_string('privacy:metadata:preference:seenintro', 'mod_playervideo')
        );
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                       AND ctx.contextlevel = :ctxlevel1
                  JOIN {modules} m1 ON m1.id = cm.module AND m1.name = :modname1
                  JOIN {playervideo} pv1 ON pv1.id = cm.instance
                  JOIN {playervideo_progress} pp ON pp.playervideoid = pv1.id
                 WHERE pp.userid = :userid1";
        $contextlist->add_from_sql(
            $sql,
            ['ctxlevel1' => CONTEXT_MODULE, 'modname1' => 'playervideo', 'userid1' => $userid]
        );

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                       AND ctx.contextlevel = :ctxlevel2
                  JOIN {modules} m2 ON m2.id = cm.module AND m2.name = :modname2
                  JOIN {playervideo} pv2 ON pv2.id = cm.instance
                  JOIN {playervideo_attempts} pa ON pa.playervideoid = pv2.id
                 WHERE pa.userid = :userid2";
        $contextlist->add_from_sql(
            $sql,
            ['ctxlevel2' => CONTEXT_MODULE, 'modname2' => 'playervideo', 'userid2' => $userid]
        );

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                       AND ctx.contextlevel = :ctxlevel3
                  JOIN {modules} m3 ON m3.id = cm.module AND m3.name = :modname3
                  JOIN {playervideo} pv3 ON pv3.id = cm.instance
                  JOIN {playervideo_responses} pr ON pr.playervideoid = pv3.id
                 WHERE pr.userid = :userid3";
        $contextlist->add_from_sql(
            $sql,
            ['ctxlevel3' => CONTEXT_MODULE, 'modname3' => 'playervideo', 'userid3' => $userid]
        );

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('playervideo', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $params = ['pid' => (int) $cm->instance];

        $userlist->add_from_sql('userid', 'SELECT userid FROM {playervideo_progress} WHERE playervideoid = :pid', $params);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {playervideo_attempts} WHERE playervideoid = :pid', $params);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {playervideo_responses} WHERE playervideoid = :pid', $params);
    }

    /**
     * Bulk-resolves the playervideo instance id for every context_module context in the
     * list in a single query, instead of calling get_coursemodule_from_id() once per
     * context — shared by export_user_data() and delete_data_for_user(), the two places
     * that need to walk every context in an approved_contextlist.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return array<int,int> Course module id => playervideo instance id.
     */
    private static function get_instance_ids_by_cmid(approved_contextlist $contextlist): array {
        global $DB;

        $cmids = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module) {
                $cmids[] = $context->instanceid;
            }
        }
        if (empty($cmids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT cm.id, cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.id $insql",
            array_merge(['modname' => 'playervideo'], $inparams)
        );

        $map = [];
        foreach ($records as $record) {
            $map[(int) $record->id] = (int) $record->instance;
        }
        return $map;
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $instanceidsbycmid = self::get_instance_ids_by_cmid($contextlist);
        $instanceids = array_values($instanceidsbycmid);
        if (empty($instanceids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED);
        $userparams = array_merge(['userid' => $userid], $inparams);

        $progressbyinstanceid = [];
        $progressrecords = $DB->get_records_select(
            'playervideo_progress',
            "userid = :userid AND playervideoid $insql",
            $userparams
        );
        foreach ($progressrecords as $record) {
            $progressbyinstanceid[(int) $record->playervideoid] = $record;
        }

        $attemptsbyinstanceid = [];
        $attemptrecords = $DB->get_records_select(
            'playervideo_attempts',
            "userid = :userid AND playervideoid $insql",
            $userparams,
            'timecreated ASC'
        );
        foreach ($attemptrecords as $record) {
            $attemptsbyinstanceid[(int) $record->playervideoid][] = $record;
        }

        $responsesbyinstanceid = [];
        $responserecords = $DB->get_records_select(
            'playervideo_responses',
            "userid = :userid AND playervideoid $insql",
            $userparams,
            'timecreated ASC'
        );
        foreach ($responserecords as $record) {
            $responsesbyinstanceid[(int) $record->playervideoid][] = $record;
        }

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $instanceid = $instanceidsbycmid[$context->instanceid] ?? null;
            if ($instanceid === null) {
                continue;
            }

            self::export_progress_for_context($context, $progressbyinstanceid[$instanceid] ?? null);
            self::export_attempts_for_context($context, $attemptsbyinstanceid[$instanceid] ?? []);
            self::export_responses_for_context($context, $responsesbyinstanceid[$instanceid] ?? []);
        }
    }

    /**
     * Exports one context's playback progress row, if any.
     *
     * @param \context $context Context to export into.
     * @param \stdClass|null $progress The user's progress row for this instance, if any.
     */
    private static function export_progress_for_context(\context $context, ?\stdClass $progress): void {
        if ($progress === null) {
            return;
        }

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'mod_playervideo'), get_string('privacy:progress', 'mod_playervideo')],
            (object) [
                'lastposition' => $progress->lastposition,
                'watchedpct' => $progress->watchedpct,
                'watchedtoend' => transform::yesno($progress->watchedtoend),
                'timemodified' => transform::datetime($progress->timemodified),
            ]
        );
    }

    /**
     * Exports one context's attempt rows, if any.
     *
     * @param \context $context Context to export into.
     * @param \stdClass[] $attempts The user's attempt rows for this instance.
     */
    private static function export_attempts_for_context(\context $context, array $attempts): void {
        if (empty($attempts)) {
            return;
        }

        $rows = array_values(array_map(static function (\stdClass $attempt): array {
            return [
                'attemptnumber' => (int) $attempt->attemptnumber,
                'status' => $attempt->status,
                'grade' => $attempt->grade !== null ? (float) $attempt->grade : null,
                'timestart' => transform::datetime($attempt->timestart),
                'timefinish' => $attempt->timefinish ? transform::datetime($attempt->timefinish) : null,
            ];
        }, $attempts));

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'mod_playervideo'), get_string('privacy:attempts', 'mod_playervideo')],
            (object) ['attempts' => $rows]
        );
    }

    /**
     * Exports one context's response rows, if any.
     *
     * @param \context $context Context to export into.
     * @param \stdClass[] $responses The user's response rows for this instance.
     */
    private static function export_responses_for_context(\context $context, array $responses): void {
        if (empty($responses)) {
            return;
        }

        $rows = array_values(array_map(static function (\stdClass $response): array {
            return [
                'interactionid' => (int) $response->interactionid,
                'responsetext' => $response->responsetext,
                'iscorrect' => $response->iscorrect !== null ? transform::yesno($response->iscorrect) : null,
                'teacherfeedback' => $response->teacherfeedback,
                'status' => $response->status,
                'timecreated' => transform::datetime($response->timecreated),
            ];
        }, $responses));

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'mod_playervideo'), get_string('privacy:responses', 'mod_playervideo')],
            (object) ['responses' => $rows]
        );
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('playervideo', $context->instanceid);
        if (!$cm) {
            return;
        }

        $instanceid = (int) $cm->instance;
        $DB->delete_records('playervideo_responses', ['playervideoid' => $instanceid]);
        $DB->delete_records('playervideo_attempts', ['playervideoid' => $instanceid]);
        $DB->delete_records('playervideo_progress', ['playervideoid' => $instanceid]);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $instanceidsbycmid = self::get_instance_ids_by_cmid($contextlist);

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $instanceid = $instanceidsbycmid[$context->instanceid] ?? null;
            if ($instanceid === null) {
                continue;
            }

            $params = ['userid' => $userid, 'playervideoid' => $instanceid];
            $DB->delete_records('playervideo_responses', $params);
            $DB->delete_records('playervideo_attempts', $params);
            $DB->delete_records('playervideo_progress', $params);
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('playervideo', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        $instanceid = (int) $cm->instance;
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($inparams, ['pid' => $instanceid]);

        $DB->delete_records_select('playervideo_responses', "userid $insql AND playervideoid = :pid", $params);
        $DB->delete_records_select('playervideo_attempts', "userid $insql AND playervideoid = :pid", $params);
        $DB->delete_records_select('playervideo_progress', "userid $insql AND playervideoid = :pid", $params);
    }
}
