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
 * AI Dialogue module upgrade code.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for mod_aidialogue.
 *
 * @param int $oldversion Version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_aidialogue_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026052100) {
        // Table: aidialogue (main activity instance record).
        $table = new xmldb_table('aidialogue');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('name', XMLDB_TYPE_CHAR, '1333', null, XMLDB_NOTNULL, null, null);
        $table->add_field('intro', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('introformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('knowledgetext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('maxattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completionpassed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completionexhausted', XMLDB_TYPE_INTEGER, '1', null, null, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course', XMLDB_INDEX_NOTUNIQUE, ['course']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: aidialogue_criterion (rubric criteria for an activity).
        $table = new xmldb_table('aidialogue_criterion');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('aidialogueid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bloomslevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('minturns', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('maxturns', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '2');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('aidialogueid', XMLDB_INDEX_NOTUNIQUE, ['aidialogueid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: aidialogue_session (one attempt per student per activity).
        $table = new xmldb_table('aidialogue_session');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('aidialogueid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attemptnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('studentreport', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('teacherreport', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('aigrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('teachergrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('aidialogueid_userid_attempt', XMLDB_INDEX_UNIQUE, ['aidialogueid', 'userid', 'attemptnumber']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: aidialogue_turn (one message per conversation turn).
        $table = new xmldb_table('aidialogue_turn');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('role', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('move', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('turnnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('sessionid_turnnumber', XMLDB_INDEX_UNIQUE, ['sessionid', 'turnnumber']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: aidialogue_criterion_result (per-criterion outcome per session).
        $table = new xmldb_table('aidialogue_criterion_result');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('sessionid_criterionid', XMLDB_INDEX_UNIQUE, ['sessionid', 'criterionid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026052100, 'aidialogue');
    }

    if ($oldversion < 2026052700) {
        $table = new xmldb_table('aidialogue_session');
        $field = new xmldb_field('earlyexit', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026052700, 'aidialogue');
    }

    return true;
}
