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
 * Cross-instance/cross-course isolation regression tests for mod_playervideo's Web Services.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo;

use core_external\external_api;
use mod_playervideo\external\generate_response_correction;
use mod_playervideo\external\get_pending_corrections;
use mod_playervideo\external\get_poll_results;
use mod_playervideo\external\get_report;
use mod_playervideo\external\review_response;
use mod_playervideo\local\question_service;

/**
 * Every Web Service that takes an isolated id (attemptid, responseid, interactionid,
 * playervideoid) must derive its access context from that id's own instance/course — never from
 * an id the caller merely happens to know. This suite exercises that invariant end to end, across
 * a representative WS from each capability tier (student attempt-taking, teacher grading, teacher
 * analytics) — complementing (not duplicating) each WS's own unit tests, which already cover
 * their business rules individually. For a caller with no relationship whatsoever to the target
 * course (the realistic attacker shape here — a teacher of course A has no role in random course
 * B), validate_context()'s own require_login() check rejects the request before require_capability()
 * is even reached; that is the exception these tests assert, an even stronger guarantee than a
 * capability failure would be.
 *
 * @covers \mod_playervideo\external\submit_answer
 * @covers \mod_playervideo\external\review_response
 * @covers \mod_playervideo\external\generate_response_correction
 * @covers \mod_playervideo\external\get_pending_corrections
 * @covers \mod_playervideo\external\get_report
 * @covers \mod_playervideo\external\get_poll_results
 */
final class cross_instance_security_test extends \advanced_testcase {
    /** @var \stdClass Course A, with its own teacher/student and playervideo instance. */
    private \stdClass $coursea;

    /** @var \stdClass Course B, entirely separate from A. */
    private \stdClass $courseb;

    /** @var \stdClass Teacher enrolled only in course A. */
    private \stdClass $teachera;

    /** @var \stdClass Student enrolled only in course A. */
    private \stdClass $studenta;

    /** @var \stdClass Student enrolled only in course B. */
    private \stdClass $studentb;

    /** @var \stdClass PlayerVideo instance in course A. */
    private \stdClass $instancea;

    /** @var \stdClass PlayerVideo instance in course B. */
    private \stdClass $instanceb;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->coursea = $this->getDataGenerator()->create_course();
        $this->courseb = $this->getDataGenerator()->create_course();

