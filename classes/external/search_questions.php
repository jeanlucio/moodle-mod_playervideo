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
 * External function to search the Question Bank for the "pull from bank" picker.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\external;

use context;
use context_course;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Lists existing multichoice/truefalse questions the teacher is allowed to reuse, for the
 * "puxar do banco" timeline picker — reuses the same moodle/question:useall|usemine category
 * resolution already used by mod_playerpuzzle's mod_form.php.
 */
class search_questions extends external_api {
    /** @var int Maximum results returned, regardless of the requested limit. */
    private const MAX_LIMIT = 50;

    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'playervideoid' => new external_value(PARAM_INT, 'PlayerVideo instance id'),
            'query' => new external_value(PARAM_TEXT, 'Free-text search on the question text', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Maximum results (capped at 50)', VALUE_DEFAULT, 20),
        ]);
    }

    /**
     * Searches multichoice/truefalse questions in categories the current user may reuse.
     *
     * @param int $playervideoid PlayerVideo instance id.
     * @param string $query Free-text search on the question text.
     * @param int $limit Maximum results.
     * @return array Matching questions.
     */
    public static function execute(int $playervideoid, string $query, int $limit): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'playervideoid' => $playervideoid,
            'query' => $query,
            'limit' => $limit,
        ]);

        $cm = get_coursemodule_from_instance('playervideo', $params['playervideoid'], 0, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);
        require_capability('mod/playervideo:manage', $modcontext);

        $coursecontext = context_course::instance($cm->course);
        $contextstocheck = [];
        foreach ($coursecontext->get_parent_contexts(true) as $ctx) {
            $contextstocheck[$ctx->id] = $ctx;
        }
        $modinfo = get_fast_modinfo($cm->course);
        foreach ($modinfo->cms as $othercm) {
            $othercontext = context_module::instance($othercm->id);
            $contextstocheck[$othercontext->id] = $othercontext;
        }

        $validcontextids = [];
        foreach ($contextstocheck as $ctx) {
            if (has_capability('moodle/question:useall', $ctx) || has_capability('moodle/question:usemine', $ctx)) {
                $validcontextids[] = $ctx->id;
            }
        }

        if (empty($validcontextids)) {
            return ['questions' => []];
        }

        $limit = min(self::MAX_LIMIT, max(1, $params['limit']));

        [$contextinsql, $contextparams] = $DB->get_in_or_equal($validcontextids, SQL_PARAMS_NAMED, 'ctx');
        [$qtypeinsql, $qtypeparams] = $DB->get_in_or_equal(['multichoice', 'truefalse'], SQL_PARAMS_NAMED, 'qtype');
        $sqlparams = array_merge($contextparams, $qtypeparams);

        $sql = "SELECT q.id, q.qtype, q.questiontext, q.questiontextformat
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id AND qv.status = 'ready'
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qc.contextid $contextinsql
                   AND q.qtype $qtypeinsql";

        if (trim($params['query']) !== '') {
            $sql .= ' AND ' . $DB->sql_like('q.questiontext', ':query', false, false);
            $sqlparams['query'] = '%' . $DB->sql_like_escape(trim($params['query'])) . '%';
        }

        $sql .= ' ORDER BY q.name ASC';

        $records = $DB->get_records_sql($sql, $sqlparams, 0, $limit);

        $questions = [];
        foreach ($records as $record) {
            $questions[] = [
                'id' => (int) $record->id,
                'type' => $record->qtype,
                'preview' => format_text($record->questiontext, $record->questiontextformat, ['context' => $modcontext]),
            ];
        }

        return ['questions' => $questions];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Question id'),
                    'type' => new external_value(PARAM_ALPHA, 'multichoice | truefalse'),
                    'preview' => new external_value(PARAM_RAW, 'Formatted question text'),
                ]),
                'Matching questions'
            ),
        ]);
    }
}
