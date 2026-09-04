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
 * Form definition for mod_playervideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../../course/moodleform_mod.php');
require_once(__DIR__ . '/lib.php');

use mod_playervideo\local\hud_service;
use mod_playervideo\local\video_source;

/**
 * Activity settings form for PlayerVideo.
 */
class mod_playervideo_mod_form extends moodleform_mod {
    /**
     * Defines forms elements.
     *
     * @return void
     */
    public function definition(): void {
        global $CFG, $COURSE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'videosourceheader', get_string('videosource', 'mod_playervideo'));
        $mform->setExpanded('videosourceheader');

        $mform->addElement('select', 'videotype', get_string('videosource', 'mod_playervideo'), [
            'youtube' => get_string('videotype_youtube', 'mod_playervideo'),
            'vimeo' => get_string('videotype_vimeo', 'mod_playervideo'),
            'html5' => get_string('videotype_html5', 'mod_playervideo'),
        ]);
        $mform->setType('videotype', PARAM_ALPHA);
        $mform->setDefault('videotype', 'youtube');

        $mform->addElement('text', 'videourl', get_string('videourl', 'mod_playervideo'), ['size' => '64']);
        $mform->setType('videourl', PARAM_URL);
        $mform->hideIf('videourl', 'videotype', 'eq', 'html5');

        $mform->addElement('filepicker', 'videofile', get_string('videofile', 'mod_playervideo'), null, [
            'maxbytes' => $COURSE->maxbytes,
            'accepted_types' => ['video'],
        ]);
        $mform->hideIf('videofile', 'videotype', 'neq', 'html5');

        $mform->addElement('filepicker', 'posterimage', get_string('posterimage', 'mod_playervideo'), null, [
            'maxbytes' => $COURSE->maxbytes,
            'accepted_types' => ['web_image'],
        ]);

        $mform->addElement('text', 'posterdescription', get_string('posterdescription', 'mod_playervideo'), ['size' => '64']);
        $mform->setType('posterdescription', PARAM_TEXT);

        $mform->addElement('advcheckbox', 'showinline', get_string('fixinline', 'mod_playervideo'));
        $mform->setType('showinline', PARAM_INT);
        $mform->setDefault('showinline', 0);

        $mform->addElement('header', 'attemptsheader', get_string('attemptsheader', 'mod_playervideo'));
        $mform->setExpanded('attemptsheader');

