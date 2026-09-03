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
 * Tests for the mod_playervideo/view student-page template.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo;

use advanced_testcase;

/**
 * Tests for the mod_playervideo/view student-page template.
 *
 * @coversNothing
 */
final class view_render_test extends advanced_testcase {
    /**
     * The template must not render the activity description itself. The incourse page layout
     * already prints it once via $PAGE->activityheader; a second render here made every drop
     * shortcode (or any other filtered content) in the description appear twice on the page.
     */
    public function test_view_template_does_not_render_the_activity_description(): void {
        global $OUTPUT, $PAGE;

        $this->resetAfterTest();
        $PAGE->set_url('/mod/playervideo/view.php');

        $html = $OUTPUT->render_from_template('mod_playervideo/view', [
            // A stray "intro" key must be ignored even if a future edit to view.php passes one.
            'intro' => '<p>DESCRIPTION-MARKER-SHOULD-NOT-APPEAR</p>',
            'introbody' => '<p>ONBOARDING-MARKER</p>',
            'canattempt' => true,
            'canstart' => true,
            'startbuttonlabel' => 'Start',
            'pendingcorrectionnotice' => '',
            'previousattempts' => [],
        ]);

        $this->assertStringNotContainsString('DESCRIPTION-MARKER-SHOULD-NOT-APPEAR', $html);
        $this->assertStringNotContainsString('playervideo-intro', $html);
        // The onboarding body is a separate, static string and must still be present (hidden).
        $this->assertStringContainsString('ONBOARDING-MARKER', $html);
        $this->assertStringContainsString('playervideo-onboarding-content', $html);
    }
}
