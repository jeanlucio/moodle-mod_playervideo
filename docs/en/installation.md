# 🛠️ Installation & Configuration

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `mod/` directory.
3. Rename the folder to `playervideo` (if necessary).
   Final path:
   `your-moodle/mod/playervideo/`
4. Visit **Site administration > Notifications** to complete installation.
5. Add a **PlayerVideo** activity to a course — the source video (YouTube/Vimeo URL or an
   uploaded HTML5 file), grading options and PlayerHUD integration are all configured on the
   same activity form.

No manual capability assignment is needed for standard roles: an editing teacher already gets
`mod/playervideo:manage` (author the timeline), `mod/playervideo:reviewresponses` (grade open
questions) and `mod/playervideo:viewreports` (analytics) by default, and a non-editing teacher
gets the latter two. Adjust any of the six capabilities under **Site administration > Users >
Permissions > Define roles** if your institution's role setup differs.

To enable the AI-assisted features (question generation, easy-read summaries, grading
suggestions), install and configure [`local_aihub`](https://github.com/jeanlucio/moodle-local_aihub)
or Moodle's own `core_ai` subsystem — see [Requirements](#requirements) above. Every AI-backed
button simply stays available-but-inert until a source is configured; nothing else in the
activity depends on it.
