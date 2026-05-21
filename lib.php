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
 * Library of interface functions and constants for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * List of features supported in AI Dialogue module.
 *
 * @param string $feature FEATURE_xx constant for requested feature.
 * @return bool|null True if module supports feature, false if not, null if doesn't know.
 */
function aidialogue_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE => MOD_ARCHETYPE_OTHER,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_MOD_INTRO => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_BACKUP_MOODLE2 => false,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_COMMUNICATION,
        default => null,
    };
}

/**
 * Add new AI Dialogue instance.
 *
 * @param stdClass $data Form data.
 * @param mod_aidialogue_mod_form|null $mform The form instance.
 * @return int New instance id.
 */
function aidialogue_add_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $DB->insert_record('aidialogue', $data);

    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'aidialogue',
        $data->id,
        $completiontimeexpected,
    );

    return $data->id;
}

/**
 * Update AI Dialogue instance.
 *
 * @param stdClass $data Form data.
 * @param mod_aidialogue_mod_form $mform The form instance.
 * @return bool True on success.
 */
function aidialogue_update_instance($data, $mform) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    $DB->update_record('aidialogue', $data);

    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'aidialogue',
        $data->id,
        $completiontimeexpected,
    );

    return true;
}

/**
 * Delete AI Dialogue instance.
 *
 * @param int $id Instance id.
 * @return bool True on success.
 */
function aidialogue_delete_instance($id) {
    global $DB;

    if (!$aidialogue = $DB->get_record('aidialogue', ['id' => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance('aidialogue', $id);
    \core_completion\api::update_completion_date_event($cm->id, 'aidialogue', $id, null);

    $DB->delete_records('aidialogue', ['id' => $aidialogue->id]);

    return true;
}

/**
 * Mark the activity completed and trigger the course_module_viewed event.
 *
 * @param stdClass $aidialogue Activity instance.
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param stdClass $context Context object.
 */
function aidialogue_view($aidialogue, $course, $cm, $context) {
    $params = [
        'context' => $context,
        'objectid' => $aidialogue->id,
    ];

    $event = \mod_aidialogue\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('aidialogue', $aidialogue);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * List the actions that correspond to a view of this module.
 *
 * @return string[]
 */
function aidialogue_get_view_actions() {
    return ['view', 'view all'];
}

/**
 * List the actions that correspond to a post of this module.
 *
 * @return string[]
 */
function aidialogue_get_post_actions() {
    return ['update', 'add'];
}

/**
 * Return a list of page types.
 *
 * @param string $pagetype Current page type.
 * @param stdClass $parentcontext Block's parent context.
 * @param stdClass $currentcontext Current context of block.
 * @return array
 */
function aidialogue_page_type_list($pagetype, $parentcontext, $currentcontext) {
    return [
        'mod-aidialogue-*' => get_string('page-mod-aidialogue-x', 'aidialogue'),
    ];
}

/**
 * Check if the module has any update that affects the current user since a given time.
 *
 * @param cm_info $cm Course module data.
 * @param int $from The time to check updates from.
 * @param array $filter If we need to check only specific updates.
 * @return stdClass
 */
function aidialogue_check_updates_since(cm_info $cm, $from, $filter = []) {
    return course_check_module_updates_since($cm, $from, ['intro'], $filter);
}

/**
 * Given a course_module object, this function returns any "extra" information
 * that may be needed when printing this activity in a course listing.
 *
 * @param stdClass $coursemodule Course module object.
 * @return cached_cm_info|null
 */
function aidialogue_get_coursemodule_info($coursemodule) {
    global $DB;

    $aidialogue = $DB->get_record(
        'aidialogue',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat'
    );

    if (!$aidialogue) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $aidialogue->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('aidialogue', $aidialogue, $coursemodule->id, false);
    }

    return $info;
}
