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
 * Library functions for mod_playervideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_playervideo\local\attempt_manager;
use mod_playervideo\local\question_service;
use mod_playervideo\local\video_source;

/**
 * Tells Moodle this plugin uses a branded icon (disables purpose recolour filter).
 *
 * @return bool
 */
function mod_playervideo_is_branded(): bool {
    return true;
}

/**
 * Return the features this module supports.
 *
 * @param string $feature FEATURE_xx constant for requested feature.
 * @return mixed True if module supports feature, a purpose string for
 *     FEATURE_MOD_PURPOSE/FEATURE_MOD_OTHERPURPOSE, null if doesn't know.
 */
function playervideo_supports(string $feature): mixed {
    // FEATURE_MOD_OTHERPURPOSE only exists from Moodle 5.1 onwards (MDL-85598); this plugin
    // also targets Moodle 4.5, where referencing the undefined constant as a switch case
    // label would still be a fatal error, guard or not — checked ahead of the switch instead.
    // Lets the activity chooser list this activity under both its primary purpose (content)
    // and this secondary one (assessment, since it produces a real grade).
    if (defined('FEATURE_MOD_OTHERPURPOSE') && $feature === FEATURE_MOD_OTHERPURPOSE) {
        return MOD_PURPOSE_ASSESSMENT;
    }

    switch ($feature) {
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        default:
            return null;
    }
}

/**
 * Returns the available grading method options, keyed by their attempt_manager::GRADE_*
 * constant. Single source of truth shared by the settings form dropdown and anywhere else
 * that needs to describe the same four methods identically.
 *
 * @return array<int, string>
 */
function playervideo_get_grademethod_options(): array {
    return [
        attempt_manager::GRADE_HIGHEST => get_string('grademethod_highest', 'mod_playervideo'),
        attempt_manager::GRADE_AVERAGE => get_string('grademethod_average', 'mod_playervideo'),
        attempt_manager::GRADE_FIRST => get_string('grademethod_first', 'mod_playervideo'),
        attempt_manager::GRADE_LAST => get_string('grademethod_last', 'mod_playervideo'),
    ];
}

/**
 * Creates or updates the grade item for a playervideo instance.
 *
 * @param stdClass $instance Activity instance (must have id, course, name, grade, gradepass).
 * @param mixed $grades Grade object(s), null to update item only, or 'reset' to reset grades.
 * @return int GRADE_UPDATE_OK or error constant.
 */
function playervideo_grade_item_update(stdClass $instance, mixed $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $instance->name,
        'idnumber' => $instance->cmidnumber ?? '',
    ];

    if ((int) $instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = (float) $instance->grade;
        $params['grademin'] = 0.0;
    } else if ((int) $instance->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -(int) $instance->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    $isreset = $grades === 'reset';
    if ($isreset) {
        $params['reset'] = true;
        $grades = null;
    }

    $result = grade_update(
        'mod/playervideo',
        $instance->course,
        'mod',
        'playervideo',
        $instance->id,
        0,
        $grades,
        $params
    );

    // The core grade_update() function silently ignores a 'gradepass' key in $itemdetails:
    // its own internal allow-list (lib/gradelib.php) only lets itemname/idnumber/gradetype/
    // grademax/grademin/scaleid/multfactor/plusfactor/deleted/hidden through. The pass grade
    // has to be applied directly on the grade_item instead.
    if ($result === GRADE_UPDATE_OK && !$isreset && !empty($instance->gradepass)) {
        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'playervideo',
            'iteminstance' => $instance->id,
            'itemnumber' => 0,
            'courseid' => $instance->course,
        ]);
        if ($gradeitem && (float) $gradeitem->gradepass !== (float) $instance->gradepass) {
            $gradeitem->gradepass = (float) $instance->gradepass;
            $gradeitem->update();
        }
    }

    return $result;
}

/**
 * Updates gradebook grades for one or all users of a playervideo instance.
 *
 * @param stdClass $instance Activity instance.
 * @param int $userid User id, 0 to update all users with at least one finished attempt.
 * @return void
 */
