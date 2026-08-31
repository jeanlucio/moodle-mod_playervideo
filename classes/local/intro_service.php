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
 * Service tracking whether a user has already seen the automatic onboarding modal.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tracks the site-wide "seen intro" user preference.
 *
 * The onboarding modal teaches a general mechanic (how the video pauses for interactions),
 * not something tied to a specific course or activity instance, so the preference is
 * intentionally site-wide rather than per-instance — a student who already learned the
 * mechanic in one course should not be shown it again in another. Mirrors the exact pattern
 * already in production at mod_playerwords\local\intro_service.
 */
class intro_service {
    /**
     * Name of the user preference flagging that the onboarding modal has already been shown
     * automatically once.
     */
    private const PREFERENCE_NAME = 'mod_playervideo_seenintro';

    /**
     * Whether the given user has already seen the automatic introduction.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function has_seen_intro(int $userid): bool {
        return (bool) get_user_preferences(self::PREFERENCE_NAME, false, $userid);
    }

    /**
     * Marks the automatic introduction as seen for the given user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function mark_intro_seen(int $userid): void {
        set_user_preference(self::PREFERENCE_NAME, 1, $userid);
    }

    /**
     * Name of the underlying user preference, exposed for the privacy provider and for
     * db/uninstall.php cleanup.
     *
     * @return string
     */
    public static function get_preference_name(): string {
        return self::PREFERENCE_NAME;
    }
}
