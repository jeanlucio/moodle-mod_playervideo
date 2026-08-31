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
 * External function tests for search_questions.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_search_questions web service.
 *
 * @covers \mod_playervideo\external\search_questions
 */
final class search_questions_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Editing teacher, enrolled in $course. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_search_questions', array_merge([
            'query' => '',
            'limit' => 20,
        ], $args));
    }

    /**
     * Tests that a question in the course's own category is found, and that a text query
     * filters correctly.
     *
     * @return void
     */
    public function test_finds_questions_in_course_category(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id]);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $coursecontext = \context_course::instance($this->course->id);
        $category = $questiongenerator->create_question_category(['contextid' => $coursecontext->id]);
        $questiongenerator->create_question('multichoice', 'one_of_four', [
            'category' => $category->id,
            'questiontext' => ['text' => 'Which of these is a volcano?', 'format' => FORMAT_HTML],
        ]);
        $questiongenerator->create_question('truefalse', 'true', [
            'category' => $category->id,
            'questiontext' => ['text' => 'This river is the longest in the world.', 'format' => FORMAT_HTML],
        ]);

        $result = $this->call(['playervideoid' => $instance->id]);
        $this->assertFalse($result['error']);
        $this->assertCount(2, $result['data']['questions']);

        $filtered = $this->call(['playervideoid' => $instance->id, 'query' => 'volcano']);
        $this->assertFalse($filtered['error']);
        $this->assertCount(1, $filtered['data']['questions']);
    }

    /**
     * Tests that questions in a category the teacher has no access to are never returned.
     *
     * @return void
     */
    public function test_does_not_leak_questions_from_inaccessible_categories(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id]);

        $othercourse = $this->getDataGenerator()->create_course();
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $othercontext = \context_course::instance($othercourse->id);
        $othercategory = $questiongenerator->create_question_category(['contextid' => $othercontext->id]);
        $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $othercategory->id]);

        $result = $this->call(['playervideoid' => $instance->id]);

        $this->assertFalse($result['error']);
        $this->assertSame([], $result['data']['questions']);
    }
}
