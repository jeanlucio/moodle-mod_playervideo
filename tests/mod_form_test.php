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
 * Unit tests for mod_playervideo_mod_form's custom validation rules.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo;

/**
 * Tests for mod_playervideo_mod_form::validation().
 *
 * @covers \mod_playervideo_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/mod/playervideo/mod_form.php');
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Instantiates mod_playervideo_mod_form for an existing instance, enough to run
     * validation() against.
     *
     * @return \mod_playervideo_mod_form
     */
    private function build_form(): \mod_playervideo_mod_form {
        global $PAGE;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id);

        $PAGE->set_course($this->course);

        $data = (object) [
            'instance' => $instance->id,
            'id' => $cm->id,
            'course' => $this->course->id,
        ];

        return new \mod_playervideo_mod_form($data, 0, $cm, $this->course);
    }

    /**
     * Creates a draft file area containing a single image, mimicking what the filepicker
     * leaves behind after a real upload in the browser.
     *
     * @return int The new draft item id.
     */
    private function create_poster_draft_with_file(): int {
        global $USER;

        $draftitemid = 0;
        file_prepare_draft_area($draftitemid, null, 'user', 'draft', null);

        $usercontext = \context_user::instance($USER->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'poster.png',
        ], 'fake-png-bytes');

        return $draftitemid;
    }

    /**
     * Builds a minimal, well-formed submission the parent moodleform_mod::validation()
     * can process without missing-key notices, overridable per test.
     *
     * @param array $overrides Fields to override on top of the base submission.
     * @return array The submitted data array.
     */
    private function base_submission(array $overrides = []): array {
        $data = [
            'name' => 'A Video Activity',
            'modulename' => 'playervideo',
            'instance' => 0,
            'coursemodule' => 0,
            'cmidnumber' => '',
            'availabilityconditionsjson' => '',
            'videotype' => 'youtube',
            'videourl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'posterimage' => 0,
            'posterdescription' => '',
            'hudretrycostitem' => 0,
            'hudretrycostqty' => 1,
            'grade' => ['modgrade_type' => 'point', 'modgrade_point' => 100, 'modgrade_scale' => 0],
        ];

        return array_merge($data, $overrides);
    }

    /**
     * A poster image uploaded without an accessibility description is rejected.
     *
     * @return void
     */
    public function test_rejects_poster_without_description(): void {
        $form = $this->build_form();
        $draftitemid = $this->create_poster_draft_with_file();

        $errors = $form->validation($this->base_submission(['posterimage' => $draftitemid]), []);

        $this->assertArrayHasKey('posterdescription', $errors);
        $this->assertSame(get_string('error_posterdescriptionrequired', 'mod_playervideo'), $errors['posterdescription']);
    }

    /**
     * A poster image accompanied by a description raises no error.
     *
     * @return void
     */
    public function test_accepts_poster_with_description(): void {
        $form = $this->build_form();
        $draftitemid = $this->create_poster_draft_with_file();

        $errors = $form->validation($this->base_submission([
            'posterimage' => $draftitemid,
            'posterdescription' => 'A sunrise over a calm lake.',
        ]), []);

        $this->assertArrayNotHasKey('posterdescription', $errors);
    }

    /**
     * No poster image at all needs no description either — the requirement is
     * conditional on an image actually being present, not a blanket rule.
     *
     * @return void
     */
    public function test_no_poster_needs_no_description(): void {
        $form = $this->build_form();

        $errors = $form->validation($this->base_submission(), []);

        $this->assertArrayNotHasKey('posterdescription', $errors);
    }

    /**
     * A description made only of whitespace is treated the same as an empty one.
     *
     * @return void
     */
    public function test_rejects_whitespace_only_description(): void {
        $form = $this->build_form();
        $draftitemid = $this->create_poster_draft_with_file();

        $errors = $form->validation($this->base_submission([
            'posterimage' => $draftitemid,
            'posterdescription' => '   ',
        ]), []);

        $this->assertArrayHasKey('posterdescription', $errors);
    }
}
