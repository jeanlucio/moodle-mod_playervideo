# ✨ Features

* 🎬 **Three Video Sources, One Player:** YouTube, Vimeo and self-hosted HTML5 video, each
  behind the exact same UI — native player controls are always disabled in favour of a single
  timeline the student and teacher both use, so switching a video's source never changes how
  the activity behaves.
* 🖱️ **Click-to-Mark Timeline Editor:** The teacher marks a question, a note or a poll directly
  on the video's own timeline by clicking where it should fire, drags two handles on the same
  timeline to trim the playable window (start/end), and edits or removes any marker from the
  same view.
* ❓ **Three Interaction Types:** *Question* (multiple-choice, true/false or open/essay, pulled
  from the real Question Bank or created inline), *Note* (an inline text marker, no grading),
  and *Poll* (no correct answer — the class's aggregate result is shown back to the student
  right after voting, never counted towards the grade).
* 🤖 **AI-Assisted Question Authoring:** Generate one question at a time, or paste a lecture
  transcript and generate a whole batch in one go — every AI-generated question lands on a
  review screen for the teacher to accept or discard before it becomes a real timeline
  interaction, never created unattended. A generated question's timestamp is always anchored to
  a real point in the transcript, never left to the AI's own arithmetic.
* 💾 **Auto-Save, Resume & Anti-Skip:** Every response and every playback position auto-saves
  through the interaction's own web service, with a `localStorage` retry queue that survives a
  dropped connection; reloading the page resumes the exact same in-progress attempt. Forward
  seeking past what has actually been watched is blocked server-side (configurable per
  activity), rewinding is always free.
* 🔁 **Multiple Attempts & Grade Aggregation:** An activity can allow a fixed number of attempts
  or none at all, aggregating the final grade by highest, average, first or last attempt.
* ⏳ **Grade Withheld Until Every Open Question Is Graded:** An attempt with a pending essay
  response never reaches the Gradebook early with a partial score — it stays
  `pendingcorrection` until a teacher (with AI assistance, see below) confirms the last one.
* 🌐 **Manual Captions, Merged With Native Tracks:** A teacher can author a caption track (VTT,
  pasted as plain "timestamp + text" or real VTT) per language; the student's caption selector
  merges it with whatever native captions YouTube/Vimeo already expose for that video, in one
  list.
* 📄 **AI Easy-Read Summary, Always Reviewed First:** An AI-generated plain-language summary of
  the video's content — always pending until a teacher approves or edits it, exactly like a
  generated question; students only ever see the approved version.
* 🦻 **Text-Only ("Blind") Mode:** The same attempt — same grade, same progress — rendered as a
  single linear document merging captions and interactions in order, for a student who cannot
  or does not want to use the video player. Always available from the activity's start screen,
  not hidden behind an accessibility setting.
* 🧑‍🏫 **AI-Assisted Grading for Open Questions:** Generates a suggested score and feedback for
  one response, or every pending response at once — the teacher always confirms or edits the
  final grade before it counts; the AI only ever proposes a completeness score, scaled to the
  question's real weight on the server, never asked to reason about the grading scale itself.
* 📊 **Built-In Analytics:** A report page aggregates results per question (accuracy for
  multiple-choice, pending/graded count for open questions) and per student (attempts, final
  grade, percentage watched, completion) — split across two independent capabilities, so a
  teacher who only reviews responses or only views analytics still gets exactly their half of
  the page.
* 🎮 **Optional PlayerHUD Integration:** Grants or charges `block_playerhud` inventory items on
  a correct answer or a retry when the block is installed and configured for the course —
  entirely absent otherwise, never a hard dependency.
* 🔒 **"Blind JSON" by Construction:** The client is never sent which answer is correct before
  the student submits — grading always happens server-side. This is a deliberate, structural
  difference from H5P Interactive Video, whose entire content JSON (question, options, *and*
  which one is correct) is embedded in `window.H5PIntegration` at page load, readable through
  the browser console before ever answering.
* 📦 **Backup & Restore:** Full Moodle 2 backup/restore, including "Duplicate activity" — the
  timeline, captions and easy-read summaries always travel with the activity; attempts and
  responses follow the "Include enrolled users" setting. Because the plugin references the
  Question Bank directly rather than through the full Question Usage API, `questionid`/
  `answerid` are remapped by hand on restore, through the same `question_created`/
  `question_answer` mapping namespaces every core question type uses.
* 🔐 **Privacy API & Cross-Instance Isolation:** Full Privacy API implementation, and a
  dedicated test suite proving every Web Service derives its access context from the resource's
  own course — never from an id a caller merely happens to know.
