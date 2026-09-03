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
 * Unit tests for intro_service.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for intro_service.
 *
 * @covers \mod_playervideo\local\intro_service
 */
final class intro_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that a user who has never seen the intro reports false.
     *
     * @return void
     */
    public function test_has_seen_intro_is_false_by_default(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(intro_service::has_seen_intro($user->id));
    }

    /**
     * Tests that marking the intro seen flips has_seen_intro to true for that user.
     *
     * @return void
     */
    public function test_mark_intro_seen_flips_has_seen_intro(): void {
        $user = $this->getDataGenerator()->create_user();

        intro_service::mark_intro_seen($user->id);

        $this->assertTrue(intro_service::has_seen_intro($user->id));
    }

    /**
     * Tests that the preference is site-wide, not per-course/instance: marking it seen for
     * one user never affects another — the isolation this preference actually needs, since
     * it is keyed only by userid, never by any course/instance id.
     *
     * @return void
     */
    public function test_mark_intro_seen_does_not_affect_other_users(): void {
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        intro_service::mark_intro_seen($usera->id);

        $this->assertTrue(intro_service::has_seen_intro($usera->id));
        $this->assertFalse(intro_service::has_seen_intro($userb->id));
    }

    /**
     * Tests that get_preference_name returns the exact string the privacy provider needs to
     * stay in sync with this class's own private constant.
     *
     * @return void
     */
    public function test_get_preference_name_matches_the_real_preference(): void {
        $user = $this->getDataGenerator()->create_user();
        intro_service::mark_intro_seen($user->id);

        $stored = get_user_preferences(intro_service::get_preference_name(), null, $user->id);

        $this->assertEquals(1, $stored);
    }
}
