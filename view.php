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
 * Renders three states:
 *   no_session — no active attempt; show "Start Session" button.
 *   active     — session in progress; show chat transcript + input.
 *   complete   — session finished; show student report and grade.
 *
 * NOTE: This is a bare/functional UI for development and engine testing.
 * Bea's design will replace the HTML/CSS here without touching the engine.
 *
 * The "Start Session" button POSTs to this page with action=start.
 * Subsequent chat messages are handled by the mod_aidialogue/chat AMD module,
 * which calls the mod_aidialogue external functions.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/mod/aidialogue/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\session_manager;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\dialogue_engine;

$id     = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aidialogue');
$aidialogue    = $DB->get_record('aidialogue', ['id' => $cm->instance], '*', MUST_EXIST);

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

// Build the engine.
$config  = activity_config::load_from_db($cm->instance);
$engine  = new dialogue_engine(
    new session_manager(),
    new ai_client(),
    new prompt_builder(),
);

// Handle actions.
$errormessage = '';

if ($action === 'start' && confirm_sesskey()) {
    try {
        $result  = $engine->start_session($config, $USER->id);
        $session = $result['session'];
        // Reload page to show active state with the opening message already in DB.
        redirect(new moodle_url('/mod/aidialogue/view.php', ['id' => $cm->id]));
    } catch (\moodle_exception $e) {
        $errormessage = $e->getMessage();
    }
}

// Determine current state.
$state = $engine->get_session_state($config, $USER->id);

// Output.
echo $OUTPUT->header();

// Error banner (if any action failed).
if ($errormessage) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('error')) . ': ' . s($errormessage),
        'alert alert-danger'
    );
}

// STATE A: No active session.
if ($state['state'] === 'no_session') {
    $attemptcount = $state['attempt_count'];
    $maxattempts   = $config->maxattempts;
    $last          = $state['last_completed'];

    echo html_writer::start_div('aidialogue-notsession p-4');

    // Show previous attempt result if any.
    if ($last !== null) {
        echo html_writer::tag('h4', get_string('previousattempt', 'aidialogue'));
        echo html_writer::tag(
            'p',
            get_string('attemptcount', 'aidialogue', ['used' => $attemptcount, 'max' => $maxattempts > 0 ? $maxattempts : '∞'])
        );
        if (!empty($last->studentreport)) {
            echo html_writer::tag('h5', get_string('yourfeedback', 'aidialogue'));
            echo html_writer::tag('div', format_text($last->studentreport, FORMAT_PLAIN), ['class' => 'border p-3 mb-3 bg-light']);
        }
        if (!empty($last->aigrade)) {
            echo html_writer::tag('p', get_string('aigrade', 'aidialogue') . ': ' . round($last->aigrade, 1) . '%');
        }
    } else {
        echo html_writer::tag('p', get_string('nosessionyet', 'aidialogue'));
        if ($maxattempts > 0) {
            echo html_writer::tag('p', get_string('attemptsallowed', 'aidialogue', $maxattempts));
        }
    }

    if ($state['can_start']) {
        $starturl = new moodle_url('/mod/aidialogue/view.php', ['id' => $cm->id, 'action' => 'start', 'sesskey' => sesskey()]);
        echo html_writer::tag(
            'form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'start'])
            . html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => get_string('startsession', 'aidialogue'), 'class' => 'btn btn-primary',
            ]),
            ['method' => 'post', 'action' => new moodle_url('/mod/aidialogue/view.php')]
        );
    } else {
        echo html_writer::tag('p', html_writer::tag('em', get_string('noattemptsremaining', 'aidialogue')));
    }

    echo html_writer::end_div();
}

