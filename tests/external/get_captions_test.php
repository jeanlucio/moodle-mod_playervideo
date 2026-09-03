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
 * External function tests for get_captions.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;
use mod_playervideo\local\caption_service;

/**
 * Tests for the mod_playervideo_get_captions web service.
 *
 * @covers \mod_playervideo\external\get_captions
 */
final class get_captions_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Instance used by every test. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();

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
        return external_api::call_external_function('mod_playervideo_get_captions', $args);
    }

    /**
     * Tests that a student (view-only) can list captions — reading them is not restricted to
     * teachers, unlike saving one.
     *
     * @return void
     */
    public function test_student_can_list_captions(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        caption_service::save_caption($this->instance->id, 'en', '0:05 Hello.');

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $this->assertCount(1, $result['data']['captions']);
        $this->assertSame('en', $result['data']['captions'][0]['lang']);
        $this->assertSame('manual', $result['data']['captions'][0]['source']);
        $this->assertStringStartsWith('WEBVTT', $result['data']['captions'][0]['content']);
    }

    /**
     * Tests that an instance with no captions returns an empty list, not an error.
     *
     * @return void
     */
    public function test_returns_an_empty_list_when_no_captions_exist(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $this->assertSame([], $result['data']['captions']);
    }

    /**
     * Tests that a user with no access to the course at all is rejected.
     *
     * @return void
     */
    public function test_user_without_access_cannot_list_captions(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
    }
}
