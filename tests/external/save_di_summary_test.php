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
 * External function tests for save_di_summary.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;
use mod_playervideo\local\di_summary_service;

/**
 * Tests for the mod_playervideo_save_di_summary web service.
 *
 * @covers \mod_playervideo\external\save_di_summary
 */
final class save_di_summary_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Instance used by every test. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_save_di_summary', array_merge([
            'playervideoid' => $this->instance->id,
            'lang' => 'en',
            'content' => 'Plants use light.',
            'approved' => false,
            'delete' => false,
        ], $args));
    }

    /**
     * Tests that a teacher can save a summary as pending, then approve it in a later call.
     *
     * @return void
     */
    public function test_teacher_can_save_and_then_approve(): void {
        $result = $this->call([]);
        $this->assertFalse($result['error']);
        $this->assertSame(di_summary_service::STATUS_PENDING, $result['data']['status']);

        $result = $this->call(['approved' => true]);
        $this->assertFalse($result['error']);
        $this->assertSame(di_summary_service::STATUS_APPROVED, $result['data']['status']);
    }

    /**
     * Tests that empty content is rejected.
     *
     * @return void
     */
    public function test_empty_content_is_rejected(): void {
        $result = $this->call(['content' => '  ']);

        $this->assertTrue($result['error']);
        $this->assertSame('error_disummarycontentrequired', $result['exception']->errorcode);
    }

    /**
     * Tests that a summary can be deleted, and that deleting a language with nothing saved
     * fails with a clear error.
     *
     * @return void
     */
    public function test_teacher_can_delete_a_summary(): void {
        $this->call([]);

        $result = $this->call(['delete' => true]);
        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['deleted']);

        $second = $this->call(['delete' => true]);
        $this->assertTrue($second['error']);
        $this->assertSame('error_disummarynotfound', $second['exception']->errorcode);
    }

    /**
     * Tests that a student cannot save a DI summary.
     *
     * @return void
     */
    public function test_student_cannot_save_a_summary(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call([]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }
}
