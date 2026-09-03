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
 * External function tests for save_caption.
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
 * Tests for the mod_playervideo_save_caption web service.
 *
 * @covers \mod_playervideo\external\save_caption
 */
final class save_caption_test extends \advanced_testcase {
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
        return external_api::call_external_function('mod_playervideo_save_caption', array_merge([
            'playervideoid' => $this->instance->id,
            'lang' => 'en',
            'content' => '0:05 Hello.',
            'delete' => false,
        ], $args));
    }

    /**
     * Tests that a teacher can create a caption, and that the stored content is real VTT.
     *
     * @return void
     */
    public function test_teacher_can_create_a_caption(): void {
        $result = $this->call([]);

        $this->assertFalse($result['error']);
        $this->assertSame('en', $result['data']['lang']);
        $this->assertFalse($result['data']['deleted']);

        $captions = caption_service::get_captions($this->instance->id);
        $this->assertCount(1, $captions);
        $this->assertStringStartsWith('WEBVTT', $captions[0]->content);
    }

    /**
     * Tests that saving again for the same language updates it in place (upsert), never
     * creating a second row — the caller relies on this to "just save" without checking first.
     *
     * @return void
     */
    public function test_saving_the_same_language_again_updates_in_place(): void {
        $this->call(['content' => '0:05 First.']);
        $this->call(['content' => '0:10 Second.']);

        $captions = caption_service::get_captions($this->instance->id);
        $this->assertCount(1, $captions);
        $this->assertStringContainsString('Second.', $captions[0]->content);
    }

    /**
     * Tests that the language code is lowercased and trimmed before saving.
     *
     * @return void
     */
    public function test_language_code_is_normalised(): void {
        $result = $this->call(['lang' => ' PT-BR ']);

        $this->assertFalse($result['error']);
        $this->assertSame('pt-br', $result['data']['lang']);
    }

    /**
     * Tests that a language code with characters outside a-z0-9_- is rejected, even though the
     * WS parameter itself accepts PARAM_RAW (see save_caption.php for why).
     *
     * @return void
     */
    public function test_invalid_language_code_is_rejected(): void {
        $result = $this->call(['lang' => 'pt br!']);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidlang', $result['exception']->errorcode);
    }

    /**
     * Tests that empty content is rejected on create, before ever touching the database.
     *
     * @return void
     */
    public function test_empty_content_is_rejected(): void {
        $result = $this->call(['content' => '   ']);

        $this->assertTrue($result['error']);
        $this->assertSame('error_captioncontentrequired', $result['exception']->errorcode);
    }

    /**
     * Tests that a caption can be deleted, and that deleting a language with nothing saved
     * fails with a clear error instead of silently succeeding.
     *
     * @return void
     */
    public function test_teacher_can_delete_a_caption(): void {
        $this->call([]);

        $result = $this->call(['delete' => true]);
        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['deleted']);
        $this->assertCount(0, caption_service::get_captions($this->instance->id));

        $second = $this->call(['delete' => true]);
        $this->assertTrue($second['error']);
        $this->assertSame('error_captionnotfound', $second['exception']->errorcode);
    }

    /**
     * Tests that a student cannot save a caption — must fail on the capability check.
     *
     * @return void
     */
    public function test_student_cannot_save_a_caption(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call([]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }
}