// STATE B: Active session — chat UI.
if ($state['state'] === 'active') {
    $session = $state['session'];
    $turns   = $state['turns'];

    echo html_writer::start_div('aidialogue-chat p-4');

    // Transcript.
    echo html_writer::tag('h4', get_string('conversation', 'aidialogue'));
    echo html_writer::start_div('aidialogue-transcript bg-light border p-3 mb-3', ['id' => 'aidialogue-transcript']);

    foreach ($turns as $turn) {
        $label   = $turn->role === 'student' ? get_string('you', 'aidialogue') : get_string('ai', 'aidialogue');
        $classes = $turn->role === 'student' ? 'mb-2 text-end' : 'mb-2';
        $bubble  = html_writer::tag(
            'span',
            s($turn->content),
            ['class' => ($turn->role === 'student' ? 'badge bg-primary' : 'badge bg-secondary') . ' text-wrap aidialogue-bubble']
        );
        echo html_writer::tag('div', html_writer::tag('small', $label) . html_writer::tag('div', $bubble), ['class' => $classes]);
    }

    echo html_writer::end_div(); // End transcript.

    // Input form (driven by the mod_aidialogue/chat AMD module).
    echo html_writer::start_tag('div', ['class' => 'd-flex gap-2 align-items-start', 'id' => 'aidialogue-input-area']);
    echo html_writer::tag(
        'textarea',
        '',
        [
            'id'          => 'aidialogue-input',
            'class'       => 'form-control aidialogue-input-flex',
            'rows'        => '3',
            'placeholder' => get_string('typeyourmessage', 'aidialogue'),
        ]
    );
    echo html_writer::tag(
        'button',
        get_string('send', 'aidialogue'),
        ['id' => 'aidialogue-send', 'class' => 'btn btn-primary', 'type' => 'button']
    );
    echo html_writer::end_tag('div');

    echo html_writer::tag(
        'button',
        get_string('endsession', 'aidialogue'),
        ['id' => 'aidialogue-end', 'class' => 'btn btn-outline-danger btn-sm mt-2', 'type' => 'button']
    );

    echo html_writer::tag('p', '', ['id' => 'aidialogue-status', 'class' => 'text-muted mt-2 small']);

    echo html_writer::end_div(); // End aidialogue-chat.

    // Drive the chat UI through the AMD module, which calls the registered external functions.
    $PAGE->requires->js_call_amd('mod_aidialogue/chat', 'init', [(int) $session->id, (int) $cm->id]);
}

// STATE C: Session complete — results screen.
if ($state['state'] === 'complete') {
    $session       = $state['last_completed'];
    $attemptcount = $state['attempt_count'];
    $maxattempts   = $config->maxattempts;

    echo html_writer::start_div('aidialogue-complete p-4');
    echo html_writer::tag('h4', get_string('sessioncomplete', 'aidialogue'));

    // Early-exit info banner.
    if (!empty($session->earlyexit)) {
        echo html_writer::div(get_string('sessionendedearlyinfo', 'aidialogue'), 'alert alert-warning');
    }

    // Pass/fail banner — green if grade ≥ 50.
    if (!empty($session->aigrade)) {
        $passed  = $session->aigrade >= 50;
        $bannerclass = $passed ? 'alert alert-success' : 'alert alert-warning';
        $bannertext  = $passed ? get_string('passed', 'aidialogue') : get_string('notpassed', 'aidialogue');
        $bannergrade = round($session->aigrade, 1) . '%';
        echo html_writer::tag(
            'div',
            $bannertext . ' — ' . get_string('aigrade', 'aidialogue') . ': ' . $bannergrade,
            ['class' => $bannerclass]
        );
    }

    // Student report.
    if (!empty($session->studentreport)) {
        echo html_writer::tag('h5', get_string('yourfeedback', 'aidialogue'));
        echo html_writer::tag('div', format_text($session->studentreport, FORMAT_PLAIN), ['class' => 'border p-3 mb-3 bg-light']);
    }

    // Attempt info + start-again button.
    echo html_writer::tag('p', get_string('attemptcount', 'aidialogue', [
        'used' => $attemptcount,
        'max'  => $maxattempts > 0 ? $maxattempts : '∞',
    ]));

    if ($state['can_start']) {
        echo html_writer::tag(
            'form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'start'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => get_string('tryagain', 'aidialogue'), 'class' => 'btn btn-outline-primary',
            ]),
            ['method' => 'post', 'action' => new moodle_url('/mod/aidialogue/view.php')]
        );
    } else {
        echo html_writer::tag('p', html_writer::tag('em', get_string('noattemptsremaining', 'aidialogue')));
    }

    echo html_writer::end_div();
}

echo $OUTPUT->footer();
