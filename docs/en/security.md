# 🔐 Security & Compliance

* Capability-based access control: `mod/playervideo:view`, `mod/playervideo:attempt`,
  `mod/playervideo:manage`, `mod/playervideo:reviewresponses` and
  `mod/playervideo:viewreports` gate every action and view by role.
* **"Blind JSON":** the client is never sent which answer is correct before a response is
  submitted — every read path that reaches the student (question preview, poll options) strips
  correctness fields at the SQL level rather than filtering an already-loaded object, so a
  browser console can never recover the answer key from a page load the way it can from H5P
  Interactive Video's `window.H5PIntegration`.
* Every web service resolves the id it receives (`attemptid`, `interactionid`, `responseid`,
  `playervideoid`) by re-binding it to its own course module context and calling
  `validate_context()` before any capability check — never by isolated primary key — verified by
  a dedicated cross-instance isolation suite (`tests/cross_instance_security_test.php`) across
  representative web services from every capability tier.
* A question pulled into the timeline is re-validated server-side against the same
  `moodle/question:useall`/`usemine` category rule the "pull from bank" picker already applies
  to what it *offers* — closing the gap a raw web service call could otherwise use to bypass
  that picker and reference a question from a category outside the caller's own course.
* The plugin references the Question Bank directly rather than through the full Question Usage
  API — a deliberate simplification, proven in production by a sibling plugin in the same
  ecosystem, and actually the *safer* choice for "Blind JSON" here: the runtime read query
  never selects the correctness column at all, rather than loading a full question object and
  relying on careful filtering everywhere it is displayed. The one place this trade-off shows
  up is backup/restore, where the plugin remaps `questionid`/`answerid` by hand on restore
  (core's own `question_created`/`question_answer` mapping namespaces, the same ones every
  `qtype_*` restore step uses), degrading gracefully to the original id when a question was not
  part of the backup's own scope, or dropping the interaction outright if it cannot be resolved
  at all.
* Workflow guards (an already-graded response, a non-open-question interaction, an invalid
  grade) raise a translated `moodle_exception`, never a `coding_exception` — these are
  business-rule outcomes a normal user action can trigger, not programmer mistakes.
* Web services are consumed via `core/ajax`, whose transport already includes and validates the
  session key automatically.
* AI-assisted features never call an external provider directly: generation routes through
  [`local_aihub`](https://github.com/jeanlucio/moodle-local_aihub) (BYOK, its own Privacy
  Provider already declares the providers it reaches) or Moodle's own `core_ai` subsystem —
  this plugin needs no `add_external_location_link()` of its own. AI-generated content (question
  text, easy-read summaries, grading feedback) is treated as untrusted input: rendered with
  `format_text()`/escaped, never trusted or inserted as raw HTML.
* Rich text (notes, captions, responses, AI feedback) is always rendered through
  `format_text()` — never printed raw.
* Moodle External API compliant.
* Privacy API fully implemented: personal data (playback progress, attempts, responses) is
  exportable and erasable per user and per context; authored content (the timeline, captions,
  easy-read summaries) is the course's own pedagogical record, not personal data.
