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
 * Unit tests for the DI easy-read summary service.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for di_summary_service.
 *
 * @covers \mod_playervideo\local\di_summary_service
 */
final class di_summary_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that a freshly generated summary always starts pending, and that saving a new
     * generation over an existing approved one resets it back to pending — a fresh AI
     * generation always needs a fresh teacher review.
     *
     * @return void
     */
    public function test_save_generated_always_starts_pending(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);

        di_summary_service::save_generated($instance->id, 'en', 'First draft.');
        $summary = di_summary_service::get_summary($instance->id, 'en');
        $this->assertSame(di_summary_service::STATUS_PENDING, $summary->status);

        di_summary_service::save_reviewed($instance->id, 'en', 'First draft.', true);
        $summary = di_summary_service::get_summary($instance->id, 'en');
        $this->assertSame(di_summary_service::STATUS_APPROVED, $summary->status);

        di_summary_service::save_generated($instance->id, 'en', 'Regenerated draft.');
        $summary = di_summary_service::get_summary($instance->id, 'en');
        $this->assertSame(di_summary_service::STATUS_PENDING, $summary->status);
        $this->assertSame('Regenerated draft.', $summary->content);
    }

    /**
     * Tests that save_reviewed() sets status by the approved flag, in both directions.
     *
     * @return void
     */
    public function test_save_reviewed_toggles_status_by_approved_flag(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);

        di_summary_service::save_reviewed($instance->id, 'en', 'Text.', true);
        $this->assertSame(di_summary_service::STATUS_APPROVED, di_summary_service::get_summary($instance->id, 'en')->status);

        di_summary_service::save_reviewed($instance->id, 'en', 'Edited text.', false);
        $summary = di_summary_service::get_summary($instance->id, 'en');
        $this->assertSame(di_summary_service::STATUS_PENDING, $summary->status);
        $this->assertSame('Edited text.', $summary->content);
    }

    /**
     * Tests the full get/delete cycle, and that get_summaries() lists every language regardless
     * of status.
     *
     * @return void
     */
    public function test_get_summaries_and_delete_cycle(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);

        di_summary_service::save_generated($instance->id, 'en', 'English.');
        di_summary_service::save_reviewed($instance->id, 'pt-br', 'Português.', true);

        $summaries = di_summary_service::get_summaries($instance->id);
        $this->assertCount(2, $summaries);

        $this->assertTrue(di_summary_service::delete_summary($instance->id, 'en'));
        $this->assertCount(1, di_summary_service::get_summaries($instance->id));
        $this->assertFalse(di_summary_service::delete_summary($instance->id, 'en'));
    }
}