        $maxattemptsoptions = [0 => get_string('maxattempts_unlimited', 'mod_playervideo')];
        for ($i = 1; $i <= 10; $i++) {
            $maxattemptsoptions[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mod_playervideo'), $maxattemptsoptions);
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);

        $mform->addElement('advcheckbox', 'allowseekahead', get_string('allowseekahead', 'mod_playervideo'));
        $mform->setType('allowseekahead', PARAM_INT);
        $mform->setDefault('allowseekahead', 0);

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);

        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', 'mod_playervideo'),
            playervideo_get_grademethod_options()
        );
        $mform->setType('grademethod', PARAM_INT);
        $mform->setDefault('grademethod', \mod_playervideo\local\attempt_manager::GRADE_HIGHEST);
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $hudblockid = null;
        if (hud_service::is_available_for_course((int) $COURSE->id)) {
            $hudblockid = hud_service::get_block_instance_id((int) $COURSE->id);
        }

        if ($hudblockid !== null) {
            $huditems = hud_service::get_items_for_block($hudblockid);
            $itemoptions = [0 => get_string('hud_noitem', 'mod_playervideo')];
            foreach ($huditems as $item) {
                $itemoptions[$item->id] = format_string($item->name);
            }

            $mform->addElement('header', 'hudheader', get_string('hud_header', 'mod_playervideo'));

            $mform->addElement(
                'select',
                'hudcorrectitem',
                get_string('hudcorrectitem', 'mod_playervideo'),
                $this->add_stale_hud_item_option($itemoptions, $hudblockid, 'hudcorrectitem')
            );
            $mform->setType('hudcorrectitem', PARAM_INT);
            $mform->setDefault('hudcorrectitem', 0);

            $mform->addElement(
                'select',
                'hudretrycostitem',
                get_string('hudretrycostitem', 'mod_playervideo'),
                $this->add_stale_hud_item_option($itemoptions, $hudblockid, 'hudretrycostitem')
            );
            $mform->setType('hudretrycostitem', PARAM_INT);
            $mform->setDefault('hudretrycostitem', 0);

            $mform->addElement('text', 'hudretrycostqty', get_string('hudretrycostqty', 'mod_playervideo'));
            $mform->setType('hudretrycostqty', PARAM_INT);
            $mform->setDefault('hudretrycostqty', 1);
            $mform->addRule('hudretrycostqty', null, 'numeric', null, 'client');
            $mform->hideIf('hudretrycostqty', 'hudretrycostitem', 'eq', 0);
        } else if (hud_service::is_installed()) {
            $mform->addElement('header', 'hudheader', get_string('hud_header', 'mod_playervideo'));
            $mform->addElement(
                'static',
                'hudnotincourse',
                '',
                html_writer::div(get_string('hud_notincourse', 'mod_playervideo'), 'alert alert-info py-2 mb-0')
            );
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Adds the currently stored item as an extra select option when it fell out of the
     * enabled-items list (disabled or deleted after being configured).
     *
     * Without this, saving the form for any unrelated reason would silently wipe the field
     * back to "no item" the moment the browser submits whatever option happens to render as
     * selected, since a <select> with no matching option cannot preserve the real value.
     *
     * @param array $options Base options (enabled items only), keyed by item id.
     * @param int $blockinstanceid Block instance ID the stored value must belong to.
     * @param string $field Field name to read the stored value from $this->current.
     * @return array
     */
    private function add_stale_hud_item_option(array $options, int $blockinstanceid, string $field): array {
        $storedid = (int) ($this->current->{$field} ?? 0);
        if ($storedid <= 0 || isset($options[$storedid])) {
            return $options;
        }

        $itemname = hud_service::get_item_name($blockinstanceid, $storedid);
        $options[$storedid] = ($itemname !== '')
            ? get_string('hud_item_disabled', 'mod_playervideo', $itemname)
            : get_string('hud_item_deleted', 'mod_playervideo');

        return $options;
    }

    /**
     * Custom validation for PlayerVideo settings.
     *
     * @param array $data Form data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if ($data['videotype'] === 'youtube' && video_source::get_youtube_id($data['videourl']) === null) {
            $errors['videourl'] = get_string('error_videourl', 'mod_playervideo');
        } else if ($data['videotype'] === 'vimeo' && video_source::get_vimeo_id($data['videourl']) === null) {
            $errors['videourl'] = get_string('error_videourl', 'mod_playervideo');
        }

        if (!empty($data['hudretrycostitem']) && (int) $data['hudretrycostqty'] < 1) {
            $errors['hudretrycostqty'] = get_string('error_hud_cost_qty', 'mod_playervideo');
        }

        // A description is only meaningful — and only checked — when a cover image was
        // actually uploaded; an activity with no image at all needs no description for it.
        $posterinfo = file_get_draft_area_info((int) $data['posterimage']);
        if ($posterinfo['filecount'] > 0 && trim($data['posterdescription']) === '') {
            $errors['posterdescription'] = get_string('error_posterdescriptionrequired', 'mod_playervideo');
        }

        return $errors;
    }

    /**
     * Preloads the videofile/posterimage draft file areas so an already-uploaded file shows up
     * in its filepicker when reopening the settings form to edit them.
     *
     * moodleform_mod's own automatic preprocessing only ever covers the intro editor and the
     * standard grade elements — never a plugin's own custom file element. Without this
     * override, videofile's filepicker (present since Fase 2, well before posterimage) always
     * rendered empty on edit, and saving the form for any unrelated reason (e.g. renaming the
     * activity) would silently wipe the previously uploaded video: file_save_draft_area_files()
     * in lib.php would synchronise the stored file to match a fresh, empty auto-generated draft
     * area, since nothing had ever told it the real one already existed. Found and fixed
     * together with posterimage's own identical need for this same override.
     *
     * @param array $defaultvalues Reference to the array of default values.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        if (empty($this->current->id)) {
            return;
        }

        foreach (['videofile', 'posterimage'] as $filearea) {
            $defaultvalues[$filearea] = file_get_submitted_draft_itemid($filearea);
            file_prepare_draft_area(
                $defaultvalues[$filearea],
                $this->context->id,
                'mod_playervideo',
                $filearea,
                0,
                ['subdirs' => 0, 'maxfiles' => 1]
            );
        }
    }

    /**
     * Adds custom completion rules to Moodle completion section.
     *
     * @return array
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $mform->addElement(
            'checkbox',
            'completionallinteractions',
            '',
            get_string('completiondetail:allinteractions', 'mod_playervideo')
        );
        $mform->setType('completionallinteractions', PARAM_INT);

        $mform->addElement(
            'checkbox',
            'completionwatchtoend',
            '',
            get_string('completiondetail:watchtoend', 'mod_playervideo')
        );
        $mform->setType('completionwatchtoend', PARAM_INT);

        return ['completionallinteractions', 'completionwatchtoend'];
    }

    /**
     * Returns whether at least one completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data['completionallinteractions']) || !empty($data['completionwatchtoend']);
    }
}