function playervideo_update_grades(stdClass $instance, int $userid = 0): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['playervideoid' => $instance->id];
    $usersql = '';
    if ($userid > 0) {
        $usersql = ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    $userids = $DB->get_fieldset_sql(
        "SELECT DISTINCT userid FROM {playervideo_attempts} WHERE playervideoid = :playervideoid$usersql",
        $params
    );

    if (empty($userids)) {
        if ($userid > 0) {
            $grade = new stdClass();
            $grade->userid = $userid;
            $grade->rawgrade = null;
            playervideo_grade_item_update($instance, [$userid => $grade]);
        } else {
            playervideo_grade_item_update($instance);
        }
        return;
    }

    $grademethod = (int) ($instance->grademethod ?? attempt_manager::GRADE_HIGHEST);
    $finalgradesbyuser = attempt_manager::aggregate_final_grades_bulk(
        $instance->id,
        array_map('intval', $userids),
        $grademethod
    );

    $grades = [];
    foreach ($finalgradesbyuser as $uid => $finalgrade) {
        if ($finalgrade === null) {
            continue;
        }
        $grade = new stdClass();
        $grade->userid = $uid;
        $grade->rawgrade = $finalgrade;
        $grades[$uid] = $grade;
    }

    playervideo_grade_item_update($instance, $grades ?: null);
}

/**
 * Add a new playervideo instance.
 *
 * @param stdClass $data Form data.
 * @param mixed $mform The form instance, unused.
 * @return int New instance id.
 */
function playervideo_add_instance(stdClass $data, mixed $mform = null): int {
    global $DB;

    $data->gradepass = isset($data->gradepass) ? (float) $data->gradepass : 0.0;
    $data->timecreated = time();
    $data->timemodified = time();

    if ($data->videotype !== 'html5') {
        $data->videourl = $data->videourl ?? '';
    } else {
        $data->videourl = null;
    }

    $data->id = $DB->insert_record('playervideo', $data);

    if ($data->videotype === 'html5' && !empty($data->videofile)) {
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files($data->videofile, $context->id, 'mod_playervideo', 'videofile', 0, [
            'subdirs' => 0,
            'maxfiles' => 1,
        ]);
    }

    // Mirrors the videofile guard above: a caller that never submitted a real draft area at all
    // (e.g. a test fixture built directly through the generator, never through the actual form)
    // must not reach file_save_draft_area_files() with nothing meaningful to give it — passing
    // an empty/absent draft itemid made Moodle try to resolve a context for the current $USER,
    // which fails outright outside a real request (a real form submission, on the other hand,
    // always supplies a genuine draft itemid here, even for an empty filepicker).
    if (!empty($data->posterimage)) {
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files($data->posterimage, $context->id, 'mod_playervideo', 'posterimage', 0, [
            'subdirs' => 0,
            'maxfiles' => 1,
        ]);
    }

    playervideo_grade_item_update($data);

    return $data->id;
}

/**
 * Update an existing playervideo instance.
 *
 * @param stdClass $data Form data.
 * @param mixed $mform The form instance, unused.
 * @return bool True on success.
 */
function playervideo_update_instance(stdClass $data, mixed $mform = null): bool {
    global $DB;

    $data->gradepass = isset($data->gradepass) ? (float) $data->gradepass : 0.0;
    $data->id = $data->instance;
    $data->timemodified = time();

    if ($data->videotype !== 'html5') {
        $data->videourl = $data->videourl ?? '';
    } else {
        $data->videourl = null;
    }

    $result = $DB->update_record('playervideo', $data);

    if ($data->videotype === 'html5' && !empty($data->videofile)) {
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files($data->videofile, $context->id, 'mod_playervideo', 'videofile', 0, [
            'subdirs' => 0,
            'maxfiles' => 1,
        ]);
    }

    if (!empty($data->posterimage)) {
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files($data->posterimage, $context->id, 'mod_playervideo', 'posterimage', 0, [
            'subdirs' => 0,
            'maxfiles' => 1,
        ]);
    }

    playervideo_grade_item_update($data);

    return $result;
}

/**
 * Delete a playervideo instance.
 *
 * Never deletes Question Bank questions or categories referenced by the instance's
 * interactions — a question may still be in use by another activity (see
 * playervideo_questions_in_use() below).
 *
 * @param int $id Instance id.
 * @return bool True on success.
 */