        $this->teachera = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teachera->id, $this->coursea->id, 'editingteacher');

        $this->studenta = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->studenta->id, $this->coursea->id, 'student');

        $this->studentb = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->studentb->id, $this->courseb->id, 'student');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $this->instancea = $generator->create_instance(['course' => $this->coursea->id]);
        $this->instanceb = $generator->create_instance(['course' => $this->courseb->id]);
    }

    /**
     * Calls a mod_playervideo Web Service through the real dispatch path.
     *
     * @param string $method Web service method name.
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(string $method, array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function($method, $args);
    }

    /**
     * Inserts a question-type interaction directly, for the given instance.
     *
     * @param \stdClass $instance PlayerVideo instance.
     * @param int $questionid Question Bank id, 0 for a note interaction.
     * @return int Interaction id.
     */
    private function make_question_interaction(\stdClass $instance, int $questionid): int {
        global $DB;

        $now = time();
        return $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $instance->id, 'timestamp' => 5, 'type' => 'question', 'weight' => 1,
            'questionid' => $questionid, 'notetext' => null, 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Inserts an in-progress attempt for the given instance/user.
     *
     * @param \stdClass $instance PlayerVideo instance.
     * @param int $userid Student id.
     * @return int Attempt id.
     */
    private function make_attempt(\stdClass $instance, int $userid): int {
        global $DB;

        $now = time();
        return $DB->insert_record('playervideo_attempts', (object) [
            'playervideoid' => $instance->id, 'userid' => $userid, 'attemptnumber' => 1,
            'status' => 'inprogress', 'grade' => null, 'hudretrycharged' => 0,
            'timestart' => $now, 'timefinish' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Creates an essay question in the given instance's own module-context category.
     *
     * @param \stdClass $instance PlayerVideo instance.
     * @return int Question id.
     */
    private function make_essay_question(\stdClass $instance): int {
        $cm = get_coursemodule_from_instance('playervideo', $instance->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $categoryid = question_service::get_or_create_category($context);

        $formdata = question_service::build_essay_formdata();
        $formdata->name = 'Essay question';
        $formdata->questiontext = ['text' => 'Explain photosynthesis.', 'format' => FORMAT_HTML];
        $formdata->defaultmark = 1;
        $formdata->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];

        return question_service::create_question('essay', $categoryid, $context->id, $formdata);
    }

    /**
     * Tests that submit_answer, given an attempt from instance A, never accepts an
     * interactionid belonging to instance B — even though both instances exist and the id
     * itself is a real row in the database.
     *
     * @return void
     */
    public function test_submit_answer_rejects_interaction_from_another_instance(): void {
        $questionidb = $this->make_essay_question($this->instanceb);
        $interactionidb = $this->make_question_interaction($this->instanceb, $questionidb);
        $attemptida = $this->make_attempt($this->instancea, $this->studenta->id);

        $this->setUser($this->studenta);
        $result = $this->call('mod_playervideo_submit_answer', [
            'attemptid' => $attemptida,
            'interactionid' => $interactionidb,
            'answerid' => 0,
            'responsetext' => 'Some answer',
            'polloptionid' => 0,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('error_interactionnotfound', $result['exception']->errorcode);
    }

    /**
     * Tests that review_response derives its access context from the response's own course, so
     * a teacher enrolled only in course A can never grade a response that belongs to course B,
     * even by supplying B's raw responseid directly.
     *
     * @return void
     */
    public function test_review_response_is_scoped_to_the_responses_own_course(): void {
        global $DB;

        $questionidb = $this->make_essay_question($this->instanceb);
        $interactionidb = $this->make_question_interaction($this->instanceb, $questionidb);
        $attemptidb = $this->make_attempt($this->instanceb, $this->studentb->id);

        $now = time();
        $responseidb = $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $this->instanceb->id, 'userid' => $this->studentb->id, 'attemptid' => $attemptidb,
            'interactionid' => $interactionidb, 'questionid' => $questionidb, 'answerid' => null,
            'polloptionid' => null, 'responsetext' => 'A vague answer.', 'iscorrect' => null, 'hudrewarded' => 0,
            'aigrade' => null, 'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'pending_ai', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->setUser($this->teachera);
        $this->expectException(\require_login_exception::class);
        review_response::execute($responseidb, 1.0, 'Good job.');
    }

    /**
     * Tests that generate_response_correction is likewise scoped to the response's own course,
     * not the caller's assumed one.
     *
     * @return void
     */
    public function test_generate_response_correction_is_scoped_to_the_responses_own_course(): void {
        global $DB;

        $questionidb = $this->make_essay_question($this->instanceb);
        $interactionidb = $this->make_question_interaction($this->instanceb, $questionidb);
        $attemptidb = $this->make_attempt($this->instanceb, $this->studentb->id);

        $now = time();
        $responseidb = $DB->insert_record('playervideo_responses', (object) [
            'playervideoid' => $this->instanceb->id, 'userid' => $this->studentb->id, 'attemptid' => $attemptidb,
            'interactionid' => $interactionidb, 'questionid' => $questionidb, 'answerid' => null,
            'polloptionid' => null, 'responsetext' => 'A vague answer.', 'iscorrect' => null, 'hudrewarded' => 0,
            'aigrade' => null, 'aifeedback' => null, 'teachergrade' => null, 'teacherfeedback' => null,
            'status' => 'pending_ai', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->setUser($this->teachera);
        $this->expectException(\require_login_exception::class);
        generate_response_correction::execute($responseidb);
    }

    /**
     * Tests that get_pending_corrections, called with course B's playervideoid, is scoped to
     * course B's own context — a teacher enrolled only in course A is rejected outright, never
     * shown course B's (empty or otherwise) correction queue.
     *
     * @return void
     */
    public function test_get_pending_corrections_is_scoped_to_the_requested_instances_own_course(): void {
        $this->setUser($this->teachera);
        $this->expectException(\require_login_exception::class);
        get_pending_corrections::execute($this->instanceb->id);
    }

    /**
     * Tests that get_report, called with course B's playervideoid, is scoped to course B's own
     * context, not the caller's.
     *
     * @return void
     */
    public function test_get_report_is_scoped_to_the_requested_instances_own_course(): void {
        $this->setUser($this->teachera);
        $this->expectException(\require_login_exception::class);
        get_report::execute($this->instanceb->id);
    }

    /**
     * Tests that get_poll_results derives its context from the interaction's own instance, so
     * a student enrolled only in course A cannot read course B's poll results by supplying B's
     * raw interactionid.
     *
     * @return void
     */
    public function test_get_poll_results_is_scoped_to_the_interactions_own_course(): void {
        global $DB;

        $now = time();
        $pollinteractionidb = $DB->insert_record('playervideo_interactions', (object) [
            'playervideoid' => $this->instanceb->id, 'timestamp' => 5, 'type' => 'poll', 'weight' => 1,
            'questionid' => null, 'notetext' => 'Favourite colour?', 'notetextformat' => FORMAT_HTML,
            'sortorder' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->setUser($this->studenta);
        $this->expectException(\require_login_exception::class);
        get_poll_results::execute($pollinteractionidb);
    }
}
