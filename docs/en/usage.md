# 📖 Usage

## Authoring the timeline

1. Add a **PlayerVideo** activity to a course, pointing it at a YouTube/Vimeo URL or an
   uploaded HTML5 video.
2. Open **Manage interactions** (from the activity's own settings menu). The video plays with a
   single custom timeline underneath it — click anywhere on the timeline to jump there, or use
   the **Add here** button to open the marker picker at the current position.
3. Choose a marker type:
   * **Question** — pull an existing multiple-choice/true-false/essay question from the Question
     Bank, create one inline, or generate one with AI from a short prompt.
   * **Note** — a short text shown to the student when the video reaches that point.
   * **Poll** — a prompt with 2 to 6 options; no correct answer, results are shown to the class
     after voting.
4. Drag the two handles on the timeline to trim the activity's playable window (start/end) —
   students never see or seek outside it.
5. Existing markers can be reopened, edited or deleted from the same screen; a marker that
   already has student responses cannot be deleted, only edited.

### Generating questions with AI

* **One at a time:** from the Question tab of the marker editor, describe what the question
  should cover and generate it — it lands as a normal Question Bank question, ready to review
  before saving.
* **In batch, from a transcript:** paste a lecture transcript (with timestamps, "12:34 ..." per
  line or similar), choose how many questions and which type(s), and generate the whole set at
  once. Every candidate shows up on a review screen with its anchored timestamp — accept the
  ones worth keeping, discard the rest. Nothing is added to the timeline until you accept it.
* While pasting a transcript, you can also reuse the same text as the activity's caption track
  for that language, with a confirmation before overwriting a caption that already exists.

### Captions and the easy-read summary

* Open the **Captions** editor (from the same marker-editor modal) to paste or write a caption
  track per language — either plain "timestamp text" lines or a real `.vtt` file's content.
* Generate an AI easy-read summary of the video's content per language from the same place; it
  stays pending until you review and approve it (or edit it first) — students only ever see an
  approved summary, shown to them from a button on the activity's start screen.

## Taking the activity (student view)

1. The start screen shows the video's introduction, the easy-read summary button (if one is
   approved) and a link to switch to the text-only mode.
2. Playing the video pauses automatically at each marker: answer the question, read the note,
   or vote in the poll to continue. Forward seeking past what has already been watched is
   blocked (unless the activity allows it); rewinding is always free.
3. Progress and every response auto-save as you go — closing the tab and coming back resumes
   the same attempt exactly where it left off.
4. Once finished, a summary of the attempt is shown; if the activity allows more than one
   attempt (and the limit hasn't been reached), a new one can be started. A finished attempt can
   always be reopened in **read-only review**: each interaction shows the response given, the
   correct answer, and per-answer feedback — never revealed while the attempt was still open.
5. **Text-only mode** renders the exact same attempt — same grade, same progress — as a single
   linear document merging the captions and interactions in reading order, for a student who
   cannot or does not want to use the video player.

## Grading open questions

1. Open the activity's **Report** page — the correction queue only appears for a user with
   `mod/playervideo:reviewresponses`.
2. Each pending response shows the question, the student's answer, and a **Generate** button
   for an AI-suggested score and feedback — or use **Generate all suggestions** to request one
   for every response still missing one, one at a time (never in parallel, to respect a real AI
   provider's own rate limit).
3. Confirm the final grade and feedback for each response — editing the AI's suggestion first is
   the same action as approving it as-is. The AI's suggestion is only ever a completeness score
   from 0.0 to 1.0; it never writes the official grade or knows the question's point value —
   that scaling happens on the server.
4. Once every pending response on an attempt is graded, the attempt's final grade is calculated
   and sent to the Gradebook automatically.

## Analytics

The same **Report** page also shows, for a user with `mod/playervideo:viewreports`: per-question
statistics (accuracy for multiple-choice, how many responses are still pending vs. graded for
open questions) and per-student statistics (attempts, final grade, percentage of the video
watched, activity completion). A user with only one of the two capabilities sees only their own
half of the page.
