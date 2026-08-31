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
 * English strings for PlayerVideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['addanswer'] = 'Add answer';
$string['addinteraction'] = 'Add interaction';
$string['allowseekahead'] = 'Allow seeking ahead';
$string['attemptnumber'] = 'Attempt {$a}';
$string['attemptsallowed'] = 'Attempts allowed';
$string['attemptsheader'] = 'Attempts & playback';
$string['attemptsummaryheader'] = 'Attempt summary';
$string['backtoactivity'] = 'Back to the activity';
$string['blindmode'] = 'Text-only mode';
$string['cannotattempt'] = 'You don\'t have permission to attempt this activity.';
$string['captioneditor'] = 'Caption editor';
$string['chooseattempttoreview'] = 'Choose an attempt to review';
$string['completiondetail:allinteractions'] = 'Student must answer/view all interactions';
$string['completiondetail:watchtoend'] = 'Student must watch until the end';
$string['confirmanswer'] = 'Confirm answer';
$string['confirmdeleteinteraction'] = 'Delete this interaction? This cannot be undone.';
$string['continuewatching'] = 'Continue';
$string['correctanswer'] = 'Correct answer';
$string['correctionpending'] = 'Pending correction';
$string['createhere'] = 'Create here';
$string['createquestion'] = 'Create question';
$string['disummary'] = 'Easy-read summary';
$string['disummary_pending'] = 'Summary pending teacher review';
$string['error_attemptnotinprogress'] = 'This attempt is no longer in progress.';
$string['error_hud_cost_qty'] = 'Enter a quantity of at least 1';
$string['error_insufficienthuditems'] = 'Not enough PlayerHUD items to start a new attempt.';
$string['error_interactionalreadyanswered'] = 'This interaction was already answered in this attempt.';
$string['error_interactionhasresponses'] = 'This interaction already has student responses and cannot be deleted.';
$string['error_interactionnotfound'] = 'Interaction not found.';
$string['error_invalidanswer'] = 'Invalid answer.';
$string['error_invalidinteractiontype'] = 'Invalid interaction type.';
$string['error_invalidqtype'] = 'Invalid question type.';
$string['error_invalidsegments'] = 'Invalid watched segments data.';
$string['error_invalidtrim'] = 'Invalid trim window.';
$string['error_noattemptsleft'] = 'No attempts left for this activity.';
$string['error_nocorrectanswer'] = 'Mark at least one answer as correct.';
$string['error_noembed'] = 'This video\'s source could not be resolved — check the URL in the activity settings.';
$string['error_noquestionselected'] = 'Pick a question from the bank or create one first.';
$string['error_notenoughanswers'] = 'Enter at least two answers.';
$string['error_notetextrequired'] = 'Enter the note text.';
$string['error_notyourattempt'] = 'This attempt does not belong to you.';
$string['error_onlyonecorrectanswer'] = 'Only one answer can be correct for a single-answer question.';
$string['error_questionnotfound'] = 'Question not found.';
$string['error_questiontextrequired'] = 'Enter the question text.';
$string['error_responsetextrequired'] = 'Enter your response.';
$string['error_seekaheadblocked'] = 'You cannot skip ahead of what you have already watched.';
$string['error_videourl'] = 'Enter a valid YouTube/Vimeo URL';
$string['false'] = 'False';
$string['finishattempt'] = 'Finish attempt now';
$string['fixinline'] = 'Pin to course page';
$string['grademethod'] = 'Grading method';
$string['grademethod_average'] = 'Average grade';
$string['grademethod_first'] = 'First attempt';
$string['grademethod_highest'] = 'Highest grade';
$string['grademethod_last'] = 'Last attempt';
$string['hud_header'] = 'PlayerHUD integration';
$string['hud_item_deleted'] = 'Deleted item (please reconfigure)';
$string['hud_item_disabled'] = '{$a} (disabled)';
$string['hud_noitem'] = 'None';
$string['hud_notincourse'] = 'PlayerHUD integration will appear here once the PlayerHUD block is added to this course.';
$string['hud_notinstalled_desc'] = 'The block_playerhud plugin is not installed on this site. Install it, then add the PlayerHUD block to a course, to let teachers reward students with items for correct answers.';
$string['hud_notinstalled_heading'] = 'PlayerHUD integration';
$string['hud_outdated_desc'] = 'The block_playerhud plugin is installed, but on a version older than v1.7.1, which this integration requires. Update block_playerhud to let teachers reward students with items for correct answers.';
$string['hud_outdated_heading'] = 'PlayerHUD integration';
$string['hudcorrectitem'] = 'PlayerHUD item per correct answer';
$string['hudretrycostitem'] = 'PlayerHUD item charged on retry';
$string['hudretrycostqty'] = 'Retry cost quantity';
$string['interactions'] = 'Interactions';
$string['interactiontype'] = 'Type';
$string['interactionweight'] = 'Weight';
$string['introbody'] = 'This video pauses at points the teacher marked to show a question or a note. Answer or read it, then the video continues. Depending on how the teacher set up this activity, you may be able to try again.';
$string['introtitle'] = 'How this activity works';
$string['manageinteractions'] = 'Manage interactions';
$string['maxattempts'] = 'Maximum attempts';
$string['maxattempts_unlimited'] = 'No limit';
$string['modulename'] = 'PlayerVideo';
$string['modulename_help'] = 'The PlayerVideo activity plays a video (YouTube, Vimeo or an uploaded file) and pauses it at marked points to show a question or a note, with automatic grading of multiple choice questions, AI-assisted correction of open questions, and playback progress tracking.';
$string['modulenameplural'] = 'PlayerVideos';
$string['newattempt'] = 'New attempt';
$string['nointeractions'] = 'No interactions yet.';
$string['notetext'] = 'Note text';
$string['pendingcorrectionnotice'] = 'Your open-question answers are pending the teacher\'s review; the grade for this attempt will appear once they are marked.';
$string['playervideo:addinstance'] = 'Add a new PlayerVideo activity';
$string['playervideo:attempt'] = 'Attempt a PlayerVideo activity';
$string['playervideo:manage'] = 'Manage interactions, captions and questions';
$string['playervideo:reviewresponses'] = 'Review open-question responses';
$string['playervideo:view'] = 'View a PlayerVideo activity';
$string['playervideo:viewreports'] = 'View PlayerVideo reports';
$string['pluginadministration'] = 'PlayerVideo administration';
$string['pluginname'] = 'PlayerVideo';
$string['preview'] = 'Preview';
$string['pullfrombank'] = 'Pull from bank';
$string['qtypemultichoice'] = 'Multiple choice';
$string['qtypetruefalse'] = 'True/False';
$string['questioncreated'] = 'Question created.';
$string['questionsettings'] = 'Question settings';
$string['questiontext'] = 'Question text';
$string['questiontype'] = 'Question type';
$string['reportheader'] = 'Analytics';
$string['result_correct'] = 'Correct';
$string['result_incorrect'] = 'Incorrect';
$string['result_notreached'] = 'Not reached';
$string['result_pending'] = 'Pending correction';
$string['result_viewed'] = 'Viewed';
$string['reviewattempt'] = 'Review attempt';
$string['reviewingattempt'] = 'Reviewing attempt {$a}';
$string['reviewpreviousattempts'] = 'Review a previous attempt';
$string['searchquestions'] = 'Search questions';
$string['singleanswer'] = 'Single answer';
$string['startattempt'] = 'Start';
$string['timestamp'] = 'Timestamp (seconds)';
$string['transcriptmode'] = 'Switch to text-only mode';
$string['trimend'] = 'Video end (seconds)';
$string['trimheader'] = 'Playback window (trim)';
$string['trimsaved'] = 'Trim window saved.';
$string['trimstart'] = 'Video start (seconds)';
$string['true'] = 'True';
$string['typenote'] = 'Note';
$string['typequestion'] = 'Question';
$string['videofile'] = 'Video file';
$string['videosource'] = 'Video source';
$string['videotype_html5'] = 'Upload';
$string['videotype_vimeo'] = 'Vimeo';
$string['videotype_youtube'] = 'YouTube';
$string['videourl'] = 'Video URL';
$string['yourgrade'] = 'Your grade: {$a}';
$string['yourresponse'] = 'Your response';
