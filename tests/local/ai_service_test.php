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
 * Unit tests for the shared AI routing service.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for ai_service.
 *
 * Deliberately does not exercise generate() end to end: doing so would either make a real
 * network call to an AI provider or require mocking local_aihub/core_ai internals this class
 * has no control over, mirroring the same choice already made for the sibling
 * mod_playerwords\local\ai_word_generator_test — routing/availability is tested here, actual
 * generation is validated live instead.
 *
 * @covers \mod_playervideo\local\ai_service
 */
final class ai_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that has_ai_source() returns false, without throwing, when neither local_aihub nor
     * core_ai has anything configured — the state of a fresh test environment. This is also the
     * exact condition generate_question_ai/generate_questions_batch rely on to exercise their
     * "error_noaisource" path without needing a real AI stub.
     *
     * @return void
     */
    public function test_has_ai_source_returns_false_with_nothing_configured(): void {
        $this->assertFalse(ai_service::has_ai_source(\context_system::instance()));
    }

    /**
     * Tests that generate() fails gracefully (never throws) when no AI source is available,
     * returning a structured failure the caller can inspect.
     *
     * @return void
     */
    public function test_generate_fails_gracefully_with_nothing_configured(): void {
        $result = ai_service::generate('prompt', 'test', \context_system::instance());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('message', $result);
    }
}
