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
 * AI Dialogue admin settings.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Muhammad Arnaldo <muhammad.arnaldo@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'mod_aidialogue/aiheader',
        get_string('aiheader', 'aidialogue'),
        get_string('aiheader_desc', 'aidialogue'),
    ));

    $settings->add(new admin_setting_configtext(
        'mod_aidialogue/aiurl',
        get_string('aiurl', 'aidialogue'),
        get_string('aiurl_desc', 'aidialogue'),
        'https://api.openai.com/v1',
        PARAM_URL,
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_aidialogue/aiapikey',
        get_string('aiapikey', 'aidialogue'),
        get_string('aiapikey_desc', 'aidialogue'),
        '',
    ));

    $settings->add(new admin_setting_configtext(
        'mod_aidialogue/aimodel',
        get_string('aimodel', 'aidialogue'),
        get_string('aimodel_desc', 'aidialogue'),
        'gpt-4o',
        PARAM_TEXT,
    ));
}
