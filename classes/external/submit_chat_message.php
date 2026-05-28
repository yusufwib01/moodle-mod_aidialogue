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

namespace mod_aidialogue\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\dialogue_engine;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\session_manager;

/**
 * External function: submit a student chat message and return the AI response.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_chat_message extends external_api {
    /**
     * Describe the input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Active session ID.'),
            'cmid'      => new external_value(PARAM_INT, 'Course module ID.'),
            'message'   => new external_value(PARAM_TEXT, 'Student message text.'),
        ]);
    }

    /**
     * Submit a student message and return the AI response.
     *
     * @param int    $sessionid Active aidialogue_session.id.
     * @param int    $cmid      Course module ID.
     * @param string $message   Student message text.
     * @return array{aimessage: string, move: string, iscomplete: bool}
     * @throws \invalid_parameter_exception On empty or over-length message.
     * @throws \moodle_exception On session errors or AI failure.
     */
    public static function execute(int $sessionid, int $cmid, string $message): array {
        global $DB, $USER;

        [
            'sessionid' => $sessionid,
            'cmid'      => $cmid,
            'message'   => $message,
        ] = self::validate_parameters(self::execute_parameters(), compact('sessionid', 'cmid', 'message'));

        $message = trim($message);
        if ($message === '') {
            throw new \invalid_parameter_exception('Message cannot be empty.');
        }
        if (\core_text::strlen($message) > 10000) {
            throw new \invalid_parameter_exception('Message exceeds maximum allowed length.');
        }

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aidialogue');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aidialogue:view', $context);

        $sessionrecord = $DB->get_record('aidialogue_session', ['id' => $sessionid], '*', IGNORE_MISSING);
        if (!$sessionrecord) {
            throw new \moodle_exception('error:sessionnotfound', 'mod_aidialogue');
        }
        if ((int) $sessionrecord->userid !== (int) $USER->id) {
            throw new \moodle_exception('error:sessionownership', 'mod_aidialogue');
        }
        if ($sessionrecord->status === 'complete') {
            throw new \moodle_exception('error:sessionalreadycomplete', 'mod_aidialogue');
        }

        $config = activity_config::load_from_db($cm->instance);
        $engine = new dialogue_engine(
            new session_manager(),
            new ai_client(),
            new prompt_builder(),
        );

        $result = $engine->process_student_turn($config, $sessionrecord, $message);

        return [
            'aimessage'  => $result['ai_message'],
            'move'       => $result['move'],
            'iscomplete' => $result['is_complete'],
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'aimessage'  => new external_value(PARAM_RAW, 'AI response text (HTML-safe on output).'),
            'move'       => new external_value(PARAM_ALPHANUMEXT, 'Move type (e.g. probe_deeper, session_close).'),
            'iscomplete' => new external_value(PARAM_BOOL, 'True when the session has been closed.'),
        ]);
    }
}
