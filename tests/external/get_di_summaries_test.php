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
 * External function tests for get_di_summaries.
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
 * Tests for the mod_playervideo_get_di_summaries web service.
 *
 * @covers \mod_playervideo\external\get_di_summaries
 */
final class get_di_summaries_test extends \advanced_testcase {
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
        return external_api::call_external_function('mod_playervideo_get_di_summaries', $args);
    }

    /**
     * Tests that a student only ever sees approved summaries — a pending one stays invisible
     * to them, enforced server-side rather than left for the client to hide.
     *
     * @return void
     */
    public function test_student_only_sees_approved_summaries(): void {
        di_summary_service::save_generated($this->instance->id, 'en', 'Pending draft.');
        di_summary_service::save_reviewed($this->instance->id, 'pt-br', 'Aprovado.', true);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $this->assertCount(1, $result['data']['summaries']);
        $this->assertSame('pt-br', $result['data']['summaries'][0]['lang']);
    }

    /**
     * Tests that a teacher sees every summary, including a pending one.
     *
     * @return void
     */
    public function test_teacher_sees_every_summary_including_pending(): void {
        di_summary_service::save_generated($this->instance->id, 'en', 'Pending draft.');
        di_summary_service::save_reviewed($this->instance->id, 'pt-br', 'Aprovado.', true);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertFalse($result['error']);
        $this->assertCount(2, $result['data']['summaries']);
    }
}
