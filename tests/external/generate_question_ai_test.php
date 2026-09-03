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
 * External function tests for generate_question_ai.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_generate_question_ai web service.
 *
 * A real generation call is never exercised here — the test environment has no AI source
 * configured (no local_aihub key, no core_ai provider), so every such call would hit
 * "error_noaisource" before ever reaching a provider; that path is itself tested below. Actual
 * generation is validated live instead, mirroring the same choice already made for
 * mod_playerwords\local\ai_word_generator_test.
 *
 * @covers \mod_playervideo\external\generate_question_ai
 */
final class generate_question_ai_test extends \advanced_testcase {
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
        return external_api::call_external_function('mod_playervideo_generate_question_ai', array_merge([
            'context' => '',
            'qtype' => 'multichoice',
        ], $args));
    }

    /**
     * Tests that an invalid question type is rejected before any AI call is attempted.
     *
     * @return void
     */
    public function test_invalid_qtype_is_rejected(): void {
        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'timestamp' => 10,
            'qtype' => 'truefalse',
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidqtype', $result['exception']->errorcode);
    }

    /**
     * Tests that, with no AI source configured (the state of a fresh test environment), the
     * call fails with a clear "no AI source" error rather than a raw exception from further down.
     *
     * @return void
     */
    public function test_fails_clearly_with_no_ai_source_configured(): void {
        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'timestamp' => 10,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_noaisource', $result['exception']->errorcode);
    }

    /**
     * Tests that a student (who has mod/playervideo:attempt, not :manage) cannot generate a
     * question — this must fail on the capability check, before it even reaches the AI-source
     * check exercised above.
     *
     * @return void
     */
    public function test_student_cannot_generate_a_question(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call([
            'playervideoid' => $this->instance->id,
            'timestamp' => 10,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }

    /**
     * Tests parse_response() accepts a well-formed multichoice response and extracts every
     * field, via Reflection since the method is private (mirrors the same testing approach
     * already used for mod_playerwords\local\ai_word_generator's private parsing helpers).
     *
     * @return void
     */
    public function test_parse_response_accepts_a_wellformed_multichoice_response(): void {
        $method = new \ReflectionMethod(generate_question_ai::class, 'parse_response');

        $json = '{"questiontext": "What is 2+2?", "answers": ['
            . '{"text": "3", "correct": false}, {"text": "4", "correct": true}]}';
        $method->setAccessible(true);
        $decoded = $method->invoke(null, $json);

        $this->assertSame('What is 2+2?', $decoded['questiontext']);
        $this->assertCount(2, $decoded['answers']);
        $this->assertFalse($decoded['answers'][0]['correct']);
        $this->assertTrue($decoded['answers'][1]['correct']);
    }

    /**
     * Tests parse_response() strips markdown code fences before decoding — a common way for a
     * model to wrap JSON despite being asked not to.
     *
     * @return void
     */
    public function test_parse_response_strips_code_fences(): void {
        $method = new \ReflectionMethod(generate_question_ai::class, 'parse_response');

        $fence = str_repeat("\x60", 3);
        $fenced = "{$fence}json\n{\"questiontext\": \"Explain X.\"}\n{$fence}";
        $method->setAccessible(true);
        $decoded = $method->invoke(null, $fenced);

        $this->assertSame('Explain X.', $decoded['questiontext']);
        $this->assertSame([], $decoded['answers']);
    }

    /**
     * Tests parse_response() returns null for a response missing the required questiontext key,
     * rather than a partially-populated array that would look usable to a careless caller.
     *
     * @return void
     */
    public function test_parse_response_rejects_missing_questiontext(): void {
        $method = new \ReflectionMethod(generate_question_ai::class, 'parse_response');

        $method->setAccessible(true);
        $this->assertNull($method->invoke(null, '{"answers": []}'));
        $this->assertNull($method->invoke(null, 'not even json'));
    }
}
