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
 * AI Dialogue class report page.
 *
 * Shows teachers a summary of all students' attempts:
 *   - Criteria overview with stacked progress bars (Met / Partial / Limit / Pending).
 *   - Student list table with per-criterion status icons and session status badge.
 *     Each row links to review.php for the individual session view.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aidialogue');
$aidialogue = $DB->get_record('aidialogue', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aidialogue:viewreport', $context);

$PAGE->set_url('/mod/aidialogue/report.php', ['id' => $cm->id]);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title($aidialogue->name);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();
$PAGE->set_secondary_active_tab('mod_aidialogue_report');
$PAGE->navbar->add(get_string('classreport', 'aidialogue'));
$PAGE->requires->js_call_amd('mod_aidialogue/report', 'init');

// Load criteria.
$criteria = array_values(
    $DB->get_records('aidialogue_criterion', ['aidialogueid' => $cm->instance], 'sortorder ASC')
);

// Load enrolled students who cannot view the report (i.e. actual students).
$allusers = get_enrolled_users(
    $context,
    'mod/aidialogue:view',
    0,
    'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
    'u.lastname ASC, u.firstname ASC'
);
$reporters = get_enrolled_users($context, 'mod/aidialogue:viewreport', 0, 'u.id');
$students = array_filter($allusers, function($user) use ($reporters) {
    return !isset($reporters[$user->id]);
});
$studentcount = count($students);

// Load the most recent session per student via SQL to avoid loading all attempts into memory.
$sql = "SELECT s.*
          FROM {aidialogue_session} s
          JOIN (SELECT userid, MAX(attemptnumber) AS maxattempt
                  FROM {aidialogue_session}
                 WHERE aidialogueid = :innerid
                 GROUP BY userid) latest
            ON latest.userid = s.userid
           AND latest.maxattempt = s.attemptnumber
         WHERE s.aidialogueid = :outerid";
$lastsessions = $DB->get_records_sql($sql, ['innerid' => $cm->instance, 'outerid' => $cm->instance]);
$lastsession = []; // Keyed by userid.
foreach ($lastsessions as $s) {
    $lastsession[(int)$s->userid] = $s;
}

// Load criterion results for complete sessions.
$sessionids = [];
foreach ($lastsession as $s) {
    if ($s->status === 'complete') {
        $sessionids[] = (int)$s->id;
    }
}

$criterionresults = []; // Keyed by userid, then criterionid.
if ($sessionids) {
    [$insql, $inparams] = $DB->get_in_or_equal($sessionids);
    $rawresults = $DB->get_records_select('aidialogue_criterion_result', "sessionid $insql", $inparams);
    // Build sessionid → userid map.
    $sessionusermap = [];
    foreach ($lastsession as $userid => $s) {
        $sessionusermap[(int)$s->id] = $userid;
    }
    foreach ($rawresults as $r) {
        $userid = $sessionusermap[(int)$r->sessionid] ?? null;
        if ($userid !== null) {
            $criterionresults[$userid][(int)$r->criterionid] = $r;
        }
    }
}

// Last attempt timestamp across all sessions.
$lasttime = null;
foreach ($lastsession as $s) {
    if ($s->timecreated > $lasttime) {
        $lasttime = (int)$s->timecreated;
    }
}

// Criteria aggregate stats: [criterionid][status] = count.
$criteriastats = [];
foreach ($criteria as $criterion) {
    $criteriastats[$criterion->id] = ['met' => 0, 'partial' => 0, 'limit' => 0, 'pending' => 0];
}
// Students with a complete session contribute their criterion results.
foreach ($criterionresults as $userid => $userresults) {
    foreach ($userresults as $cid => $r) {
        if (isset($criteriastats[$cid])) {
            if ($r->status === 'met') {
                $criteriastats[$cid]['met']++;
            } else if ($r->status === 'partial') {
                $criteriastats[$cid]['partial']++;
            } else if ($r->status === 'limit' || $r->status === 'abandoned') {
                $criteriastats[$cid]['limit']++;
            } else {
                $criteriastats[$cid]['pending']++;
            }
        }
    }
}
// Students without a complete session count as pending for all criteria.
$withresults    = count($criterionresults);
$withoutresults = max(0, $studentcount - $withresults);
foreach ($criteriastats as $cid => $stats) {
    $criteriastats[$cid]['pending'] += $withoutresults;
}

// Output.
$renderer = $PAGE->get_renderer('mod_aidialogue');
echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('classreport', 'aidialogue'));

