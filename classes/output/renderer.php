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
 * Renderer for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue\output;

use html_writer;
use mod_aidialogue\local\activity_config;

/**
 * Renderer for mod_aidialogue report and review pages.
 */
class renderer extends \plugin_renderer_base {

    /**
     * Return a circular status icon for a criterion result.
     *
     * @param string $status One of: met, partial, limit, abandoned, pending, in_progress.
     * @return string HTML badge element.
     */
    public function report_status_icon(string $status): string {
        $labelmap = [
            'met'         => 'criteriastatus_met',
            'partial'     => 'criteriastatus_partial',
            'limit'       => 'criteriastatus_limit',
            'abandoned'   => 'criteriastatus_abandoned',
            'in_progress' => 'criteriastatus_inprogress',
            'pending'     => 'criteriastatus_pending',
        ];
        $label = get_string($labelmap[$status] ?? 'criteriastatus_pending', 'aidialogue');
        $sr    = html_writer::tag('span', $label, ['class' => 'sr-only visually-hidden']);
        $base  = 'badge rounded-circle d-inline-flex align-items-center justify-content-center aidialogue-status-icon';
        switch ($status) {
            case 'met':
                $icon = html_writer::tag('i', '', ['class' => 'fa fa-check fa-fw', 'aria-hidden' => 'true']);
                return $sr . html_writer::tag('span', $icon, ['class' => "$base bg-success text-white"]);
            case 'partial':
                $icon = html_writer::tag('i', '', ['class' => 'fa fa-exclamation fa-fw', 'aria-hidden' => 'true']);
                return $sr . html_writer::tag('span', $icon, ['class' => "$base bg-warning text-dark"]);
            case 'limit':
            case 'abandoned':
                $icon = html_writer::tag('i', '', ['class' => 'fa fa-times fa-fw', 'aria-hidden' => 'true']);
                return $sr . html_writer::tag('span', $icon, ['class' => "$base bg-danger text-white"]);
            default:
                $icon = html_writer::tag('i', '', ['class' => 'fa fa-minus fa-fw', 'aria-hidden' => 'true']);
                return $sr . html_writer::tag('span', $icon, ['class' => "$base bg-secondary text-white"]);
        }
    }

    /**
     * Return a Bloom's taxonomy level badge for a criterion.
     *
     * @param int $level Bloom's level constant (1=Analyse, 2=Evaluate, 4=Create, 8=Custom).
     * @return string HTML badge element.
     */
    public function review_bloom_badge(int $level): string {
        $map = [
            activity_config::BLOOMS_ANALYSE  => ['bloom_analyse',  'bg-primary'],
            activity_config::BLOOMS_EVALUATE => ['bloom_evaluate', 'bg-success'],
            activity_config::BLOOMS_CREATE   => ['bloom_create',   'bg-warning'],
            activity_config::BLOOMS_CUSTOM   => ['bloom_custom',   'bg-secondary'],
        ];
        [$stringkey, $cls] = $map[$level] ?? ['bloom_custom', 'bg-secondary'];
        return html_writer::tag('span', get_string($stringkey, 'aidialogue'), ['class' => "badge $cls bg-opacity-50 text-dark fw-normal aidialogue-badge-sm"]);
    }

    /**
     * Return a status badge for a criterion result.
     *
     * @param string $status One of: met, partial, limit, pending, in_progress, abandoned.
     * @return string HTML badge element.
     */
    public function review_criterion_status_badge(string $status): string {
        $map = [
            'met'         => ['criteriastatus_met', 'bg-success'],
            'partial'     => ['criteriastatus_partial', 'bg-warning'],
            'limit'       => ['criteriastatus_limit', 'bg-danger'],
            'pending'     => ['criteriastatus_pending', 'bg-secondary'],
            'in_progress' => ['criteriastatus_inprogress', 'bg-info'],
            'abandoned'   => ['criteriastatus_abandoned', 'bg-secondary'],
        ];
        [$stringkey, $cls] = $map[$status] ?? ['criteriastatus_pending', 'bg-secondary'];
        return html_writer::tag('span', get_string($stringkey, 'aidialogue'), ['class' => "badge $cls bg-opacity-50 text-dark fw-normal aidialogue-badge-sm"]);
    }

    /**
     * Return a move-type label badge for an AI turn.
     *
     * @param string|null $move Move type stored on the turn, or null for student turns.
     * @return string HTML span element, or empty string for student turns.
     */
    public function review_move_badge(?string $move): string {
        if ($move === null) {
            return '';
        }
        $stringkey = 'move_' . $move;
        $label = get_string_manager()->string_exists($stringkey, 'aidialogue')
            ? get_string($stringkey, 'aidialogue')
            : s($move);
        return html_writer::tag('span', $label, [
            'class' => 'badge bg-light text-muted fw-normal me-1 aidialogue-move-badge',
        ]);
    }
}
