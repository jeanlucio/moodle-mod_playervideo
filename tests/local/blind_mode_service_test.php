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
 * Unit tests for the blind-mode (text-only) document builder.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for blind_mode_service.
 *
 * @covers \mod_playervideo\local\blind_mode_service
 */
final class blind_mode_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that caption cues and interactions are merged into one list ordered strictly by
     * timestamp, and that each block is tagged with the right "kind".
     *
     * @return void
     */
    public function test_build_document_merges_captions_and_interactions_by_timestamp(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);
        $context = \context_module::instance(get_coursemodule_from_instance('playervideo', $instance->id)->id);

        caption_service::save_caption($instance->id, 'en', "0:05 First caption line.\n0:40 Second caption line.");

        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id,
            'timestamp' => 20,
            'type' => 'note',
            'weight' => 1,
            'notetext' => 'A note in the middle.',
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $blocks = blind_mode_service::build_document($instance, $context);

        $this->assertCount(3, $blocks);
        $this->assertSame('text', $blocks[0]['kind']);
        $this->assertSame(5.0, $blocks[0]['timestamp']);
        $this->assertSame('interaction', $blocks[1]['kind']);
        $this->assertSame(20.0, $blocks[1]['timestamp']);
        $this->assertSame('note', $blocks[1]['type']);
        $this->assertSame('text', $blocks[2]['kind']);
        $this->assertSame(40.0, $blocks[2]['timestamp']);
    }

    /**
     * Tests that the document still builds (interactions only, no text blocks) when the
     * instance has no caption at all — this mode must degrade gracefully, not error out.
     *
     * @return void
     */
    public function test_build_document_works_with_no_captions_at_all(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);
        $context = \context_module::instance(get_coursemodule_from_instance('playervideo', $instance->id)->id);

        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id,
            'timestamp' => 10,
            'type' => 'note',
            'weight' => 1,
            'notetext' => 'Only interaction.',
            'notetextformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $blocks = blind_mode_service::build_document($instance, $context);

        $this->assertCount(1, $blocks);
        $this->assertSame('interaction', $blocks[0]['kind']);
    }

    /**
     * Tests that a question interaction block carries full "Blind JSON" question data (never
     * revealing which option is correct) via the same helper the video player uses.
     *
     * @return void
     */
    public function test_question_interaction_block_carries_question_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id);
        $context = \context_module::instance($cm->id);

        $categoryid = question_service::get_or_create_category($context);
        $formdata = new \stdClass();
        $formdata->name = 'Q';
        $formdata->questiontext = ['text' => 'What is 2+2?', 'format' => FORMAT_HTML];
        $formdata->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->defaultmark = 1;
        $formdata->penalty = 0;
        $qtypedata = question_service::build_multichoice_formdata(
            [['text' => '3', 'correct' => false], ['text' => '4', 'correct' => true]],
            true
        );
        foreach (get_object_vars($qtypedata) as $field => $value) {
            $formdata->$field = $value;
        }
        $questionid = question_service::create_question('multichoice', $categoryid, $context->id, $formdata);

        $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id,
            'timestamp' => 5,
            'type' => 'question',
            'weight' => 1,
            'questionid' => $questionid,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $blocks = blind_mode_service::build_document($instance, $context);

        $this->assertCount(1, $blocks);
        $this->assertNotNull($blocks[0]['question']);
        $this->assertCount(2, $blocks[0]['question']['options']);
        foreach ($blocks[0]['question']['options'] as $option) {
            $this->assertArrayNotHasKey('correct', $option);
        }
    }
}
