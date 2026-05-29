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
 * Custom completion rules for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Muhammad Arnaldo <muhammad.arnaldo@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion for mod_aidialogue.
 *
 * Defines two rules:
 *   completionpassed     — at least one completed session where ALL criteria ended with status='met'.
 *   completionexhausted  — maxattempts > 0 AND the student has used all their attempts.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetch the completion state for a given completion rule.
     *
     * @param string $rule The completion rule identifier.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $userid     = $this->userid;
        $aidialogue = $DB->get_record('aidialogue', ['id' => $this->cm->instance], '*', MUST_EXIST);

        $status = match ($rule) {
            'completionpassed'    => $this->get_passed_state($aidialogue, $userid),
            'completionexhausted' => $this->get_exhausted_state($aidialogue, $userid),
            default               => COMPLETION_INCOMPLETE,
        };

        return $status;
    }

    /**
     * Completion state for the "passed" rule: at least one completed session with all criteria met.
     *
     * @param \stdClass $aidialogue Activity instance record.
     * @param int       $userid     User ID being evaluated.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    private function get_passed_state(\stdClass $aidialogue, int $userid): int {
        global $DB;

        if (empty($aidialogue->completionpassed)) {
            return COMPLETION_INCOMPLETE;
        }

        // A passing session = 'complete' status AND every criterion_result is 'met'.
        $sql = "SELECT COUNT(s.id)
                  FROM {aidialogue_session} s
                 WHERE s.aidialogueid = :aidialogueid
                   AND s.userid       = :userid
                   AND s.status       = 'complete'
                   AND NOT EXISTS (
                           SELECT 1
                             FROM {aidialogue_criterion_result} cr
                            WHERE cr.sessionid = s.id
                              AND cr.status   != 'met'
                       )";

        $passed = $DB->count_records_sql($sql, [
            'aidialogueid' => $aidialogue->id,
            'userid'       => $userid,
        ]);

        return $passed > 0 ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Completion state for the "exhausted" rule: maxattempts > 0 and all attempts used.
     *
     * @param \stdClass $aidialogue Activity instance record.
     * @param int       $userid     User ID being evaluated.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    private function get_exhausted_state(\stdClass $aidialogue, int $userid): int {
        global $DB;

        if (empty($aidialogue->completionexhausted) || $aidialogue->maxattempts <= 0) {
            return COMPLETION_INCOMPLETE;
        }

        $used = $DB->count_records('aidialogue_session', [
            'aidialogueid' => $aidialogue->id,
            'userid'       => $userid,
        ]);

        return $used >= $aidialogue->maxattempts ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionpassed',
            'completionexhausted',
        ];
    }

    /**
     * Return the descriptions of the custom completion rules for display.
     *
     * @return string[] Rule key => localised description string.
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionpassed'    => get_string('completionpassed', 'aidialogue'),
            'completionexhausted' => get_string('completionexhausted', 'aidialogue'),
        ];
    }

    /**
     * Return the sort order for completion rules, including core view tracking.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionpassed',
            'completionexhausted',
        ];
    }
}