$nstudentskey = $studentcount === 1 ? 'nstudent' : 'nstudents';
$subtitle = get_string($nstudentskey, 'aidialogue', $studentcount);
if ($lasttime) {
    $subtitle .= ' &middot; ' . get_string('lastattempt', 'aidialogue') . ' '
        . userdate($lasttime, get_string('strftimedatefullshort', 'langconfig'));
}
echo html_writer::tag('p', $subtitle, ['class' => 'text-muted mb-3']);

// Criteria overview card.
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');

echo html_writer::tag(
    'h3',
    get_string('criteriaoverview', 'aidialogue')
    . html_writer::tag(
        'small',
        get_string($nstudentskey, 'aidialogue', $studentcount),
        ['class' => 'float-end text-muted fw-normal aidialogue-meta']
    ),
    ['class' => 'card-title h5 mb-4']
);

foreach ($criteria as $idx => $criterion) {
    $stats  = $criteriastats[$criterion->id];
    $metpct = $studentcount > 0 ? ($stats['met'] / $studentcount * 100) : 0;
    $parpct = $studentcount > 0 ? ($stats['partial'] / $studentcount * 100) : 0;
    $limpct = $studentcount > 0 ? ($stats['limit'] / $studentcount * 100) : 0;
    $penpct = max(0, 100 - $metpct - $parpct - $limpct);

    echo html_writer::start_div('mb-4');

    // Criterion label row.
    $labelhtml = html_writer::tag('span', ($idx + 1) . '. ', ['class' => 'text-muted fw-semibold flex-shrink-0'])
        . html_writer::tag('span', s($criterion->description), ['class' => 'fs-6 fw-semibold']);
    if ($studentcount > 0 && $metpct < 50) {
        $labelhtml .= ' ' . html_writer::tag(
            'span',
            '&#9873; ' . get_string('moststruggled', 'aidialogue'),
            ['class' => 'badge bg-warning text-dark ms-2 fw-normal bg-opacity-25 aidialogue-badge-sm']
        );
    }
    $mettext  = get_string('metofn', 'aidialogue', ['met' => $stats['met'], 'total' => $studentcount]);
    $metlabel = html_writer::tag('span', $mettext, ['class' => 'text-muted small']);
    echo html_writer::tag(
        'div',
        $labelhtml . html_writer::tag('span', $metlabel, ['class' => 'float-end']),
        ['class' => 'small fw-semibold mb-1']
    );

    // Stacked progress bar.
    $progresslabel = s($criterion->description) . ': '
        . get_string('metofn', 'aidialogue', ['met' => $stats['met'], 'total' => $studentcount]);
    echo html_writer::start_tag('div', [
        'class'         => 'progress my-3 aidialogue-progress',
        'role'          => 'progressbar',
        'aria-valuenow' => round($metpct, 2),
        'aria-valuemin' => '0',
        'aria-valuemax' => '100',
        'aria-label'    => $progresslabel,
    ]);
    if ($metpct > 0) {
        echo html_writer::tag('div', '', [
            'class' => 'progress-bar bg-success',
            'style' => 'width:' . round($metpct, 2) . '%',
            'title' => get_string('legendmet', 'aidialogue'),
        ]);
    }
    if ($parpct > 0) {
        echo html_writer::tag('div', '', [
            'class' => 'progress-bar bg-warning',
            'style' => 'width:' . round($parpct, 2) . '%',
            'title' => get_string('legendpartial', 'aidialogue'),
        ]);
    }
    if ($limpct > 0) {
        echo html_writer::tag('div', '', [
            'class' => 'progress-bar bg-danger',
            'style' => 'width:' . round($limpct, 2) . '%',
            'title' => get_string('legendlimit', 'aidialogue'),
        ]);
    }
    if ($penpct > 0) {
        echo html_writer::tag('div', '', [
            'class' => 'progress-bar bg-secondary',
            'style' => 'width:' . round($penpct, 2) . '%',
            'title' => get_string('legendpending', 'aidialogue'),
        ]);
    }
    echo html_writer::end_tag('div');

    echo html_writer::end_div();
}

