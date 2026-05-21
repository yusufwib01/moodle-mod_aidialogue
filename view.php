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
 * AI Dialogue activity view page.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/mod/aidialogue/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aidialogue');
$aidialogue = $DB->get_record('aidialogue', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aidialogue:view', $context);

aidialogue_view($aidialogue, $course, $cm, $context);

$PAGE->set_url('/mod/aidialogue/view.php', ['id' => $cm->id]);
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title($course->shortname . ': ' . $aidialogue->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($aidialogue);

$activityheader = ['hidecompletion' => false];
if (!$PAGE->activityheader->is_title_allowed()) {
    $activityheader['title'] = '';
}
$PAGE->activityheader->set_attrs($activityheader);

echo $OUTPUT->header();

echo $OUTPUT->box(get_string('placeholder', 'aidialogue'), 'generalbox');

echo $OUTPUT->footer();
