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

namespace mod_aidialogue\local;

/**
 * Immutable value object representing one criterion within an activity config.
 *
 * Constructed by activity_config — do not instantiate directly outside of that class.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Moodle HQ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class criterion_config {

    /** @var int DB row ID (aidialogue_criterion.id). 0 for stub criteria. */
    public readonly int $id;

    /** @var int 1-based processing order. */
    public readonly int $sortorder;

    /** @var int Bloom's level — one of the activity_config::BLOOMS_* constants. */
    public readonly int $bloomslevel;

    /**
     * @var string Evidence description.
     * For BLOOMS_CUSTOM this is used verbatim as the AI instruction.
     * For other levels it describes the evidence required; the prompt_builder
     * combines it with level-specific probe language.
     */
    public readonly string $description;

    /** @var int Minimum student turns before the criterion can be closed. */
    public readonly int $minturns;

    /** @var int Maximum student turns; AI is forced to close at this limit. */
    public readonly int $maxturns;

    /**
     * @param int    $id          DB row ID.
     * @param int    $sortorder   1-based processing order.
     * @param int    $bloomslevel One of activity_config::BLOOMS_* constants.
     * @param string $description Evidence description / custom instruction.
     * @param int    $minturns    Minimum turns before criterion can close.
     * @param int    $maxturns    Hard cap on turns for this criterion.
     */
    public function __construct(
        int $id,
        int $sortorder,
        int $bloomslevel,
        string $description,
        int $minturns,
        int $maxturns,
    ) {
        $this->id = $id;
        $this->sortorder = $sortorder;
        $this->bloomslevel = $bloomslevel;
        $this->description = $description;
        $this->minturns = $minturns;
        $this->maxturns = $maxturns;
    }
}
