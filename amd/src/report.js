// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AMD module for mod_aidialogue report and review page navigation.
 *
 * Handles:
 *   - Clickable table rows (report.php): rows with data-href navigate on click/Enter.
 *   - Attempt select (review.php): navigates on change.
 *
 * @module     mod_aidialogue/report
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise report page navigation.
 */
export const init = () => {
    // Clickable rows with data-href attribute.
    document.querySelectorAll('tr[data-href]').forEach(row => {
        row.addEventListener('click', () => {
            window.location.href = row.dataset.href;
        });
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                window.location.href = row.dataset.href;
            }
        });
    });

    // Attempt dropdown navigation.
    const attemptselect = document.getElementById('aidialogue-attempt-select');
    if (attemptselect) {
        attemptselect.addEventListener('change', () => {
            window.location.href = attemptselect.value;
        });
    }
};
