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

$string['accept'] = 'Accept';
$string['actions'] = 'Actions';
$string['addanswer'] = 'Add answer';
$string['addcaptionlanguage'] = 'Add language';
$string['addhere'] = 'Add here';
$string['addinteraction'] = 'Add interaction';
$string['addmarkerat'] = 'Add marker at {$a}';
$string['addpolloption'] = 'Add option';
$string['aicontext'] = 'What is happening in the video at this point? (optional)';
$string['aiusage_batch'] = 'Generate questions from transcript (PlayerVideo)';
$string['aiusage_question'] = 'Generate question (PlayerVideo)';
$string['allmarkers'] = 'All markers';
$string['allowseekahead'] = 'Allow seeking ahead';
$string['answercounthint'] = '{$a->count} of {$a->max} answers';
$string['attemptnumber'] = 'Attempt {$a}';
$string['attemptsallowed'] = 'Attempts allowed';
$string['attemptsheader'] = 'Attempts & playback';
$string['attemptsummaryheader'] = 'Attempt summary';
$string['backtoactivity'] = 'Back to the activity';
$string['batchcount'] = 'Number of questions';
$string['blindmode'] = 'Text-only mode';
$string['cannotattempt'] = 'You don\'t have permission to attempt this activity.';
$string['captioncontent'] = 'Caption content';
$string['captiondeleted'] = 'Caption deleted.';
$string['captioneditor'] = 'Caption editor';
$string['captionlanguage'] = 'Language';
$string['captionsaved'] = 'Caption saved.';
$string['chooseattempttoreview'] = 'Choose an attempt to review';
$string['completiondetail:allinteractions'] = 'Student must answer/view all interactions';
$string['completiondetail:watchtoend'] = 'Student must watch until the end';
$string['confirmanswer'] = 'Confirm answer';
$string['confirmdeletecaption'] = 'Delete the "{$a}" caption?';
$string['confirmdeleteinteraction'] = 'Delete this interaction? This cannot be undone.';
$string['confirmoverwritecaption'] = 'A manual caption already exists for "{$a}". Overwrite it?';
$string['continuewatching'] = 'Continue';
$string['correctanswer'] = 'Correct answer';
$string['correctionpending'] = 'Pending correction';
$string['createhere'] = 'Create here';
$string['discard'] = 'Discard';
$string['disummary'] = 'Easy-read summary';
$string['disummary_pending'] = 'Summary pending teacher review';
$string['editmarkerat'] = 'Edit marker at {$a}';
$string['error_aigenerate'] = 'The AI could not generate a question right now. Try again in a moment.';
$string['error_aiinvalidresponse'] = 'The AI returned an unexpected response. Try again.';
$string['error_attemptnotinprogress'] = 'This attempt is no longer in progress.';
$string['error_captioncontentrequired'] = 'Caption content is required.';
$string['error_captionnotfound'] = 'Caption not found.';
$string['error_hud_cost_qty'] = 'Enter a quantity of at least 1';
$string['error_insufficienthuditems'] = 'Not enough PlayerHUD items to start a new attempt.';
$string['error_interactionalreadyanswered'] = 'This interaction was already answered in this attempt.';
$string['error_interactionhasresponses'] = 'This interaction already has student responses and cannot be deleted.';
$string['error_interactionnotfound'] = 'Interaction not found.';
$string['error_invalidanswer'] = 'Invalid answer.';
$string['error_invalidinteractiontype'] = 'Invalid interaction type.';
$string['error_invalidlang'] = 'Invalid language code.';
$string['error_invalidpolloption'] = 'Invalid poll option.';
$string['error_invalidpolloptioncount'] = 'A poll needs between 2 and 6 options.';
$string['error_invalidqtype'] = 'Invalid question type.';
$string['error_invalidsegments'] = 'Invalid watched segments data.';
$string['error_invalidtrim'] = 'Invalid trim window.';
$string['error_noaisource'] = 'No AI source is configured for this site.';
$string['error_noattemptsleft'] = 'No attempts left for this activity.';
$string['error_nocorrectanswer'] = 'Mark at least one answer as correct.';
$string['error_noembed'] = 'This video\'s source could not be resolved — check the URL in the activity settings.';
$string['error_noquestionselected'] = 'Pick a question from the bank or create one first.';
$string['error_notenoughanswers'] = 'Enter at least two answers.';
$string['error_notetextrequired'] = 'Enter the note text.';
$string['error_notyourattempt'] = 'This attempt does not belong to you.';
$string['error_onlyonecorrectanswer'] = 'Only one answer can be correct for a single-answer question.';
$string['error_pollhasvotes'] = 'This poll already has votes; its options can no longer be changed.';
$string['error_questionnotfound'] = 'Question not found.';
$string['error_questiontextrequired'] = 'Enter the question text.';
$string['error_responsetextrequired'] = 'Enter your response.';
$string['error_seekaheadblocked'] = 'You cannot skip ahead of what you have already watched.';
$string['error_timestamprequired'] = 'Enter the video timestamp first.';
$string['error_transcriptrequired'] = 'Paste a transcript first.';
$string['error_videourl'] = 'Enter a valid YouTube/Vimeo URL';
$string['false'] = 'False';
$string['finishattempt'] = 'Finish attempt now';
$string['fixinline'] = 'Pin to course page';
$string['generate'] = 'Generate';
$string['generatebatch'] = 'Generate from transcript';
$string['generatewithai'] = 'Generate with AI';
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
$string['markcorrect'] = 'Mark as correct';
$string['markincorrect'] = 'Mark as incorrect';
$string['maxattempts'] = 'Maximum attempts';
$string['maxattempts_unlimited'] = 'No limit';
$string['modulename'] = 'PlayerVideo';
$string['modulename_help'] = 'The PlayerVideo activity plays a video (YouTube, Vimeo or an uploaded file) and pauses it at marked points to show a question or a note, with automatic grading of multiple choice questions, AI-assisted correction of open questions, and playback progress tracking.';
$string['modulenameplural'] = 'PlayerVideos';
$string['newattempt'] = 'New attempt';
$string['newlanguagecode'] = 'New language code (e.g. en, pt-br)';
$string['nocandidates'] = 'No questions could be generated from this transcript.';
$string['nocaptionsyet'] = 'No captions yet.';
$string['nointeractions'] = 'No interactions yet.';
$string['noplayervideos'] = 'No PlayerVideo activities in this course.';
$string['notedescription'] = 'A text that pauses the video — no right or wrong answer.';
$string['notetext'] = 'Note text';
$string['pastecaptioncontenthint'] = 'Paste real VTT, or one "time text" entry per line (e.g. "0:45 Plants use sunlight.")';
$string['pastetranscript'] = 'Paste the transcript, one timestamped line per entry';
$string['pause'] = 'Pause';
$string['pendingcorrectionnotice'] = 'Your open-question answers are pending the teacher\'s review; the grade for this attempt will appear once they are marked.';
$string['play'] = 'Play';
$string['playervideo:addinstance'] = 'Add a new PlayerVideo activity';
$string['playervideo:attempt'] = 'Attempt a PlayerVideo activity';
$string['playervideo:manage'] = 'Manage interactions, captions and questions';
$string['playervideo:reviewresponses'] = 'Review open-question responses';
$string['playervideo:view'] = 'View a PlayerVideo activity';
$string['playervideo:viewreports'] = 'View PlayerVideo reports';
$string['pluginadministration'] = 'PlayerVideo administration';
$string['pluginname'] = 'PlayerVideo';
$string['polldescription'] = 'Students pick one option and see how the class voted.';
$string['pollprompt'] = 'Poll question';
$string['preview'] = 'Preview';
$string['privacy:attempts'] = 'Attempts';
$string['privacy:metadata:playervideo_attempts'] = 'One record per attempt a student makes at the activity.';
$string['privacy:metadata:playervideo_attempts:attemptnumber'] = 'The ordinal (1st, 2nd, 3rd...) of this attempt by this student.';
$string['privacy:metadata:playervideo_attempts:grade'] = 'The grade earned in this attempt, once finished.';
$string['privacy:metadata:playervideo_attempts:hudretrycharged'] = 'Whether a PlayerHUD item was already charged for this attempt as a retry cost.';
$string['privacy:metadata:playervideo_attempts:playervideoid'] = 'The id of the PlayerVideo activity this attempt belongs to.';
$string['privacy:metadata:playervideo_attempts:status'] = 'The attempt\'s status (in progress, pending correction, or finished).';
$string['privacy:metadata:playervideo_attempts:timecreated'] = 'The time this record was created.';
$string['privacy:metadata:playervideo_attempts:timefinish'] = 'The time this attempt finished.';
$string['privacy:metadata:playervideo_attempts:timemodified'] = 'The time this record was last modified.';
$string['privacy:metadata:playervideo_attempts:timestart'] = 'The time this attempt started.';
$string['privacy:metadata:playervideo_attempts:userid'] = 'The id of the user who made this attempt.';
$string['privacy:metadata:playervideo_progress'] = 'How much of the video each student has watched, to resume playback and enforce the anti-skip rule.';
$string['privacy:metadata:playervideo_progress:lastposition'] = 'The video position, in seconds, where the student left off.';
$string['privacy:metadata:playervideo_progress:playervideoid'] = 'The id of the PlayerVideo activity this progress belongs to.';
$string['privacy:metadata:playervideo_progress:segments'] = 'The ranges of the video, in seconds, the student has actually watched.';
$string['privacy:metadata:playervideo_progress:timecreated'] = 'The time this record was created.';
$string['privacy:metadata:playervideo_progress:timemodified'] = 'The time this record was last modified.';
$string['privacy:metadata:playervideo_progress:userid'] = 'The id of the user this progress belongs to.';
$string['privacy:metadata:playervideo_progress:watchedpct'] = 'The percentage of the video actually watched.';
$string['privacy:metadata:playervideo_progress:watchedtoend'] = 'Whether the video\'s native ended event has fired for this student.';
$string['privacy:metadata:playervideo_responses'] = 'One record per student response to a timeline interaction (question, note, or poll), within one attempt.';
$string['privacy:metadata:playervideo_responses:aifeedback'] = 'The AI-suggested feedback for this response, when applicable.';
$string['privacy:metadata:playervideo_responses:aigrade'] = 'The AI-suggested grade for this response, when applicable.';
$string['privacy:metadata:playervideo_responses:answerid'] = 'The id of the multiple-choice answer selected, when applicable.';
$string['privacy:metadata:playervideo_responses:attemptid'] = 'The id of the attempt this response belongs to.';
$string['privacy:metadata:playervideo_responses:hudrewarded'] = 'Whether a PlayerHUD item was already granted for this response.';
$string['privacy:metadata:playervideo_responses:interactionid'] = 'The id of the timeline interaction being responded to.';
$string['privacy:metadata:playervideo_responses:iscorrect'] = 'Whether the response was marked correct, for an automatically graded question.';
$string['privacy:metadata:playervideo_responses:playervideoid'] = 'The id of the PlayerVideo activity this response belongs to.';
$string['privacy:metadata:playervideo_responses:polloptionid'] = 'The id of the poll option selected, when applicable.';
$string['privacy:metadata:playervideo_responses:questionid'] = 'The id of the Question Bank question this response answers, when applicable.';
$string['privacy:metadata:playervideo_responses:responsetext'] = 'The free-text response given, for an open question.';
$string['privacy:metadata:playervideo_responses:status'] = 'The response\'s status (answered, viewed, voted, pending AI, pending review, or graded).';
$string['privacy:metadata:playervideo_responses:teacherfeedback'] = 'The teacher\'s feedback for this response, when applicable.';
$string['privacy:metadata:playervideo_responses:teachergrade'] = 'The teacher-confirmed grade for this response, when applicable.';
$string['privacy:metadata:playervideo_responses:timecreated'] = 'The time this record was created.';
$string['privacy:metadata:playervideo_responses:timemodified'] = 'The time this record was last modified.';
$string['privacy:metadata:playervideo_responses:userid'] = 'The id of the user who gave this response.';
$string['privacy:metadata:preference:seenintro'] = 'Whether the user has already seen the automatic introduction to how PlayerVideo activities work.';
$string['privacy:progress'] = 'Playback progress';
$string['privacy:responses'] = 'Responses';
$string['pullfrombank'] = 'Pull from bank';
$string['qtypeessay'] = 'Open question';
$string['qtypemix'] = 'Mix (AI decides)';
$string['qtypemultichoice'] = 'Multiple choice';
$string['qtypetruefalse'] = 'True/False';
$string['questioncreatedandadded'] = 'Question created and added to the timeline.';
$string['questiondescription'] = 'Pull an existing question from the bank, or create one here.';
$string['questiongeneratedandadded'] = 'Question generated and added.';
$string['questionsettings'] = 'Question settings';
$string['questiontext'] = 'Question text';
$string['questiontype'] = 'Question type';
$string['removealternative'] = 'Remove alternative';
$string['removepolloption'] = 'Remove option';
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
$string['selectedquestionhint'] = 'Selected — press Save below to add it to the timeline.';
$string['singleanswer'] = 'Single answer';
$string['singleanswerhint'] = 'Turn off to allow more than one correct answer';
$string['startattempt'] = 'Start';
$string['subtitles'] = 'Subtitles';
$string['subtitlesoff'] = 'Subtitles off';
$string['timelinehint'] = 'Drag the grey handles to trim the playback window; click anywhere else on the bar to add a marker there.';
$string['timelinelabel'] = 'Video timeline';
$string['timestamp'] = 'Timestamp (seconds)';
$string['transcriptmode'] = 'Switch to text-only mode';
$string['trimend'] = 'Video end (seconds)';
$string['trimheader'] = 'Playback window (trim)';
$string['trimsaved'] = 'Trim window saved.';
$string['trimstart'] = 'Video start (seconds)';
$string['true'] = 'True';
$string['typenote'] = 'Note';
$string['typepoll'] = 'Poll';
$string['typequestion'] = 'Question';
$string['usetranscriptascaption'] = 'Also use this text as the caption';
$string['videofile'] = 'Video file';
$string['videosource'] = 'Video source';
$string['videotype_html5'] = 'Upload';
$string['videotype_vimeo'] = 'Vimeo';
$string['videotype_youtube'] = 'YouTube';
$string['videourl'] = 'Video URL';
$string['viewfullactivity'] = 'Answer questions and see your grade';
$string['yourgrade'] = 'Your grade: {$a}';
$string['yourresponse'] = 'Your response';
