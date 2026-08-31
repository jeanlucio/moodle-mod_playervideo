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
 * Shows the how-this-works onboarding modal, once, on a student's first visit to any
 * PlayerVideo activity on the whole site.
 *
 * @module     mod_playervideo/onboarding
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Notification from 'core/notification';
import {getString} from 'core/str';

/**
 * Opens the onboarding content (already server-rendered into #playervideo-onboarding-content,
 * hidden) in a modal.
 *
 * @param {HTMLElement} content Hidden container holding the pre-rendered onboarding body.
 * @returns {Promise<void>}
 */
const openOnboardingModal = async(content) => {
    try {
        const title = await getString('introtitle', 'mod_playervideo');
        await Modal.create({
            title,
            body: content.innerHTML,
            show: true,
            removeOnClose: true,
        });
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Opens the onboarding modal automatically, once, when requested by the server for this page
 * load. The server decides `autoshow` from a site-wide user preference (see
 * intro_service::has_seen_intro()) that is marked seen the moment it is decided, so this can
 * only ever fire once per user across every PlayerVideo activity on the whole site, not once
 * per activity or per course.
 *
 * @param {boolean} autoshow Whether to open the modal immediately, once, on this load.
 */
export const init = (autoshow) => {
    if (!autoshow) {
        return;
    }

    const content = document.getElementById('playervideo-onboarding-content');
    if (!content) {
        return;
    }

    openOnboardingModal(content);
};
