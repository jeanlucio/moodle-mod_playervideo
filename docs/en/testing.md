# 🧪 Automated Tests

PlayerVideo ships with a PHPUnit test suite covering the domain services, all 22 Web Services,
completion rules, output rendering, uninstall cleanup, Privacy API compliance, backup/restore
and cross-instance isolation. Every CI push runs against the full matrix (Moodle 4.5 → 5.2, PHP
8.2 → 8.4, PostgreSQL & MariaDB), plus live end-to-end validation against real AI providers
(Gemini/Groq/DeepSeek, via `local_aihub`) for every AI-backed feature.

### Web Services Tests (`tests/external/`)

| Test file | Cases |
|-----------|------:|
| `save_interaction_test.php` | 15 |
| `submit_answer_test.php` | 8 |
| `generate_questions_batch_test.php` | 8 |
| `save_caption_test.php` | 7 |
| `create_question_test.php` | 7 |
| `start_attempt_test.php` | 6 |
| `generate_response_correction_test.php` | 6 |
| `generate_question_ai_test.php` | 6 |
| `save_progress_test.php` | 5 |
| `save_trim_test.php` | 4 |
| `save_di_summary_test.php` | 4 |
| `review_response_test.php` | 4 |
| `get_poll_results_test.php` | 4 |
| `get_attempt_review_test.php` | 4 |
| `generate_di_summary_test.php` | 4 |
| `finish_attempt_test.php` | 4 |
| `get_report_test.php` | 3 |
| `get_interactions_test.php` | 3 |
| `get_captions_test.php` | 3 |
| `search_questions_test.php` | 2 |
| `get_pending_corrections_test.php` | 2 |
| `get_di_summaries_test.php` | 2 |
| **Subtotal** | **111** |

### Domain Service Tests (`tests/local/`)

| Test file | Cases |
|-----------|------:|
| `question_service_test.php` | 19 |
| `attempt_manager_test.php` | 16 |
| `hud_service_test.php` | 16 |
| `video_source_test.php` | 9 |
| `caption_service_test.php` | 7 |
| `intro_service_test.php` | 4 |
| `di_summary_service_test.php` | 3 |
| `transcript_service_test.php` | 3 |
| `ai_service_test.php` | 2 |
| **Subtotal** | **79** |

### Library, Completion, Output, Privacy, Backup & Isolation Tests

| Test file | Cases |
|-----------|------:|
| `lib_test.php` | 15 |
| `privacy/provider_test.php` | 14 |
| `mod_form_test.php` | 17 |
| `backup_restore_test.php` | 9 |
| `cross_instance_security_test.php` | 6 |
| `completion/custom_completion_test.php` | 4 |
| `uninstall_test.php` | 2 |
| `output/view_render_test.php` | 1 |
| **Subtotal** | **68** |

| **Grand Total** | **258** |

```bash
vendor/bin/phpunit --bootstrap lib/phpunit/bootstrap.php mod/playervideo
```

**Line coverage by class (PHPUnit + Xdebug, via the `moodle-coverage` tool):**

| Class | Line coverage |
|-------|:-------------:|
| `local\di_summary_service` | 100% |
| `local\intro_service` | 100% |
| `local\video_source` | 100% |
| `external\get_attempt_review` | 100% |
| `external\get_captions` | 100% |
| `external\get_di_summaries` | 100% |
| `external\get_interactions` | 100% |
| `external\get_poll_results` | 100% |
| `external\save_caption` | 100% |
| `external\save_trim` | 100% |
| `local\attempt_manager` | 98% |
| `external\create_question` | 98% |
| `external\save_progress` | 98% |
| `external\review_response` | 98% |
| `external\search_questions` | 98% |
| `external\get_pending_corrections` | 98% |
| `external\finish_attempt` | 97% |
| `external\save_di_summary` | 97% |
| `external\save_interaction` | 97% |
| `external\get_report` | 96% |
| `privacy\provider` | 94% |
| `local\hud_service` | 89% |
| `external\submit_answer` | 85% |
| `external\start_attempt` | 80% |
| `local\transcript_service` | 78% |
| `completion\custom_completion` | 77% |
| `local\question_service` | 72% |
| `local\caption_service` | 67% |
| `external\generate_di_summary` | 62% |
| `external\generate_questions_batch` | 52% |
| `external\generate_question_ai` | 52% |
| `external\generate_response_correction` | 49% |
| `local\ai_service` | 32% |
| **Overall** | **81%** |

