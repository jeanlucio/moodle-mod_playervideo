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
 * External function tests for generate_di_summary.
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
 * Tests for the mod_playervideo_generate_di_summary web service.
 *
 * A real generation call is never exercised here — the test environment has no AI source
 * configured, mirroring the same choice already made for generate_question_ai_test.
 *
 * @covers \mod_playervideo\external\generate_di_summary
 */
final class generate_di_summary_test extends \advanced_testcase {
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
        return external_api::call_external_function('mod_playervideo_generate_di_summary', array_merge([
            'lang' => 'en',
        ], $args));
    }

    /**
     * Tests that generating a summary for a language with no caption fails clearly, before ever
     * reaching the AI-source check.
     *
     * @return void
     */
    public function test_fails_clearly_with_no_caption_for_language(): void {
        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_nocaptionforlanguage', $result['exception']->errorcode);
    }

    /**
     * Tests that, with a caption present but no AI source configured, the call fails with a
     * clear "no AI source" error.
     *
     * @return void
     */
    public function test_fails_clearly_with_no_ai_source_configured(): void {
        caption_service::save_caption($this->instance->id, 'en', '0:05 Plants use sunlight to make energy.');

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_noaisource', $result['exception']->errorcode);
    }

    /**
     * Tests that a student cannot generate a DI summary — must fail on the capability check.
     *
     * @return void
     */
    public function test_student_cannot_generate_a_summary(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }

    /**
     * Tests that an invalid language code is rejected.
     *
     * @return void
     */
    public function test_invalid_language_code_is_rejected(): void {
        $result = $this->call(['playervideoid' => $this->instance->id, 'lang' => 'pt br!']);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidlang', $result['exception']->errorcode);
    }
}