function playervideo_delete_instance(int $id): bool {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $instance = $DB->get_record('playervideo', ['id' => $id], 'id, course');
    if (!$instance) {
        return false;
    }

    grade_update('mod/playervideo', $instance->course, 'mod', 'playervideo', $id, 0, null, ['deleted' => 1]);

    $DB->delete_records('playervideo_responses', ['playervideoid' => $id]);
    $DB->delete_records('playervideo_attempts', ['playervideoid' => $id]);
    // Must run before the parent interactions are deleted below — it selects its own rows by
    // interactionid, not playervideoid.
    $DB->delete_records_select(
        'playervideo_poll_options',
        'interactionid IN (SELECT id FROM {playervideo_interactions} WHERE playervideoid = :playervideoid)',
        ['playervideoid' => $id]
    );
    $DB->delete_records('playervideo_interactions', ['playervideoid' => $id]);
    $DB->delete_records('playervideo_captions', ['playervideoid' => $id]);
    $DB->delete_records('playervideo_progress', ['playervideoid' => $id]);
    $DB->delete_records('playervideo_disummaries', ['playervideoid' => $id]);
    $DB->delete_records('playervideo', ['id' => $id]);

    return true;
}

/**
 * Populates the course module info object with custom completion rule data, and — when the
 * instance is pinned to the course page — a plain inline embed of the video itself.
 *
 * @param stdClass $coursemodule The raw course_modules row (id, instance, completion, …).
 * @return cached_cm_info|false A populated info object, or false on failure.
 */
function playervideo_get_coursemodule_info(stdClass $coursemodule): cached_cm_info|false {
    global $DB;

    $fields = 'id, name, videotype, videourl, showinline, completionallinteractions, completionwatchtoend';
    $instance = $DB->get_record('playervideo', ['id' => $coursemodule->instance], $fields);
    if (!$instance) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionallinteractions'] =
            (int) $instance->completionallinteractions;
        $info->customdata['customcompletionrules']['completionwatchtoend'] =
            (int) $instance->completionwatchtoend;
    }

    return $info;
}

/**
 * Renders the video inline on the course page when the teacher enabled "fixar na página do
 * curso" — a purely presentational decision: the course_modules/grade_item stay intact, so
 * the activity keeps its grade and attempts normally. Only a plain embed, no interactive
 * timeline — that only exists on the full activity page (view.php).
 *
 * Deliberately never calls $cm->set_no_view_link(): that nulls $cm->url globally (not just
 * on the course page card), which silently made the activity unreachable everywhere else on
 * the site — the "Atividades" listing, recent activity, calendar, any "next/previous
 * activity" navigation — with no way back to the interactive page at all. An explicit link is
 * also rendered directly inside the embed's own content, so the course page itself always has
 * one too, regardless of how the course format's generic name-as-link rendering behaves for a
 * custom cmlist item.
 *
 * @param cm_info $cm Course module info.
 * @return void
 */
