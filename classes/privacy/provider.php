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
 * Privacy Subsystem implementation for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_aidialogue.
 *
 * This plugin stores personal data in three tables:
 *   - aidialogue_session: one row per student attempt, linked to a userid.
 *   - aidialogue_turn: conversation messages (student and AI), linked to a session.
 *   - aidialogue_criterion_result: per-criterion outcomes and evidence, linked to a session.
 *
 * Student message content is also transmitted to a configured external AI
 * endpoint to generate conversational responses, criterion outcomes, and reports.
 * This is declared below as an external location link.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe all personal data stored and transmitted by this plugin.
     *
     * @param collection $collection Metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('aidialogue_session', [
            'userid'        => 'privacy:metadata:aidialogue_session:userid',
            'attemptnumber' => 'privacy:metadata:aidialogue_session:attemptnumber',
            'status'        => 'privacy:metadata:aidialogue_session:status',
            'studentreport' => 'privacy:metadata:aidialogue_session:studentreport',
            'teacherreport' => 'privacy:metadata:aidialogue_session:teacherreport',
            'aigrade'       => 'privacy:metadata:aidialogue_session:aigrade',
            'teachergrade'  => 'privacy:metadata:aidialogue_session:teachergrade',
            'earlyexit'     => 'privacy:metadata:aidialogue_session:earlyexit',
            'timecreated'   => 'privacy:metadata:aidialogue_session:timecreated',
            'timestarted'   => 'privacy:metadata:aidialogue_session:timestarted',
            'timefinished'  => 'privacy:metadata:aidialogue_session:timefinished',
        ], 'privacy:metadata:aidialogue_session');

        $collection->add_database_table('aidialogue_turn', [
            'role'        => 'privacy:metadata:aidialogue_turn:role',
            'move'        => 'privacy:metadata:aidialogue_turn:move',
            'content'     => 'privacy:metadata:aidialogue_turn:content',
            'timecreated' => 'privacy:metadata:aidialogue_turn:timecreated',
        ], 'privacy:metadata:aidialogue_turn');

        $collection->add_database_table('aidialogue_criterion_result', [
            'status'   => 'privacy:metadata:aidialogue_criterion_result:status',
            'evidence' => 'privacy:metadata:aidialogue_criterion_result:evidence',
        ], 'privacy:metadata:aidialogue_criterion_result');

        $collection->add_external_location_link('aiservice', [
            'message' => 'privacy:metadata:aiservice:message',
        ], 'privacy:metadata:aiservice');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                                          AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {aidialogue} a ON a.id = cm.instance
                  JOIN {aidialogue_session} s ON s.aidialogueid = a.id
                 WHERE s.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname'      => 'aidialogue',
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT s.userid
                  FROM {aidialogue_session} s
                  JOIN {aidialogue} a ON a.id = s.aidialogueid
                  JOIN {course_modules} cm ON cm.instance = a.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql('userid', $sql, [
            'modname' => 'aidialogue',
            'cmid'    => $context->instanceid,
        ]);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('aidialogue', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $sessions = $DB->get_records(
                'aidialogue_session',
                ['aidialogueid' => $cm->instance, 'userid' => $userid],
                'attemptnumber ASC',
            );

            foreach ($sessions as $session) {
                $subcontext = [
                    get_string('privacy:sessionsubcontext', 'aidialogue', $session->attemptnumber),
                ];

                $turns = $DB->get_records(
                    'aidialogue_turn',
                    ['sessionid' => $session->id],
                    'turnnumber ASC',
                );

                $results = $DB->get_records(
                    'aidialogue_criterion_result',
                    ['sessionid' => $session->id],
                );

                $exportdata = (object) [
                    'attemptnumber' => $session->attemptnumber,
                    'status'        => $session->status,
                    'studentreport' => $session->studentreport,
                    'teacherreport' => $session->teacherreport,
                    'aigrade'       => $session->aigrade,
                    'teachergrade'  => $session->teachergrade,
                    'earlyexit'     => transform::yesno($session->earlyexit),
                    'timecreated'   => transform::datetime($session->timecreated),
                    'timestarted'   => $session->timestarted
                        ? transform::datetime($session->timestarted)
                        : null,
                    'timefinished'  => $session->timefinished
                        ? transform::datetime($session->timefinished)
                        : null,
                    'turns'         => array_values(array_map(
                        fn($t) => (object) [
                            'role'        => $t->role,
                            'move'        => $t->move,
                            'content'     => $t->content,
                            'timecreated' => transform::datetime($t->timecreated),
                        ],
                        $turns,
                    )),
                    'criterionresults' => array_values(array_map(
                        fn($r) => (object) [
                            'status'   => $r->status,
                            'evidence' => $r->evidence,
                        ],
                        $results,
                    )),
                ];

                writer::with_context($context)->export_data($subcontext, $exportdata);
            }
        }
    }

    /**
     * Delete all personal data for all users in the specified context.
     *
     * @param \context $context Context to delete data from.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('aidialogue', $context->instanceid);
        if (!$cm) {
            return;
        }

        self::delete_sessions_and_children($DB->get_fieldset_select(
            'aidialogue_session',
            'id',
            'aidialogueid = :id',
            ['id' => $cm->instance],
        ));
    }

    /**
     * Delete all personal data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('aidialogue', $context->instanceid);
            if (!$cm) {
                continue;
            }

            self::delete_sessions_and_children($DB->get_fieldset_select(
                'aidialogue_session',
                'id',
                'aidialogueid = :aid AND userid = :uid',
                ['aid' => $cm->instance, 'uid' => $userid],
            ));
        }
    }

    /**
     * Delete multiple users' data within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('aidialogue', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$useridssql, $useridsparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $sessionids = $DB->get_fieldset_select(
            'aidialogue_session',
            'id',
            "aidialogueid = :aid AND userid {$useridssql}",
            array_merge(['aid' => $cm->instance], $useridsparams),
        );

        self::delete_sessions_and_children($sessionids);
    }

    /**
     * Delete the given sessions together with their child turn and criterion result rows.
     *
     * @param int[] $sessionids Session IDs to remove. Empty array is a no-op.
     */
    protected static function delete_sessions_and_children(array $sessionids): void {
        global $DB;

        if (empty($sessionids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($sessionids);
        $DB->delete_records_select('aidialogue_turn', "sessionid {$insql}", $inparams);
        $DB->delete_records_select('aidialogue_criterion_result', "sessionid {$insql}", $inparams);
        $DB->delete_records_select('aidialogue_session', "id {$insql}", $inparams);
    }
}
