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
 * External function to generate several questions at once from a pasted transcript.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playervideo\local\ai_service;
use mod_playervideo\local\question_service;
use moodle_exception;

/**
 * Generates up to COUNT questions from a pasted transcript, letting the AI pick the best
 * moments instead of the teacher choosing a timestamp for each one manually.
 *
 * Timestamp anchoring against alucination: every timestamp the AI returns is validated against
 * the transcript actually sent — a timestamp is only accepted when it matches one of the
 * timestamps the transcript itself declares (see {@see extract_transcript_timestamps()}). An AI
 * is bad at arithmetic/timing precision, so a "plausible but made up" timestamp is a real risk;
 * this is the server-side check that catches it, same "never trust the client/the AI without a
 * check" principle already applied elsewhere in this plugin (Blind JSON reads, correction
 * review). A candidate whose timestamp does not match anything in the transcript is dropped
 * silently rather than surfaced with a made-up value.
 *
 * Every generated question is already written to the Question Bank via
 * {@see question_service::create_question()} (same official save path as everywhere else in this
 * plugin) but, like {@see generate_question_ai}, none of them become a real timeline interaction
 * yet — the teacher reviews the returned candidate list and accepts each one individually via the
 * existing save_interaction() endpoint. A candidate never accepted stays an orphaned but harmless
 * question in the bank, same as any question never used in any activity.
 */