**Legacy (non-namespaced) files, measured separately:** `mod_form.php` and the two
`backup/moodle2/*_stepslib.php` classes are never autoloaded under the `mod_playervideo\`
namespace, so the table above — scoped to `classes/` — never sees them. Measuring the whole
plugin tree instead (`moodle-coverage mod/playervideo --filter .`) surfaces them on their own:

| File / class | Line coverage |
|--------------|:-------------:|
| `backup_playervideo_activity_structure_step` | 100% |
| `mod_form.php` (`mod_playervideo_mod_form`) | 100% |
| `restore_playervideo_activity_structure_step` | 95% |

The two Fase 9 backup/restore fixes (`videofile`/`posterimage` annotation) are fully exercised
by `backup_restore_test.php`'s real `backup_and_restore_into_new_course()` round trip — the
backup step's own single method is 100% covered. `mod_form.php` had a real, closeable gap found
by this sweep: `data_preprocessing()` (the method that fixed the `videofile`/`posterimage`
silent-data-loss bug, see the features page) started at **0% method coverage** — proven correct
only by live Playwright validation, never by a direct unit test. A follow-up pass closed every
branch across the whole file (11 new tests: the HUD reward selects only appearing when
`block_playerhud` is configured for the course, a stale HUD item id kept as a labelled option
instead of silently vanishing, the two invalid-URL and the HUD-cost-quantity validation errors,
`add_completion_rules()`/`completion_rule_enabled()`, and the `formatstringstriptags`-driven
`PARAM_CLEANHTML` fallback), taking `mod_form.php` from 62% to **100%**.

`restore_playervideo_activity_structure_step` went from 90% to 95% the same way: 3 new tests
prove `resolve_hud_item()`'s graceful degradation on "Duplicate activity" — a stored HUD item
reference survives when the block is still in the course, and drops to 0 when it (or the item)
is gone — plus a corrupted question-type interaction (`questionid` ≤ 0, a state the plugin's own
UI never produces but a hand-edited backup XML theoretically could) being dropped defensively
instead of crashing `resolve_questionid()`. The residual 5% is a handful of branches genuinely
out of reach here: `resolve_hud_item()`'s "restored through block_playerhud's own mapping" path
only fires on a *full course* backup that also restores the HUD block itself, a deep cross-plugin
assumption not worth coupling this test suite to; `resolve_questionid()`'s "core's own
`question_created` mapping succeeded" branch depends on Moodle's own dedup heuristic, already
documented in the plugin's own SCOPE as non-deterministic across Moodle versions; and four
defensive early-returns (`process_playervideo_progress`/`_attempt`/`_response`'s own-user-mapping
miss, `process_playervideo_polloption`'s interaction-mapping miss) only trigger on a
partial-user-selection backup, which would need a materially heavier test harness than the
value it would prove — mirrors the plugin's own `resolve_questionid` core-behavior note in
spirit, not a new kind of gap.

> The AI-facing classes (`ai_service`, `generate_question_ai`, `generate_questions_batch`,
> `generate_response_correction`, `generate_di_summary`) show the lowest PHPUnit line coverage
> in the plugin — most of their real-provider branches (the `local_aihub`/`core_ai` fallback
> ladder, an actual malformed-JSON response, a genuine rate-limit error) are exercised through
> live end-to-end validation against real AI providers instead of a mocked HTTP layer, which
> this class-by-class table does not count. Closing that gap with plain PHPUnit would need a
> mockable transport seam injected into `ai_service`, a real design change, not just an added
> test — deliberately not done, mirroring the same choice already made for the sibling
> `mod_playerwords\local\ai_word_generator`. `question_service`/`caption_service` similarly
> have a few branches (the 4.5-only `question_get_default_category()` code path, an
> already-real VTT passthrough) that only a specific Moodle version or input shape reaches.
> `hud_service`'s own remaining gap is the inverse case: its graceful-degradation branches
> (every method's early return when `block_playerhud` is absent) only run for real on a site
> where the block was never installed — inert here, since this dev site has it.
