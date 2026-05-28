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

namespace mod_aidialogue\task;

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\dialogue_engine;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\session_manager;

/**
 * Adhoc task: generate student/teacher reports and grade for a completed session.
 *
 * Dispatched by dialogue_engine::do_session_close() immediately after the
 * session_close AI turn is stored. The two report AI calls (student report,
 * teacher report + grade) are expensive and must not run in the student's
 * HTTP request path to avoid PHP and webserver timeouts.
 *
 * Custom data shape:
 *   {
 *     "sessionid":    int,
 *     "aidialogueid": int
 *   }
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_session_reports extends \core\task\adhoc_task {
    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $data         = $this->get_custom_data();
        $sessionid    = (int) $data->sessionid;
        $aidialogueid = (int) $data->aidialogueid;

        $session = $DB->get_record('aidialogue_session', ['id' => $sessionid], '*', IGNORE_MISSING);

        if (!$session) {
            mtrace("mod_aidialogue: session {$sessionid} not found, skipping.");
            return;
        }

        if ($session->status !== 'complete') {
            mtrace("mod_aidialogue: session {$sessionid} status is '{$session->status}', expected 'complete', skipping.");
            return;
        }

        if (!empty($session->studentreport)) {
            mtrace("mod_aidialogue: session {$sessionid} already has reports, skipping.");
            return;
        }

        $config = activity_config::load_from_db($aidialogueid);

        $engine = new dialogue_engine(
            new session_manager(),
            new ai_client(),
            new prompt_builder(),
        );

        $engine->generate_reports($config, $session);

        mtrace("mod_aidialogue: reports generated for session {$sessionid}.");
    }
}
