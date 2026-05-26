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
 * Orchestrates the full conversation lifecycle for a single session.
 *
 * This class wires together activity_config, session_manager, ai_client,
 * and prompt_builder. It is the only place where the criterion loop logic lives.
 *
 * Public API (called from view.php and ajax/chat.php):
 *
 *   start_session()         — create session row, fire session_open AI turn
 *   process_student_turn()  — record student message, get AI response, advance state
 *   get_session_state()     — return all info needed to render the current UI state
 *
 * Internal flow for process_student_turn():
 *
 *   1. Record student turn.
 *   2. Determine current criterion (find first criterion_result with status
 *      'pending' or 'in_progress').
 *   3. Count student turns on this criterion.
 *   4. If at maxturns → force_close = true.
 *   5. Ask AI for probe/close response.
 *   6. Parse [MOVE:...] tag from AI response.
 *   7. Record AI turn with move.
 *   8. If move is criterion_close:
 *        a. Determine outcome (met / partial / limit) by re-asking AI.
 *        b. Update criterion_result.
 *        c. If more criteria remain → fire criterion_open AI turn.
 *        d. If no more criteria → fire session_close AI turn → generate reports → close session.
 *   9. Return the AI's visible response text + updated session state.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Moodle HQ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dialogue_engine {

    public function __construct(
        private readonly session_manager $sessionmanager,
        private readonly ai_client $aiclient,
        private readonly prompt_builder $promptbuilder,
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Create a new session and fire the AI's opening turn.
     *
     * Returns the session record and the AI's opening message text.
     *
     * @param activity_config $config Activity config (stub or real).
     * @param int             $userid Student user ID.
     * @return array{session: \stdClass, opening_message: string}
     * @throws \moodle_exception On DB error or AI failure.
     */
    public function start_session(activity_config $config, int $userid): array {
        // Create DB rows.
        $session = $this->sessionmanager->create_session($config->id, $userid, $config);

        try {
            // Ask AI for the session_open message.
            $first_criterion = $config->get_criterion(0);
            $messages = $this->promptbuilder->build_session_open_messages($config, $first_criterion);
            $rawresponse = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);

            ['move' => $move, 'content' => $content] = $this->promptbuilder->parse_move($rawresponse);

            // Normalise — AI should return session_open but accept any opening move.
            $move = $move ?? 'session_open';

            // Record AI turn (criterionid null for session-level turns).
            $this->sessionmanager->add_turn($session->id, null, 'ai', $move, $content);
        } catch (\Throwable $e) {
            // AI call failed — delete the orphaned session so the student sees
            // the Start button again rather than an empty chat.
            $this->sessionmanager->delete_session($session->id);
            throw $e;
        }

        // Mark session active (timestarted not set yet — that's set on first student turn).
        // Status stays 'pending' until student sends the first message.
        // (activate_session is called in process_student_turn.)

        return [
            'session'          => $session,
            'opening_message'  => $content,
        ];
    }

    /**
     * Process a student's message and return the AI's response.
     *
     * This is the main conversation loop step. Called from ajax/chat.php on
     * every student message submission.
     *
     * @param activity_config $config    Activity config.
     * @param \stdClass       $session   Current session record.
     * @param string          $content   Student's message text.
     * @return array{
     *   ai_message:   string,
     *   move:         string,
     *   is_complete:  bool,
     * }
     * @throws \moodle_exception On DB error or AI failure.
     */
    public function process_student_turn(
        activity_config $config,
        \stdClass $session,
        string $content,
    ): array {
        // Activate session on first student turn.
        if ($session->status === 'pending') {
            $this->sessionmanager->activate_session($session->id);
        }

        // Find the current criterion (first with status pending or in_progress).
        $current_criterion = $this->find_current_criterion($config, $session->id);

        if ($current_criterion === null) {
            // All criteria already resolved but session not yet closed — shouldn't
            // normally happen, but close defensively.
            return $this->do_session_close($config, $session);
        }

        // Mark criterion in_progress if it was pending.
        $results = $this->sessionmanager->get_criterion_results($session->id);
        $cr_status = $results[$current_criterion->id]->status ?? 'pending';
        if ($cr_status === 'pending') {
            $this->sessionmanager->update_criterion_result($session->id, $current_criterion->id, 'in_progress');
        }

        // Record the student's turn.
        $this->sessionmanager->add_turn(
            $session->id,
            $current_criterion->id,
            'student',
            null,
            $content,
        );

        // Count student turns on this criterion (including the one just added).
        $studentturns = $this->sessionmanager->count_student_turns_for_criterion(
            $session->id,
            $current_criterion->id,
        );

        $force_close = $studentturns >= $current_criterion->maxturns;

        // Get all turns for the full conversation context.
        $all_turns = $this->sessionmanager->get_turns($session->id);

        // Ask AI for its response.
        $messages = $this->promptbuilder->build_probe_messages(
            $config,
            $current_criterion,
            $all_turns,
            $studentturns,
            $force_close,
        );
        $rawresponse = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);

        ['move' => $move, 'content' => $ai_content] = $this->promptbuilder->parse_move($rawresponse);

        // Safety fallback — if AI forgot the tag.
        if ($move === null) {
            $move = $force_close ? 'criterion_close' : 'probe_deeper';
        }

        // Record AI turn.
        $this->sessionmanager->add_turn(
            $session->id,
            $current_criterion->id,
            'ai',
            $move,
            $ai_content,
        );

        // Handle criterion_close.
        if ($move === 'criterion_close' || $force_close) {
            $outcome = $this->evaluate_criterion_outcome($config, $session, $current_criterion, $force_close);
            $evidence = $this->extract_evidence($config, $session->id, $current_criterion);
            $this->sessionmanager->update_criterion_result(
                $session->id,
                $current_criterion->id,
                $outcome,
                $evidence,
            );

            // Find next criterion.
            $next_criterion = $this->find_next_criterion($config, $session->id, $current_criterion);

            if ($next_criterion !== null) {
                // More criteria — fire criterion_open.
                $all_turns_after = $this->sessionmanager->get_turns($session->id);
                $open_messages   = $this->promptbuilder->build_criterion_open_messages(
                    $config,
                    $next_criterion,
                    $all_turns_after,
                );
                $open_raw = $this->aiclient->chat(
                    $config->aiurl,
                    $config->aiapikey,
                    $config->aimodel,
                    $open_messages,
                );
                ['move' => $open_move, 'content' => $open_content] = $this->promptbuilder->parse_move($open_raw);
                $open_move = $open_move ?? 'criterion_open';

                $this->sessionmanager->add_turn(
                    $session->id,
                    $next_criterion->id,
                    'ai',
                    $open_move,
                    $open_content,
                );

                // Return combined message: criterion close response + criterion open.
                return [
                    'ai_message'  => $ai_content . "\n\n" . $open_content,
                    'move'        => 'criterion_open',
                    'is_complete' => false,
                ];
            }

            // No more criteria — close the session.
            return $this->do_session_close($config, $session);
        }

        return [
            'ai_message'  => $ai_content,
            'move'        => $move,
            'is_complete' => false,
        ];
    }

    /**
     * Return all information needed to render the current view state.
     *
     * Called by view.php to determine which of the three UI states to show:
     *   - no_session  — no active or pending session; show "Start" button
     *   - active      — session in progress; show chat UI
     *   - complete    — session finished; show results screen
     *
     * @param activity_config $config    Activity config.
     * @param int             $userid    Student user ID.
     * @return array{
     *   state:           string,
     *   session:         \stdClass|null,
     *   turns:           array,
     *   attempt_count:   int,
     *   can_start:       bool,
     *   last_completed:  \stdClass|null,
     * }
     */
    public function get_session_state(activity_config $config, int $userid): array {
        $attempt_count  = $this->sessionmanager->count_attempts($config->id, $userid);
        $active_session = $this->sessionmanager->get_active_session($config->id, $userid);
        $last_completed = $this->sessionmanager->get_last_completed_session($config->id, $userid);

        $can_start = $config->maxattempts === 0 || $attempt_count < $config->maxattempts;

        if ($active_session !== null) {
            $turns = $this->sessionmanager->get_turns($active_session->id);
            return [
                'state'          => 'active',
                'session'        => $active_session,
                'turns'          => $turns,
                'attempt_count'  => $attempt_count,
                'can_start'      => false, // already in a session
                'last_completed' => $last_completed,
            ];
        }

        if ($last_completed !== null) {
            // Show results screen after any completed session.
            // Try Again button is conditionally rendered based on can_start.
            return [
                'state'          => 'complete',
                'session'        => $last_completed,
                'turns'          => [],
                'attempt_count'  => $attempt_count,
                'can_start'      => $can_start,
                'last_completed' => $last_completed,
            ];
        }

        return [
            'state'          => 'no_session',
            'session'        => null,
            'turns'          => [],
            'attempt_count'  => $attempt_count,
            'can_start'      => $can_start,
            'last_completed' => $last_completed,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * End a session at the student's request before all criteria are completed.
     *
     * Marks incomplete criteria as 'abandoned', then runs the normal close
     * pipeline with the earlyexit flag set so prompts reflect the early exit.
     *
     * @param activity_config $config  Activity config.
     * @param \stdClass       $session Current session record.
     * @return array  Same shape as process_student_turn() return.
     */
    public function end_session_early(activity_config $config, \stdClass $session): array {
        $this->sessionmanager->abandon_remaining_criteria($session->id);
        return $this->do_session_close($config, $session, earlyexit: true);
    }

    /**
     * Fire session_close AI turn, generate reports, and close the session.
     *
     * @param activity_config $config    Activity config.
     * @param \stdClass       $session   Session record.
     * @param bool            $earlyexit True when the student manually ended the session.
     * @return array  Same shape as process_student_turn() return.
     */
    private function do_session_close(
        activity_config $config,
        \stdClass $session,
        bool $earlyexit = false,
    ): array {
        $all_turns = $this->sessionmanager->get_turns($session->id);

        // session_close message.
        $close_messages = $this->promptbuilder->build_session_close_messages($config, $all_turns, $earlyexit);
        $close_raw      = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $close_messages);
        ['move' => $close_move, 'content' => $close_content] = $this->promptbuilder->parse_move($close_raw);
        $close_move = $close_move ?? 'session_close';

        $this->sessionmanager->add_turn($session->id, null, 'ai', $close_move, $close_content);

        // Refresh turns for report generation.
        $all_turns_final   = $this->sessionmanager->get_turns($session->id);
        $criterion_results = $this->sessionmanager->get_criterion_results($session->id);

        // Build criterion-scoped turn map for report generation.
        // Assessment calls use per-criterion blocks (not full session history) to
        // prevent position bias and cross-criterion contamination in the AI's scoring
        // (Liu et al., TACL 2023; Zheng et al., NeurIPS 2023).
        $turns_by_criterion = $this->promptbuilder->build_turns_by_criterion($all_turns_final);

        // Student report.
        $student_messages = $this->promptbuilder->build_student_report_messages(
            $config,
            $turns_by_criterion,
            $criterion_results,
            $earlyexit,
        );
        $studentreport = $this->aiclient->chat(
            $config->aiurl,
            $config->aiapikey,
            $config->aimodel,
            $student_messages,
        );

        // Teacher report + grade.
        $teacher_messages = $this->promptbuilder->build_teacher_report_messages(
            $config,
            $turns_by_criterion,
            $criterion_results,
            $earlyexit,
        );
        $teacher_raw = $this->aiclient->chat(
            $config->aiurl,
            $config->aiapikey,
            $config->aimodel,
            $teacher_messages,
        );
        ['grade' => $aigrade, 'report' => $teacherreport] = $this->promptbuilder->parse_grade($teacher_raw);

        // Persist.
        $this->sessionmanager->close_session($session->id, $studentreport, $teacherreport, $aigrade, $earlyexit);

        return [
            'ai_message'  => $close_content,
            'move'        => 'session_close',
            'is_complete' => true,
        ];
    }

    /**
     * Find the current criterion — the first one that is 'pending' or 'in_progress'.
     *
     * Returns null if all criteria are resolved (terminal status).
     *
     * @param activity_config $config    Activity config.
     * @param int             $sessionid Session ID.
     * @return criterion_config|null
     */
    private function find_current_criterion(activity_config $config, int $sessionid): ?criterion_config {
        $results = $this->sessionmanager->get_criterion_results($sessionid);

        foreach ($config->criteria as $criterion) {
            $status = $results[$criterion->id]->status ?? 'pending';
            if (in_array($status, ['pending', 'in_progress'], true)) {
                return $criterion;
            }
        }

        return null;
    }

    /**
     * Find the next criterion after the given one, or null if there are no more.
     *
     * @param activity_config  $config           Activity config.
     * @param int              $sessionid        Session ID.
     * @param criterion_config $current_criterion The criterion just closed.
     * @return criterion_config|null
     */
    private function find_next_criterion(
        activity_config $config,
        int $sessionid,
        criterion_config $current_criterion,
    ): ?criterion_config {
        $found = false;
        $results = $this->sessionmanager->get_criterion_results($sessionid);

        foreach ($config->criteria as $criterion) {
            if ($found) {
                $status = $results[$criterion->id]->status ?? 'pending';
                if (in_array($status, ['pending', 'in_progress'], true)) {
                    return $criterion;
                }
            }
            if ($criterion->id === $current_criterion->id) {
                $found = true;
            }
        }

        return null;
    }

    /**
     * Ask the AI to evaluate the criterion outcome (met / partial / limit).
     *
     * Sends a short structured prompt with just the criterion turns and asks
     * for a single-word verdict.
     *
     * @param activity_config  $config           Activity config.
     * @param \stdClass        $session          Session record.
     * @param criterion_config $criterion        The criterion just closed.
     * @param bool             $force_close      Whether this was a forced close.
     * @return string  'met', 'partial', or 'limit'.
     */
    private function evaluate_criterion_outcome(
        activity_config $config,
        \stdClass $session,
        criterion_config $criterion,
        bool $force_close,
    ): string {
        if ($force_close) {
            return 'limit';
        }

        $criterion_turns = $this->sessionmanager->get_turns_for_criterion($session->id, $criterion->id);
        $level_label     = match ($criterion->bloomslevel) {
            activity_config::BLOOMS_ANALYSE  => 'Analyse',
            activity_config::BLOOMS_EVALUATE => 'Evaluate',
            activity_config::BLOOMS_CREATE   => 'Create',
            default                          => 'Custom',
        };

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an educational assessor. Respond with exactly one word.',
            ],
            ...$this->criterion_turns_to_messages($criterion_turns),
            [
                'role'    => 'user',
                'content' => <<<EOT
Based on the conversation above, did the student meet this criterion?

Criterion [{$level_label}]: {$criterion->description}

Respond with exactly one of these words:
  met     — student provided clear, sufficient evidence
  partial — student showed some understanding but evidence was incomplete
  limit   — max turns reached without sufficient evidence (do not use this here)

One word only. No punctuation.
EOT,
            ],
        ];

        $raw = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
        $verdict = strtolower(trim($raw));

        return in_array($verdict, ['met', 'partial'], true) ? $verdict : 'partial';
    }

    /**
     * Ask the AI to extract a brief evidence quote from the criterion turns.
     *
     * @param activity_config  $config      Activity config.
     * @param int              $sessionid   Session ID.
     * @param criterion_config $criterion   The criterion just closed.
     * @return string  A short quoted excerpt, or empty string on failure.
     */
    private function extract_evidence(
        activity_config $config,
        int $sessionid,
        criterion_config $criterion,
    ): string {
        $criterion_turns = $this->sessionmanager->get_turns_for_criterion($sessionid, $criterion->id);

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an educational assessor. Be concise.',
            ],
            ...$this->criterion_turns_to_messages($criterion_turns),
            [
                'role'    => 'user',
                'content' => 'Quote the single most relevant excerpt from the student\'s responses above '
                    . 'that best demonstrates (or fails to demonstrate) their understanding of the criterion. '
                    . '1–2 sentences maximum. Quote only student text, do not paraphrase.',
            ],
        ];

        try {
            return $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
        } catch (\moodle_exception $e) {
            return '';
        }
    }

    /**
     * Convert criterion-specific turns to OpenAI messages format.
     *
     * @param array $turns Turn records for a single criterion.
     * @return array
     */
    private function criterion_turns_to_messages(array $turns): array {
        $messages = [];
        foreach ($turns as $turn) {
            if ($turn->role === 'student') {
                $messages[] = ['role' => 'user', 'content' => $turn->content];
            } else {
                $content = preg_replace('/\[MOVE:[a-z_]+\]\s*$/i', '', $turn->content);
                $messages[] = ['role' => 'assistant', 'content' => trim($content)];
            }
        }
        return $messages;
    }
}
