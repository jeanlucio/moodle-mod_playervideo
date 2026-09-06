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
 * Shared HTML-escaping helper for this plugin's AMD modules.
 *
 * Every view that builds markup with a template literal + innerHTML used to define its own
 * escapeHtml() doing only the textContent/innerHTML round-trip — which escapes &, < and > but
 * NOT quotes, so a value placed inside a double- or single-quoted attribute (value="...",
 * aria-label="...", placeholder="...") could break out of it. This module is the single
 * quote-safe implementation, safe in both text and attribute position, so the views cannot
 * drift apart on it again.
 *
 * @module     mod_playervideo/escape
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Escapes a string for safe insertion as HTML text content or as a quoted attribute value.
 *
 * @param {string} text Raw text.
 * @returns {string}
 */
export const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
};

/**
 * Alias of {@link escapeHtml}, for call sites that want the name to state the attribute intent.
 *
 * @param {string} text Raw text.
 * @returns {string}
 */
export const escapeHtmlAttribute = escapeHtml;
