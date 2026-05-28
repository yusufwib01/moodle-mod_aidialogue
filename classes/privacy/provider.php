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
 * This plugin stores: aidialogue_session, aidialogue_turn, aidialogue_criterion_result.
 * Each session is linked to a userid and contains the conversation history and outcomes.
 * The conversation history (aidialogue_turn) includes both student messages and AI replies.
 * The criterion results (aidialogue_criterion_result) include the outcome and evidence for each criterion.
 *
 * Student message content is also transmitted to a configured external AI
 * endpoint to generate responses. This is declared as an external data flow.
 */
class provider implements
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe all personal data stored and transmitted by this plugin.
     *
     * @param collection $collection Metadata collection to populate.
     * @return collection
     */
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('aidialogue_session', [
            'userid'        => 'privacy:metadata:aidialogue_session:userid',
            'attemptnumber' => 'privacy:metadata:aidialogue_session:attemptnumber',
            'status'        => 'privacy:metadata:aidialogue_session:status',
            'studentreport' => 'privacy:metadata:aidialogue_session:studentreport',
            'teacherreport' => 'privacy:metadata:aidialogue_session:teacherreport',
            'aigrade'       => 'privacy:metadata:aidialogue_session:aigrade',
            'earlyexit'     => 'privacy:metadata:aidialogue_session:earlyexit',
            'timecreated'   => 'privacy:metadata:aidialogue_session:timecreated',
            'timestarted'   => 'privacy:metadata:aidialogue_session:timestarted',
            'timefinished'  => 'privacy:metadata:aidialogue_session:timefinished',
        ], 'privacy:metadata:aidialogue_session');

        $collection->add_database_table('aidialogue_turn', [
            'role'        => 'privacy:metadata:aidialogue_turn:role',
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
     * Get the list of contexts that contain user information for a specific user.
     *
     * @param int $userid User ID.
     * @return contextlist
     */
    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                                          AND ctx.contextlevel = :contextlevel
                  JOIN {aidialogue} a ON a.id = cm.instance
                  JOIN {aidialogue_session} s ON s.aidialogueid = a.id
                                             AND s.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users.
     */
    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $sql = "SELECT s.userid
                  FROM {aidialogue_session} s
                  JOIN {aidialogue} a ON a.id = s.aidialogueid
                  JOIN {course_modules} cm ON cm.instance = a.id
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified user in the contexts provided.
     *
     * @param approved_contextlist $contextlist Approved contextlist.
     */
    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
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

                $exportdata = (object) [
                    'attemptnumber' => $session->attemptnumber,
                    'status'        => $session->status,
                    'studentreport' => $session->studentreport,
                    'teacherreport' => $session->teacherreport,
                    'aigrade'       => $session->aigrade,
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
                            'content'     => $t->content,
                            'timecreated' => transform::datetime($t->timecreated),
                        ],
                        $turns,
                    )),
                ];

                writer::with_context($context)->export_data($subcontext, $exportdata);
            }
        }
    }

    /**
     * Delete all personal data for all users in the given context.
     *
     * @param \context $context Context to delete data for.
     */
    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('aidialogue', $context->instanceid);
        if (!$cm) {
            return;
        }

        $sessionids = $DB->get_fieldset_select(
            'aidialogue_session',
            'id',
            'aidialogueid = :id',
            ['id' => $cm->instance],
        );

        if (!empty($sessionids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($sessionids);
            $DB->delete_records_select('aidialogue_turn', "sessionid {$insql}", $inparams);
            $DB->delete_records_select('aidialogue_criterion_result', "sessionid {$insql}", $inparams);
        }

        $DB->delete_records('aidialogue_session', ['aidialogueid' => $cm->instance]);
    }

    /**
     * Delete personal data for the given approved contextlist.
     *
     * @param approved_contextlist $contextlist Approved contextlist.
     */
    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('aidialogue', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $sessionids = $DB->get_fieldset_select(
                'aidialogue_session',
                'id',
                'aidialogueid = :aid AND userid = :uid',
                ['aid' => $cm->instance, 'uid' => $userid],
            );

            if (!empty($sessionids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($sessionids);
                $DB->delete_records_select('aidialogue_turn', "sessionid {$insql}", $inparams);
                $DB->delete_records_select('aidialogue_criterion_result', "sessionid {$insql}", $inparams);
                $DB->delete_records_select('aidialogue_session', "id {$insql}", $inparams);
            }
        }
    }

    /**
     * Delete multiple users' data within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information.
     */
    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel !== CONTEXT_MODULE) {
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

        [$useridssql, $useridsparams] = $DB->get_in_or_equal($userids);
        $sessionids = $DB->get_fieldset_select(
            'aidialogue_session',
            'id',
            "aidialogueid = :aid AND userid {$useridssql}",
            array_merge(['aid' => $cm->instance], $useridsparams),
        );

        if (!empty($sessionids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($sessionids);
            $DB->delete_records_select('aidialogue_turn', "sessionid {$insql}", $inparams);
            $DB->delete_records_select('aidialogue_criterion_result', "sessionid {$insql}", $inparams);
            $DB->delete_records_select('aidialogue_session', "id {$insql}", $inparams);
        }
    }
}
