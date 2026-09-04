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
     * @param array $instanceoverrides Field values to override on the generated instance.
     * @return \mod_playervideo_mod_form
     */
    private function build_form(array $instanceoverrides = []): \mod_playervideo_mod_form {
        global $PAGE;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(array_merge(['course' => $this->course->id], $instanceoverrides));
        $cm = get_coursemodule_from_instance('playervideo', $instance->id);

        $PAGE->set_course($this->course);

        // Cloning the real instance record (not a bare {instance, id, course} stub) matters
        // here: get_moduleinfo_data() does the same in real Moodle before constructing the
        // form, and add_stale_hud_item_option() reads $this->current->hudcorrectitem — a
        // stub object without that field would always see it as unset (0), silently skipping
        // the whole stale-option branch regardless of what the test actually configured.
        $data = clone $instance;
        $data->instance = $instance->id;
        $data->id = $cm->id;

        return new \mod_playervideo_mod_form($data, 0, $cm, $this->course);
    }

    /**
     * Extracts the underlying MoodleQuickForm from a mod_playervideo_mod_form instance, so a
     * test can inspect elements/types that have no public accessor of their own.
     *
     * @param \mod_playervideo_mod_form $form Form instance.
     * @return \MoodleQuickForm
     */
    private function get_quickform(\mod_playervideo_mod_form $form): \MoodleQuickForm {
        $refclass = new \ReflectionClass(\mod_playervideo_mod_form::class);
        $formprop = $refclass->getProperty('_form');
        $formprop->setAccessible(true);

        return $formprop->getValue($form);
    }

    /**
     * Skips the current test when block_playerhud is not installed — mirrors the same soft
     * dependency guard already used by hud_service_test.php.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a block_instances record for block_playerhud in the given course context —
     * same pattern as hud_service_test.php's own make_block_instance().
     *
     * @param \stdClass $course Course object.
     * @return int Block instance ID.
     */
    private function make_hud_block(\stdClass $course): int {
        global $DB;
        $context = \context_course::instance($course->id);

        return $DB->insert_record('block_instances', (object) [
            'blockname' => 'playerhud',
            'parentcontextid' => $context->id,
            'showinsubcontexts' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => base64_encode(serialize(new \stdClass())),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts a block_playerhud_items record for the given block instance — same pattern as
     * hud_service_test.php's own make_item().
     *
     * @param int $blockinstanceid Block instance ID.
     * @param string $name Item display name.
     * @param bool $enabled Whether the item is enabled.
     * @return int Item ID.
     */
    private function make_hud_item(int $blockinstanceid, string $name = 'Gold Key', bool $enabled = true): int {
        global $DB;

        return $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $blockinstanceid,
            'name' => $name,
            'xp' => 0,
            'image' => '',
            'description' => '',
            'enabled' => $enabled ? 1 : 0,
            'secret' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
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

    /**
     * Editing an existing instance that already has a stored videofile/posterimage preloads
     * both into fresh draft areas, so their filepickers show the file instead of rendering
     * empty — the exact silent-data-loss gap this method was added to close (see its own
     * docblock).
     *
     * @return void
     */
    public function test_data_preprocessing_preloads_existing_videofile_and_posterimage(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playervideo');
        $instance = $generator->create_instance(['course' => $this->course->id, 'videotype' => 'html5']);
        $cm = get_coursemodule_from_instance('playervideo', $instance->id);
        $context = \context_module::instance($cm->id);

        $fs = get_file_storage();
        foreach (['videofile', 'posterimage'] as $filearea) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_playervideo',
                'filearea' => $filearea,
                'itemid' => 0,
                'filepath' => '/',
                'filename' => "$filearea.bin",
            ], 'fake-bytes');
        }

        global $PAGE;
        $PAGE->set_course($this->course);
        $data = (object) ['instance' => $instance->id, 'id' => $cm->id, 'course' => $this->course->id];
        $form = new \mod_playervideo_mod_form($data, 0, $cm, $this->course);

        $defaultvalues = [];
        $form->data_preprocessing($defaultvalues);

        foreach (['videofile', 'posterimage'] as $filearea) {
            $this->assertArrayHasKey($filearea, $defaultvalues);
            $this->assertNotEmpty($defaultvalues[$filearea]);
            $info = file_get_draft_area_info((int) $defaultvalues[$filearea]);
            $this->assertSame(1, $info['filecount']);
        }
    }

    /**
     * Adding a brand new instance (no course module yet) is a no-op — there is nothing to
     * preload, and the method must not try to prepare a draft area against a non-existent
     * file area.
     *
     * @return void
     */
    public function test_data_preprocessing_is_noop_when_adding_a_new_instance(): void {
        global $PAGE;
        $PAGE->set_course($this->course);

        $data = (object) ['instance' => 0, 'id' => 0, 'course' => $this->course->id, 'section' => 0];
        $form = new \mod_playervideo_mod_form($data, 0, null, $this->course);

        $defaultvalues = [];
        $form->data_preprocessing($defaultvalues);

        $this->assertSame([], $defaultvalues);
    }

    /**
     * The activity name defaults to PARAM_TEXT, the common case on a site that strips tags
     * from formatted strings.
     *
     * @return void
     */
    public function test_name_field_uses_text_param_by_default(): void {
        set_config('formatstringstriptags', 1);

        $mform = $this->get_quickform($this->build_form());

        $this->assertSame(PARAM_TEXT, $mform->_types['name']);
    }

    /**
     * With formatstringstriptags disabled, the name field falls back to PARAM_CLEANHTML so
     * a title can legitimately contain simple markup.
     *
     * @return void
     */
    public function test_name_field_uses_cleanhtml_param_when_formatstringstriptags_disabled(): void {
        set_config('formatstringstriptags', 0);

        $mform = $this->get_quickform($this->build_form());

        $this->assertSame(PARAM_CLEANHTML, $mform->_types['name']);
    }

    /**
     * When block_playerhud is configured for the course, the HUD reward selects are added to
     * the form — the branch that never fires for a course with no HUD block at all.
     *
     * @return void
     */
    public function test_definition_includes_hud_item_selects_when_playerhud_available(): void {
        $this->skip_if_no_playerhud();
        $blockinstanceid = $this->make_hud_block($this->course);
        $this->make_hud_item($blockinstanceid, 'Gold Key');

        $mform = $this->get_quickform($this->build_form());

        $this->assertTrue($mform->elementExists('hudcorrectitem'));
        $this->assertTrue($mform->elementExists('hudretrycostitem'));
        $this->assertTrue($mform->elementExists('hudretrycostqty'));
    }

    /**
     * A stored hudcorrectitem pointing at an item that still exists, but was since disabled,
     * is kept as a selectable (labelled) option instead of silently disappearing from the
     * select — losing it would wipe the field the next time the form is saved.
     *
     * @return void
     */
    public function test_stale_hud_item_kept_as_disabled_option_when_item_still_exists(): void {
        $this->skip_if_no_playerhud();
        $blockinstanceid = $this->make_hud_block($this->course);
        $enableditemid = $this->make_hud_item($blockinstanceid, 'Gold Key', true);
        $disableditemid = $this->make_hud_item($blockinstanceid, 'Retired Badge', false);

        $mform = $this->get_quickform($this->build_form(['hudcorrectitem' => $disableditemid]));

        $select = $mform->getElement('hudcorrectitem');
        $values = array_map('intval', array_column(array_column($select->_options, 'attr'), 'value'));
        $this->assertContains($disableditemid, $values);
        $this->assertContains($enableditemid, $values);
    }

    /**
     * A stored hudcorrectitem pointing at an item that no longer exists at all (deleted) is
     * still kept as a selectable option, labelled accordingly, instead of being dropped.
     *
     * @return void
     */
    public function test_stale_hud_item_kept_as_deleted_option_when_item_no_longer_exists(): void {
        $this->skip_if_no_playerhud();
        $blockinstanceid = $this->make_hud_block($this->course);
        $this->make_hud_item($blockinstanceid, 'Gold Key');
        $deletedid = 999999;

        $mform = $this->get_quickform($this->build_form(['hudcorrectitem' => $deletedid]));

        $select = $mform->getElement('hudcorrectitem');
        $values = array_map('intval', array_column(array_column($select->_options, 'attr'), 'value'));
        $this->assertContains($deletedid, $values);
    }

    /**
     * An invalid YouTube URL is rejected.
     *
     * @return void
     */
    public function test_rejects_invalid_youtube_url(): void {
        $form = $this->build_form();

        $errors = $form->validation($this->base_submission([
            'videotype' => 'youtube',
            'videourl' => 'https://example.com/not-a-video',
        ]), []);

        $this->assertArrayHasKey('videourl', $errors);
    }

    /**
     * An invalid Vimeo URL is rejected.
     *
     * @return void
     */
    public function test_rejects_invalid_vimeo_url(): void {
        $form = $this->build_form();

        $errors = $form->validation($this->base_submission([
            'videotype' => 'vimeo',
            'videourl' => 'https://example.com/not-a-video',
        ]), []);

        $this->assertArrayHasKey('videourl', $errors);
    }

    /**
     * A HUD retry cost quantity below 1, with a cost item actually selected, is rejected.
     *
     * @return void
     */
    public function test_rejects_hud_retry_cost_qty_below_one(): void {
        $form = $this->build_form();

        $errors = $form->validation($this->base_submission([
            'hudretrycostitem' => 1,
            'hudretrycostqty' => 0,
        ]), []);

        $this->assertArrayHasKey('hudretrycostqty', $errors);
    }

    /**
     * add_completion_rules() adds both completion checkboxes and returns their element names,
     * as core's own completion machinery expects.
     *
     * @return void
     */
    public function test_add_completion_rules_adds_both_checkboxes(): void {
        $form = $this->build_form();
        $mform = $this->get_quickform($form);

        $names = $form->add_completion_rules();

        $this->assertSame(['completionallinteractions', 'completionwatchtoend'], $names);
        $this->assertTrue($mform->elementExists('completionallinteractions'));
        $this->assertTrue($mform->elementExists('completionwatchtoend'));
    }

    /**
     * completion_rule_enabled() is true whenever at least one of the two rules is checked.
     *
     * @return void
     */
    public function test_completion_rule_enabled_true_when_either_flag_set(): void {
        $form = $this->build_form();

        $this->assertTrue($form->completion_rule_enabled(['completionallinteractions' => 1, 'completionwatchtoend' => 0]));
        $this->assertTrue($form->completion_rule_enabled(['completionallinteractions' => 0, 'completionwatchtoend' => 1]));
    }

    /**
     * completion_rule_enabled() is false when neither rule is checked.
     *
     * @return void
     */
    public function test_completion_rule_enabled_false_when_neither_flag_set(): void {
        $form = $this->build_form();

        $this->assertFalse($form->completion_rule_enabled(['completionallinteractions' => 0, 'completionwatchtoend' => 0]));
    }
}