// Legend.
echo html_writer::start_tag('div', ['class' => 'd-flex gap-3 small text-muted mt-3 pt-2 border-top']);
foreach (
    [
        ['bg-success', get_string('legendmet', 'aidialogue')],
        ['bg-warning', get_string('legendpartial', 'aidialogue')],
        ['bg-danger', get_string('legendlimit', 'aidialogue')],
        ['bg-secondary', get_string('legendpending', 'aidialogue')],
    ] as [$cls, $label]
) {
    $swatch = html_writer::tag('span', '&nbsp;&nbsp;&nbsp;', [
        'class'       => "badge $cls me-1 aidialogue-swatch",
        'aria-hidden' => 'true',
    ]);
    echo html_writer::tag('span', $swatch . $label);
}
echo html_writer::end_tag('div');

echo html_writer::end_div(); // End card-body.
echo html_writer::end_div(); // End card.

// Student list card.
echo html_writer::start_div('card');
echo html_writer::start_div('card-body');

echo html_writer::tag(
    'h3',
    get_string('studentlist', 'aidialogue')
    . html_writer::tag(
        'small',
        get_string('clickrowfordetails', 'aidialogue'),
        ['class' => 'float-end text-muted fw-normal aidialogue-meta']
    ),
    ['class' => 'card-title h5 mb-4']
);

if (!$students) {
    echo html_writer::tag('p', get_string('nostudents', 'aidialogue'), ['class' => 'text-muted']);
} else {
    echo html_writer::start_tag('table', ['class' => 'table table-hover align-middle mb-0']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag(
        'th',
        get_string('name'),
        ['class' => 'small text-uppercase text-muted fw-semibold', 'scope' => 'col']
    );
    foreach ($criteria as $idx => $criterion) {
        echo html_writer::tag('th', 'C' . ($idx + 1), [
            'class' => 'text-center small text-uppercase text-muted fw-semibold',
            'title' => s($criterion->description),
            'scope' => 'col',
        ]);
    }
    echo html_writer::tag(
        'th', get_string('status'),
        ['class' => 'small text-uppercase text-muted fw-semibold', 'scope' => 'col']
    );
    echo html_writer::tag('th', '', ['scope' => 'col']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    echo html_writer::start_tag('tbody');
    foreach ($students as $student) {
        $session     = $lastsession[$student->id] ?? null;
        $userresults = $criterionresults[$student->id] ?? [];
        $reviewurl   = null;
        if ($session) {
            $reviewurl = new moodle_url('/mod/aidialogue/review.php', [
                'id'      => $cm->id,
                'session' => $session->id,
            ]);
        }

        $rowattrs = [];
        if ($reviewurl) {
            $rowattrs['class']    = 'aidialogue-row-link';
            $rowattrs['data-href'] = $reviewurl->out(false);
            $rowattrs['tabindex'] = '0';
            $rowattrs['role']     = 'link';
        }
        echo html_writer::start_tag('tr', $rowattrs);
        echo html_writer::tag('td', html_writer::tag('strong', s(fullname($student))));

        foreach ($criteria as $criterion) {
            $result = $userresults[$criterion->id] ?? null;
            $icon   = $result
                ? $renderer->report_status_icon($result->status)
                : html_writer::tag('span', '&ndash;', ['class' => 'text-muted']);
            echo html_writer::tag('td', $icon, ['class' => 'text-center']);
        }

        // Session status badge.
        if (!$session || $session->status === 'pending') {
            $badge = html_writer::tag(
                'span', get_string('statuspending', 'aidialogue'), ['class' => 'badge bg-secondary']
            );
        } else if ($session->status === 'complete') {
            $badge = html_writer::tag(
                'span', get_string('statuscomplete', 'aidialogue'), ['class' => 'badge bg-success']
            );
        } else {
            $badge = html_writer::tag(
                'span', get_string('statusactive', 'aidialogue'), ['class' => 'badge bg-primary']
            );
        }
        echo html_writer::tag('td', $badge);
        echo html_writer::tag('td', $reviewurl ? '›' : '', ['class' => 'text-muted text-end pe-2']);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo html_writer::end_div(); // End card-body.
echo html_writer::end_div(); // End card.

echo $OUTPUT->footer();
