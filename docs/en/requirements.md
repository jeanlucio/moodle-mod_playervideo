# 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5 – 5.2 |
| PHP       | 8.1+    |

AI-assisted features (question generation, easy-read summaries, open-question grading
suggestions) work without any configuration and degrade gracefully when no AI source is
available. To actually generate content, one of the following must be reachable:

* [`local_aihub`](https://github.com/jeanlucio/moodle-local_aihub) installed on the same site,
  with a site or personal BYOK key configured for at least one provider (Gemini, Groq,
  DeepSeek, or any OpenAI-compatible endpoint) — the recommended path.
* Moodle's own `core_ai` subsystem, configured with an institutional provider, as a fallback
  when `local_aihub` is absent or has no key available.

`block_playerhud` is an entirely optional integration — install and configure it on a course to
enable item grants/costs on this activity; nothing here requires it.
