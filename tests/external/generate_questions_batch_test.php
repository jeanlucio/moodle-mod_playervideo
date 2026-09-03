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
 * External function tests for generate_questions_batch.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use core_external\external_api;

/**
 * Tests for the mod_playervideo_generate_questions_batch web service.
 *
 * As with generate_question_ai_test, no real generation call is exercised — the test
 * environment has no AI source configured, so that path is tested via its own clear error
 * instead of a mock. The timestamp-anchoring and JSON-parsing helpers, being pure functions, are
 * fully covered here via Reflection.
 *
 * @covers \mod_playervideo\external\generate_questions_batch
 */
final class generate_questions_batch_test extends \advanced_testcase {
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
        return external_api::call_external_function('mod_playervideo_generate_questions_batch', array_merge([
            'transcript' => "0:05 Introduction to the topic.\n1:30 The main concept explained.",
            'count' => 3,
            'format' => 'mc',
        ], $args));
    }

    /**
     * Tests that an empty transcript is rejected before any AI call is attempted.
     *
     * @return void
     */
    public function test_empty_transcript_is_rejected(): void {
        $result = $this->call(['playervideoid' => $this->instance->id, 'transcript' => '   ']);

        $this->assertTrue($result['error']);
        $this->assertSame('error_transcriptrequired', $result['exception']->errorcode);
    }

    /**
     * Tests that an invalid format value is rejected.
     *
     * @return void
     */
    public function test_invalid_format_is_rejected(): void {
        $result = $this->call(['playervideoid' => $this->instance->id, 'format' => 'invalid']);

        $this->assertTrue($result['error']);
        $this->assertSame('error_invalidqtype', $result['exception']->errorcode);
    }

    /**
     * Tests that, with no AI source configured, the call fails with a clear "no AI source"
     * error rather than a raw exception from further down.
     *
     * @return void
     */
    public function test_fails_clearly_with_no_ai_source_configured(): void {
        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_noaisource', $result['exception']->errorcode);
    }

    /**
     * Tests that a student cannot request a batch generation — must fail on the capability
     * check, before reaching the transcript/AI-source checks exercised above.
     *
     * @return void
     */
    public function test_student_cannot_generate_a_batch(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $result = $this->call(['playervideoid' => $this->instance->id]);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }

    /**
     * Tests that extract_transcript_timestamps() recognises mm:ss, h:mm:ss and a bare "12s"
     * style anywhere in a line — "any reasonable format" per the project spec, not a fixed
     * grammar.
     *
     * @return void
     */
    public function test_extract_transcript_timestamps_recognises_common_formats(): void {
        $method = new \ReflectionMethod(generate_questions_batch::class, 'extract_transcript_timestamps');
        $method->setAccessible(true);

        $transcript = "0:05 First line.\n01:02:03 Second line.\nThird line at 45s.\nNo timestamp here.";
        $timestamps = $method->invoke(null, $transcript);

        $this->assertContains(5, $timestamps);
        $this->assertContains(3723, $timestamps);
        $this->assertContains(45, $timestamps);
        $this->assertCount(3, $timestamps);
    }

    /**
     * Tests parse_response() accepts a well-formed batch response with a mix of question types.
     *
     * @return void
     */
    public function test_parse_response_accepts_a_wellformed_batch(): void {
        $method = new \ReflectionMethod(generate_questions_batch::class, 'parse_response');
        $method->setAccessible(true);

        $json = '{"questions": ['
            . '{"timestamp": 5, "qtype": "multichoice", "questiontext": "Q1?", '
            . '"answers": [{"text": "A", "correct": true}, {"text": "B", "correct": false}]},'
            . '{"timestamp": 90, "qtype": "essay", "questiontext": "Q2?"}'
            . ']}';
        $candidates = $method->invoke(null, $json);

        $this->assertCount(2, $candidates);
        $this->assertSame(5, $candidates[0]['timestamp']);
        $this->assertSame('multichoice', $candidates[0]['qtype']);
        $this->assertCount(2, $candidates[0]['answers']);
        $this->assertSame(90, $candidates[1]['timestamp']);
        $this->assertSame('essay', $candidates[1]['qtype']);
        $this->assertSame([], $candidates[1]['answers']);
    }

    /**
     * Tests parse_response() returns null when the response has no "questions" array at all.
     *
     * @return void
     */
    public function test_parse_response_rejects_missing_questions_array(): void {
        $method = new \ReflectionMethod(generate_questions_batch::class, 'parse_response');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, '{"foo": "bar"}'));
        $this->assertNull($method->invoke(null, 'not even json'));
    }

    /**
     * Tests parse_response() converts a "mm:ss"/"h:mm:ss" style timestamp string to seconds,
     * instead of truncating it via a naive int cast — a real model (Groq gpt-oss-120b) was
     * observed echoing the transcript's own "0:45" text back despite the prompt asking for a
     * plain integer, which silently zeroed out every candidate's timestamp and failed the
     * anchoring check against extract_transcript_timestamps().
     *
     * @return void
     */
    public function test_parse_response_normalises_a_string_timestamp(): void {
        $method = new \ReflectionMethod(generate_questions_batch::class, 'parse_response');
        $method->setAccessible(true);

        $json = '{"questions": ['
            . '{"timestamp": "0:45", "qtype": "multichoice", "questiontext": "Q1?", "answers": []},'
            . '{"timestamp": "1:02:03", "qtype": "essay", "questiontext": "Q2?"}'
            . ']}';
        $candidates = $method->invoke(null, $json);

        $this->assertSame(45, $candidates[0]['timestamp']);
        $this->assertSame(3723, $candidates[1]['timestamp']);
    }
}
