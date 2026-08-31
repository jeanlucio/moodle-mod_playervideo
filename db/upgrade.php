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
    return true;
}
