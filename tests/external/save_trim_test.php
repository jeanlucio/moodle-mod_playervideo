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
 * External function tests for save_trim.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_save_trim web service.
 *
 * @covers \mod_playervideo\external\save_trim
 */
final class save_trim_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_save_trim', $args);
    }

    /**
     * Tests that a valid trim window is persisted.
     *
     * @return void
     */
    public function test_saves_a_valid_trim_window(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id]);

        $result = $this->call(['playervideoid' => $instance->id, 'trimstart' => 10.5, 'trimend' => 120.0]);

        $this->assertFalse($result['error']);
        $this->assertSame(10.5, $result['data']['trimstart']);
        $this->assertSame(120.0, $result['data']['trimend']);
        $record = $DB->get_record('playervideo', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(10.5, (float) $record->trimstart);
        $this->assertSame(120.0, (float) $record->trimend);
    }

    /**
     * Tests that null values clear a previously set trim window.
     *
     * @return void
     */
    public function test_null_values_clear_the_trim_window(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance([
            'course' => $this->course->id,
            'trimstart' => 5,
            'trimend' => 90,
        ]);

        $result = $this->call(['playervideoid' => $instance->id, 'trimstart' => null, 'trimend' => null]);

        $this->assertFalse($result['error']);
        $record = $DB->get_record('playervideo', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertNull($record->trimstart);
        $this->assertNull($record->trimend);
    }

    /**
     * Tests that an end before (or equal to) the start is rejected.
     *
     * @return void
     */
    public function test_end_before_start_is_rejected(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id]);

        $result = $this->call(['playervideoid' => $instance->id, 'trimstart' => 100, 'trimend' => 50]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidtrim', $result['exception']->errorcode);
    }

    /**
     * Tests that a negative start is rejected.
     *
     * @return void
     */
    public function test_negative_start_is_rejected(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id]);

        $result = $this->call(['playervideoid' => $instance->id, 'trimstart' => -5, 'trimend' => null]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidtrim', $result['exception']->errorcode);
    }
}