class generate_questions_batch extends external_api {
    /** @var int Hard ceiling on how many questions a single call can request. */
    private const MAX_COUNT = 10;

    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'transcript' => new external_value(PARAM_RAW, 'Pasted transcript, one timestamped line per entry'),
            'count' => new external_value(PARAM_INT, 'Number of questions to generate'),
            'format' => new external_value(PARAM_ALPHA, 'mc | open | mix', VALUE_DEFAULT, 'mc'),
        ]);
    }

    /**
     * Generates the batch of questions.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $transcript Pasted transcript, one timestamped line per entry.
     * @param int $count Number of questions to generate.
     * @param string $format 'mc' | 'open' | 'mix'.
     * @return array List of generated question candidates, pending teacher review.
     */
    public static function execute(int $playervideoid, string $transcript, int $count, string $format): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'transcript' => $transcript,
            'count' => $count,
            'format' => $format,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $modulecontext = context_module::instance($cm->id);
        self::validate_context($modulecontext);
        require_capability('mod/playervideo:manage', $modulecontext);
        require_capability('moodle/question:add', $modulecontext);

        if (!in_array($params['format'], ['mc', 'open', 'mix'], true)) {
            throw new moodle_exception('error_invalidqtype', 'mod_playervideo');
        }
        if (trim($params['transcript']) === '') {
            throw new moodle_exception('error_transcriptrequired', 'mod_playervideo');
        }

        // Clamp regardless of what the client sent — a client-supplied loop bound must never be
        // trusted as-is (see the project's own rule on this exact class of issue).
        $requestcount = max(1, min(self::MAX_COUNT, $params['count']));

        if (!ai_service::has_ai_source($modulecontext)) {
            throw new moodle_exception('error_noaisource', 'mod_playervideo');
        }

        $validtimestamps = self::extract_transcript_timestamps($params['transcript']);

        $prompt = self::build_prompt($params['transcript'], $requestcount, $params['format']);
        $description = get_string('aiusage_batch', 'mod_playervideo');
        $result = ai_service::generate($prompt, $description, $modulecontext);

        if (empty($result['success'])) {
            throw new moodle_exception('error_aigenerate', 'mod_playervideo');
        }

        $candidates = self::parse_response((string) ($result['data'] ?? ''));
        if ($candidates === null) {
            throw new moodle_exception('error_aiinvalidresponse', 'mod_playervideo');
        }

        $categoryid = question_service::get_or_create_category($modulecontext);

        $created = [];
        foreach ($candidates as $candidate) {
            if (count($created) >= $requestcount) {
                break;
            }
            // Anchoring check: drop anything the AI invented that does not match a timestamp
            // the transcript actually declares — never trust a number the AI "calculated".
            if (!in_array($candidate['timestamp'], $validtimestamps, true)) {
                continue;
            }

            try {
                $questionid = self::save_generated_question(
                    $candidate['qtype'],
                    $candidate,
                    $categoryid,
                    $modulecontext->id
                );
            } catch (moodle_exception $e) {
                // One malformed candidate (e.g. an "mc" answer set the AI got wrong) should not
                // discard the rest of an otherwise-good batch.
                continue;
            }

            $preview = question_service::get_question_for_review($questionid, $modulecontext);
            $created[] = [
                'questionid' => $questionid,
                'timestamp' => $candidate['timestamp'],
                'questiontext' => $preview['text'] ?? '',
                'answers' => array_map(
                    static fn(array $a): array => ['text' => $a['text'], 'correct' => $a['correct']],
                    $preview['options'] ?? []
                ),
            ];
        }

        return ['candidates' => $created];
    }

    /**
     * Extracts every timestamp (in seconds) the transcript itself declares.
     *
     * Deliberately permissive about the transcript's own format — "any reasonable format" per
     * the project spec, not a fixed grammar like VTT. Recognises mm:ss, h:mm:ss and a bare
     * "12.5s"/"12s" style, anywhere in a line, since real transcript exports vary. This is the
     * ground truth every AI-returned timestamp is checked against.
     *
     * @param string $transcript The pasted transcript text.
     * @return int[] Every distinct timestamp found, in seconds.
     */
    private static function extract_transcript_timestamps(string $transcript): array {
        $timestamps = [];

        foreach (explode("\n", $transcript) as $line) {
            if (preg_match('/(?:(\d+):)?(\d{1,2}):(\d{2})/', $line, $matches)) {
                $hours = $matches[1] !== '' ? (int) $matches[1] : 0;
                $minutes = (int) $matches[2];
                $seconds = (int) $matches[3];
                $timestamps[] = $hours * 3600 + $minutes * 60 + $seconds;
                continue;
            }
            if (preg_match('/\b(\d+)\s*s\b/i', $line, $matches)) {
                $timestamps[] = (int) $matches[1];
            }
        }

        return array_values(array_unique($timestamps));
    }

    /**
     * Builds the AI prompt for the batch, asking it to pick the best moments itself.
     *
     * @param string $transcript The pasted transcript text.
     * @param int $count Number of questions to generate.
     * @param string $format 'mc' | 'open' | 'mix'.
     * @return string The prompt text.
     */
    private static function build_prompt(string $transcript, int $count, string $format): string {
        $formatinstruction = match ($format) {
            'open' => 'Every question must be open-ended (type "essay"), requiring a short written answer.',
            'mix' => 'Choose the best type for each question: "multichoice" (4 options, one correct) '
                . 'or "essay" (open-ended, short written answer).',
            default => 'Every question must be multiple-choice (type "multichoice"), with exactly 4 '
                . 'answer options, only one correct.',
        };

        return implode("\n", [
            'You are an instructional designer creating comprehension questions for an educational '
                . 'video, from its transcript below.',
            "Pick the {$count} best moments in the transcript for a comprehension question.",
            $formatinstruction,
            'CRITICAL: for each question, "timestamp" MUST be copied exactly from a timestamp that '
                . 'already appears in the transcript below — never invent or calculate one.',
            'Reply ONLY with a valid JSON object in this exact format, no code fences: '
                . '{"questions": [{"timestamp": <seconds>, "qtype": "multichoice"|"essay", '
                . '"questiontext": "...", "answers": [{"text": "...", "correct": true}, ...]}]} '
                . '(omit "answers" entirely for an essay question)',
            '--- TRANSCRIPT ---',
            $transcript,
        ]);
    }

    /**
     * Parses and structurally validates the AI's JSON response.
     *
     * @param string $responsetext Raw text returned by the AI provider.
     * @return array|null List of candidate arrays (timestamp, qtype, questiontext, answers), or
     *      null if the response is not a JSON object with a "questions" array.
     */
    private static function parse_response(string $responsetext): ?array {
        $cleaned = preg_replace('/^\x60\x60\x60(?:json)?\s*/im', '', $responsetext);
        $cleaned = preg_replace('/\x60\x60\x60\s*$/m', '', $cleaned);
        $cleaned = trim((string) $cleaned);

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded) || !isset($decoded['questions']) || !is_array($decoded['questions'])) {
            return null;
        }

        $candidates = [];
        foreach ($decoded['questions'] as $entry) {
            if (!is_array($entry) || !isset($entry['questiontext']) || !isset($entry['timestamp'])) {
                continue;
            }
            $qtype = ($entry['qtype'] ?? '') === 'essay' ? 'essay' : 'multichoice';

            $answers = [];
            if (isset($entry['answers']) && is_array($entry['answers'])) {
                foreach ($entry['answers'] as $answer) {
                    if (!is_array($answer) || !isset($answer['text'])) {
                        continue;
                    }
                    $answers[] = [
                        'text' => (string) $answer['text'],
                        'correct' => !empty($answer['correct']),
                    ];
                }
            }

            $candidates[] = [
                'timestamp' => self::normalise_timestamp($entry['timestamp']),
                'qtype' => $qtype,
                'questiontext' => (string) $entry['questiontext'],
                'answers' => $answers,
            ];
        }

        return $candidates;
    }

    /**
     * Normalises a "timestamp" value from the AI response into whole seconds.
     *
     * The prompt asks for a plain integer, but a model does not always comply — it sometimes
     * echoes the "mm:ss"/"h:mm:ss" text it saw in the transcript instead. A naive `(int)` cast on
     * a string like "0:45" silently truncates to its leading digits (0), which would then fail
     * the anchoring check against a real "45 seconds" entry even though the model picked a valid
     * moment. Accepting both shapes here keeps the anchoring check itself strict (still an exact
     * match against {@see extract_transcript_timestamps()}), instead of loosening it to compensate.
     *
     * @param mixed $value Raw "timestamp" value from the decoded JSON.
     * @return int Timestamp in whole seconds.
     */
    private static function normalise_timestamp(mixed $value): int {
        if (is_string($value) && preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', trim($value), $matches)) {
            $hours = $matches[1] !== '' ? (int) $matches[1] : 0;
            return $hours * 3600 + ((int) $matches[2]) * 60 + (int) $matches[3];
        }
        return (int) $value;
    }

    /**
     * Writes one generated candidate through the official save path.
     *
     * @param string $qtype 'multichoice' | 'essay'.
     * @param array $candidate Parsed candidate, see {@see parse_response()}.
     * @param int $categoryid Destination question category id.
     * @param int $categorycontextid Context id that owns that category.
     * @return int The created question id.
     */
    private static function save_generated_question(
        string $qtype,
        array $candidate,
        int $categoryid,
        int $categorycontextid
    ): int {
        $formdata = new \stdClass();
        $formdata->name = shorten_text(strip_tags($candidate['questiontext']), 60);
        $formdata->questiontext = ['text' => $candidate['questiontext'], 'format' => FORMAT_HTML];
        $formdata->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $formdata->defaultmark = 1;
        $formdata->penalty = 0;

        if ($qtype === 'essay') {
            $qtypedata = question_service::build_essay_formdata();
        } else {
            $qtypedata = question_service::build_multichoice_formdata($candidate['answers'], true);
        }
        foreach (get_object_vars($qtypedata) as $field => $value) {
            $formdata->$field = $value;
        }

        return question_service::create_question($qtype, $categoryid, $categorycontextid, $formdata);
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'candidates' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_INT, 'Created question id'),
                    'timestamp' => new external_value(PARAM_INT, 'Video timestamp, in seconds'),
                    'questiontext' => new external_value(PARAM_RAW, 'Formatted question text'),
                    'answers' => new external_multiple_structure(
                        new external_single_structure([
                            'text' => new external_value(PARAM_RAW, 'Answer text'),
                            'correct' => new external_value(PARAM_BOOL, 'Whether this answer is correct'),
                        ]),
                        'Answer options, empty for essay questions'
                    ),
                ]),
                'Generated candidates, pending teacher review'
            ),
        ]);
    }
}