function playervideo_cm_info_dynamic(cm_info $cm): void {
    global $DB, $PAGE;

    $instance = $DB->get_record('playervideo', ['id' => $cm->instance], 'videotype, videourl, showinline');
    if (!$instance || empty($instance->showinline)) {
        return;
    }

    $context = context_module::instance($cm->id);
    $fileurl = null;
    if ($instance->videotype === 'html5') {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_playervideo', 'videofile', 0, 'filename', false);
        $file = reset($files);
        if ($file) {
            $fileurl = moodle_url::make_pluginfile_url(
                $context->id,
                'mod_playervideo',
                'videofile',
                0,
                $file->get_filepath(),
                $file->get_filename()
            );
        }
    }

    $embedurl = video_source::get_embed_url($instance->videotype, $instance->videourl, $fileurl);
    if ($embedurl === null) {
        return;
    }

    /*
     * cm_info_dynamic() is invoked lazily, the first time any code touches this cm's
     * dynamic properties — sometimes navigation building well after the page <head> has
     * already been sent (confirmed live: global_navigation::generate_sections_and_
     * activities() -> cm_info::get_name() can trigger this hook post-head on some
     * requests). $PAGE->requires->css() throws a fatal coding_exception once the head is
     * out, so it must never be called unconditionally here — skipping it in that rare
     * case is a small styling gap on the inline embed, not a fatal error for the page.
     * $PAGE->headerprinted tracks a different, later state (moodle_page::STATE_IN_BODY)
     * and is not a reliable proxy for this — the actual flag page_requirements_manager
     * itself checks is is_head_done(), which is what must be asked here.
     */
    if (!$PAGE->requires->is_head_done()) {
        $PAGE->requires->css('/mod/playervideo/styles.css');
    }

    if ($instance->videotype === 'html5') {
        $html = html_writer::tag('video', '', [
            'src' => $embedurl->out(false),
            // A boolean HTML attribute's value must be empty or repeat the attribute's own
            // name — html_writer::attribute() would otherwise render true as the literal,
            // HTML5-invalid string "1" (fails the mustache linter's HTML validation step).
            'controls' => 'controls',
            'class' => 'ph-video-embed',
        ]);
    } else {
        $html = html_writer::tag('iframe', '', [
            'src' => $embedurl->out(false),
            'class' => 'ph-video-embed',
            'allowfullscreen' => 'allowfullscreen',
            'allow' => 'autoplay; fullscreen',
        ]);
    }

    $viewurl = new moodle_url('/mod/playervideo/view.php', ['id' => $cm->id]);
    $html .= html_writer::div(
        html_writer::link($viewurl, get_string('viewfullactivity', 'mod_playervideo')),
        'playervideo-inline-viewlink'
    );

    $cm->set_content($html);
    $cm->set_custom_cmlist_item(true);
}

/**
 * Serves a playervideo instance's uploaded video file or cover image.
 *
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context Module context.
 * @param string $filearea File area.
 * @param array $args Extra arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional options.
 * @return bool False if the file was not found or is not servable.
 */
function playervideo_pluginfile(
    stdClass $course,
    stdClass $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if (!has_capability('mod/playervideo:view', $context)) {
        return false;
    }

    if ($filearea !== 'videofile' && $filearea !== 'posterimage') {
        return false;
    }

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_playervideo', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
    return true;
}

/**
 * Checks whether the given question ids are referenced by any PlayerVideo instance.
 *
 * Discovered automatically by core via get_plugins_with_function('questions_in_use')
 * (lib/questionlib.php), called before letting a teacher delete a question from the bank —
 * this is what protects playervideo_responses from ever pointing at a deleted question,
 * since the plugin does not use the full Question Usage API.
 *
 * @param int[] $questionids Question ids to check.
 * @return bool True if at least one of them is referenced by this plugin.
 */
function playervideo_questions_in_use(array $questionids): bool {
    return question_service::has_questions_in_use($questionids);
}

/**
 * Adds "Manage interactions" to the activity administration menu, for teachers.
 *
 * Discovered automatically by core (settings_navigation::load_module_settings(), which calls
 * "{$cm->modname}_extend_settings_navigation" by name) — without this, interactions.php (the
 * timeline editor) has no link pointing to it anywhere in the UI.
 *
 * @param settings_navigation $settings The settings navigation object.
 * @param navigation_node $playervideonode The node to add children to.
 * @return void
 */
function playervideo_extend_settings_navigation(
    settings_navigation $settings,
    navigation_node $playervideonode
): void {
    $cm = $settings->get_page()->cm;

    if (!has_capability('mod/playervideo:manage', $cm->context)) {
        return;
    }

    $url = new moodle_url('/mod/playervideo/interactions.php', ['id' => $cm->id]);
    $playervideonode->add(
        get_string('manageinteractions', 'mod_playervideo'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'mod_playervideo_manageinteractions'
    );

    // Correction queue + analytics link is gated on either capability, since a custom role
    // could plausibly grant only one of the two (see report.php's own per-section WS checks for
    // the real enforcement; this only controls whether the link itself appears).
    if (
        has_capability('mod/playervideo:reviewresponses', $cm->context)
        || has_capability('mod/playervideo:viewreports', $cm->context)
    ) {
        $reporturl = new moodle_url('/mod/playervideo/report.php', ['id' => $cm->id]);
        $playervideonode->add(
            get_string('viewreport', 'mod_playervideo'),
            $reporturl,
            navigation_node::TYPE_SETTING,
            null,
            'mod_playervideo_viewreport'
        );
    }
}
