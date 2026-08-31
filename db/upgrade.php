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
 * Plugin upgrade steps.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executes mod_playervideo upgrade steps from the given old version.
 *
 * @param int $oldversion Version number we are upgrading from.
 * @return bool True if upgrade succeeded.
 */
function xmldb_playervideo_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026083101) {
        $table = new xmldb_table('playervideo');

        $field = new xmldb_field('completionallinteractions', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('completionwatchtoend', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026083101, 'playervideo');
    }

    if ($oldversion < 2026083102) {
        $table = new xmldb_table('playervideo');

        $field = new xmldb_field('trimstart', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'videourl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trimend', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'trimstart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026083102, 'playervideo');
    }

    if ($oldversion < 2026083105) {
        $table = new xmldb_table('playervideo_poll_options');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('interactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('optiontext', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            // No separate index for interactionid: the foreign key above already creates one,
            // and an identically-named/fielded index alongside it is a coding_exception (Moodle
            // treats that as a duplicate, not an addition).
            $table->add_key('interactionid', XMLDB_KEY_FOREIGN, ['interactionid'], 'playervideo_interactions', ['id']);

            $dbman->create_table($table);
        }

        $responsestable = new xmldb_table('playervideo_responses');
        $field = new xmldb_field('polloptionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'answerid');
        if (!$dbman->field_exists($responsestable, $field)) {
            $dbman->add_field($responsestable, $field);
        }

        $key = new xmldb_key('polloptionid', XMLDB_KEY_FOREIGN, ['polloptionid'], 'playervideo_poll_options', ['id']);
        $dbman->add_key($responsestable, $key);

        upgrade_mod_savepoint(true, 2026083105, 'playervideo');
    }

    return true;
}
