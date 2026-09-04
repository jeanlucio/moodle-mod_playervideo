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
 * Serialises check-then-act sequences that must not run twice concurrently for the same key.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

use core\lock\lock;
use core\lock\lock_config;
use moodle_exception;

/**
 * Class attempt_lock
 *
 * submit_answer (already-answered/already-rewarded checks, PlayerHUD item grant) and
 * start_attempt (maxattempts check, attempt creation) each run a check-then-act sequence as
 * separate, unlocked steps. Two concurrent calls for the same resource can both pass the
 * checks before either writes, letting a student be rewarded twice for one correct answer or
 * exceed the configured attempt limit. Acquiring this lock before the checks and releasing it
 * after the write serialises the whole sequence per resource, so a contending caller re-runs
 * the same checks against the first caller's committed result instead of racing it.
 */
final class attempt_lock {
    /**
     * Seconds to wait for the lock before giving up. The critical section is a handful of
     * fast queries plus one write, so a contending caller is expected to succeed well within
     * this window once the current holder releases.
     */
    private const TIMEOUT = 5;

    /**
     * Acquires a lock for the given resource key, blocking up to {@see TIMEOUT} seconds.
     *
     * @param string $resourcekey Unique key for the resource being serialised
     *      (e.g. "answer_{attemptid}_{interactionid}" or "start_{playervideoid}_{userid}").
     * @return lock The held lock; the caller must release() it, typically in a finally block.
     * @throws moodle_exception If the lock could not be acquired within the timeout.
     */
    public static function acquire(string $resourcekey): lock {
        $factory = lock_config::get_lock_factory('mod_playervideo');
        $lock = $factory->get_lock($resourcekey, self::TIMEOUT);

        if (!$lock) {
            throw new moodle_exception('error_attemptlockbusy', 'mod_playervideo');
        }

        return $lock;
    }
}
