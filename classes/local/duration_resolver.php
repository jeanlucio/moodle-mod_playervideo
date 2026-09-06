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
 * Reconciles the client-reported video duration into a trustworthy instance-level value.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use stdClass;

/**
 * Turns the untrusted `duration` heartbeat parameter into an authoritative instance value.
 *
 * `playervideo.duration` is shared content, not per-student progress: it divides every
 * student's watchedpct and bounds the engagement report whenever the teacher has set no
 * explicit trim. It is written only from save_progress, whose `duration` parameter comes
 * straight from the player running in the student's browser.
 *
 * The previous rule ("store it once, then only ever let it grow") let a single hostile
 * heartbeat (`duration = 9e9`) become a permanent, unrepairable divisor — there is no
 * duration field on mod_form for a teacher to correct it. This class replaces that rule with:
 *
 * - a hard sanity ceiling (24h) — an out-of-range report is ignored outright;
 * - the first credible report establishes the value;
 * - once established, a report may nudge it upward only by a small margin, and only while the
 *   video is still largely unwatched (a real duration is fixed; a big jump is a hostile
 *   ratchet or a metadata glitch, and once any student has watched most of the video the
 *   value is treated as confirmed and frozen against further growth);
 * - a lower report never shrinks it (glitch-safe), but the proven-watched floor may raise it;
 * - the value is cleared when the teacher swaps the video source (see playervideo_update_instance),
 *   and an explicit teacher trim still overrides it entirely for every consumer.
 *
 * The one residual case is an attacker being the very first client to hit a brand-new
 * activity, before any teacher preview or honest student: they can set a wrong value, but a
 * bounded one (<= 24h), correctable through the trim editor.
 */
class duration_resolver {
    /** @var float Hard upper bound for any accepted duration, in seconds (24 hours, DAYSECS). */
    private const MAX_PLAUSIBLE = 86400.0;

    /** @var float Largest upward correction allowed per heartbeat, as a fraction of the stored value. */
    private const GROWTH_FRACTION = 0.15;

    /** @var float Largest upward correction allowed per heartbeat, in seconds, for short clips. */
    private const GROWTH_MARGIN = 120.0;

    /** @var float Once this fraction of the stored duration has been watched, growth is frozen. */
    private const CONFIRM_FRACTION = 0.9;

    /**
     * Reconciles a reported duration against the evidence, persisting the result when it changes.
     *
     * @param stdClass $instance The playervideo instance; its `duration` property is updated in place.
     * @param float $reported The raw `duration` value from the heartbeat (0 when the player does not know it).
     * @param array $mergedsegments This heartbeat's already-merged [start, end] watched pairs.
     * @param \moodle_database $db Database handle.
     * @return float The authoritative duration now in force (0 when still unknown).
     */
    public static function reconcile(stdClass $instance, float $reported, array $mergedsegments, \moodle_database $db): float {
        $current = (float) ($instance->duration ?? 0);
        $evidence = min(self::MAX_PLAUSIBLE, segment_tracker::furthest_position($mergedsegments));
        $reportok = is_finite($reported) && $reported > 0 && $reported <= self::MAX_PLAUSIBLE;

        if (!$reportok) {
            $resolved = $current;
        } else if ($current <= 0) {
            $resolved = $reported;
        } else if ($reported <= $current) {
            $resolved = $current;
        } else {
            $margin = max($current * self::GROWTH_FRACTION, self::GROWTH_MARGIN);
            $withinmargin = ($reported - $current) <= $margin;
            $confirmed = $evidence >= $current * self::CONFIRM_FRACTION;
            $resolved = ($withinmargin && !$confirmed) ? $reported : $current;
        }

        // The proven-watched position is a lower bound the report cannot argue away — but it
        // only ever raises an existing value, it must not bootstrap a duration out of a stray
        // second of playback when no credible report has ever arrived.
        if ($resolved > 0) {
            $resolved = min(self::MAX_PLAUSIBLE, max($resolved, $evidence));
        }
        $resolved = round($resolved, 2);

        if (abs($resolved - $current) > 0.01) {
            $stored = $resolved > 0 ? $resolved : null;
            $db->set_field('playervideo', 'duration', $stored, ['id' => $instance->id]);
            $instance->duration = $stored;
        }

        return (float) ($instance->duration ?? 0);
    }
}
