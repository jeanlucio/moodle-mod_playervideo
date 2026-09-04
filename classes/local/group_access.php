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
 * Restricts student-facing report/correction data to the caller's own separate group.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use context;
use stdClass;

/**
 * Class group_access
 *
 * The activity supports FEATURE_GROUPS/FEATURE_GROUPINGS (see lib.php), but get_report,
 * get_pending_corrections and review_response never consulted the group mode at all — a
 * teacher with mod/playervideo:viewreports or :reviewresponses but without
 * moodle/site:accessallgroups saw and could grade every student's data, including students in
 * a separate group that "Separate groups" is meant to hide from them. Visible groups mode is
 * intentionally left unrestricted here (its whole point is that data stays visible across
 * groups; only actions are meant to be scoped), matching groups_get_activity_allowed_groups()'s
 * own semantics.
 */
final class group_access {
    /**
     * Resolves which student ids the current user may see/act on for this activity.
     *
     * @param stdClass $cm The course module record.
     * @param context $context The activity's context.
     * @return int[]|null Allowed user ids, or null when there is no restriction to apply
     *      (no groups, visible groups mode, or the caller has accessallgroups).
     */
    public static function restricted_userids(stdClass $cm, context $context): ?array {
        global $DB;

        if ((int) groups_get_activity_groupmode($cm) !== SEPARATEGROUPS) {
            return null;
        }
        if (has_capability('moodle/site:accessallgroups', $context)) {
            return null;
        }

        $allowedgroups = groups_get_activity_allowed_groups($cm);
        if (empty($allowedgroups)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($allowedgroups), SQL_PARAMS_NAMED);
        $userids = $DB->get_fieldset_select('groups_members', 'DISTINCT userid', "groupid $insql", $inparams);

        return array_map('intval', $userids);
    }

    /**
     * Checks whether the current user may see/act on the given student's data.
     *
     * @param stdClass $cm The course module record.
     * @param context $context The activity's context.
     * @param int $targetuserid The student whose data is being accessed.
     * @return bool
     */
    public static function can_access_user(stdClass $cm, context $context, int $targetuserid): bool {
        $restricted = self::restricted_userids($cm, $context);

        return $restricted === null || in_array($targetuserid, $restricted, true);
    }
}
