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
 * External function tests for get_interactions.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_get_interactions web service.
 *
 * @covers \mod_playervideo\external\get_interactions
 */
final class get_interactions_test extends \advanced_testcase {
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
    }

    /**
     * Calls the web service through the real dispatch path (sesskey, capability and
     * parameter validation all exercised).
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playervideo_get_interactions', $args);
    }

    /**
     * Tests that the trim window and interactions (ordered by timestamp) are returned,
     * with a formatted preview for question interactions and the raw note text for notes.
     *
     * @return void
     */
    public function test_returns_trim_and_ordered_interactions(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id, 'trimstart' => 5, 'trimend' => 90]);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $questiongenerator->create_question('multichoice', 'one_of_four', [
            'category' => $category->id,
            'questiontext' => ['text' => 'What is the capital of France?', 'format' => FORMAT_HTML],
        ]);

        $now = time();
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 20, 'type' => 'note', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Watch closely here', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 10, 'type' => 'question', 'weight' => 2,
            'questionid' => $question->id, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->setUser($this->teacher);
        $result = $this->call(['playervideoid' => $instance->id]);

        $this->assertFalse($result['error']);
        $this->assertSame(5.0, $result['data']['trimstart']);
        $this->assertSame(90.0, $result['data']['trimend']);

        $interactions = $result['data']['interactions'];
        $this->assertCount(2, $interactions);
        // Ordered by timestamp: the question (10) comes before the note (20).
        $this->assertSame('question', $interactions[0]['type']);
        $this->assertSame(2.0, $interactions[0]['weight']);
        $this->assertStringContainsString('capital of France', $interactions[0]['questionpreview']);
        $this->assertSame('note', $interactions[1]['type']);
        $this->assertSame('Watch closely here', $interactions[1]['notetext']);
    }

    /**
     * Tests that a student (without mod/playervideo:manage) is denied.
     *
     * @return void
     */
    public function test_requires_manage_capability(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_interactions::execute($instance->id);
    }
}
