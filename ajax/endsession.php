<?php
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
 * AJAX endpoint for student-initiated early session exit.
 *
 * Called via POST from view.php when the student clicks "End Session".
 *
 * Required POST params:
 *   sesskey   — Moodle session key (CSRF protection).
 *   sessionid — aidialogue_session.id for the active session.
 *   cmid      — Course module ID (used for capability check).
 *
 * Returns JSON:
 *   On success: { is_complete: true }
 *   On error:   { error: string }
 *
 * @package    mod_aidialogue
 * @copyright  2026 Moodle HQ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require('../../../config.php');
require_once($CFG->dirroot . '/mod/aidialogue/lib.php');

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\session_manager;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\dialogue_engine;

// Always respond with JSON.
header('Content-Type: application/json');

/**
 * Emit a JSON error and exit.
 *
 * @param string $message Human-readable error message.
 */
function aidialogue_endsession_error(string $message): never {
    echo json_encode(['error' => $message]);
    die;
}

// -------------------------------------------------------------------------
// Input validation.
// -------------------------------------------------------------------------
if (!confirm_sesskey()) {
    aidialogue_endsession_error('Invalid session key.');
}

$sessionid = required_param('sessionid', PARAM_INT);
$cmid      = required_param('cmid', PARAM_INT);

// -------------------------------------------------------------------------
// Auth + capability check.
// -------------------------------------------------------------------------
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aidialogue');
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aidialogue:view', $context);

// -------------------------------------------------------------------------
// Load the session and verify ownership.
// -------------------------------------------------------------------------
$sessionrecord = $DB->get_record('aidialogue_session', ['id' => $sessionid], '*', IGNORE_MISSING);

if (!$sessionrecord) {
    aidialogue_endsession_error('Session not found.');
}

if ((int) $sessionrecord->userid !== (int) $USER->id) {
    aidialogue_endsession_error('You do not own this session.');
}

if ($sessionrecord->status === 'complete') {
    aidialogue_endsession_error('This session is already complete.');
}

// -------------------------------------------------------------------------
// Build engine and end the session early.
// -------------------------------------------------------------------------
$config = activity_config::load_from_db($cm->instance);
$engine = new dialogue_engine(
    new session_manager(),
    new ai_client(),
    new prompt_builder(),
);

try {
    $engine->end_session_early($config, $sessionrecord);
    echo json_encode(['is_complete' => true]);
} catch (\moodle_exception $e) {
    aidialogue_endsession_error($e->getMessage());
} catch (\Throwable $e) {
    debugging('aidialogue endsession ajax error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    aidialogue_endsession_error('An unexpected error occurred. Please try again.');
}
