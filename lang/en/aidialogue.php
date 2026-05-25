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
 * Strings for component 'mod_aidialogue', language 'en'.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addcriterion'] = 'Add criterion';
$string['aiapikey'] = 'API key';
$string['aiapikey_desc'] = 'Secret key for authenticating with the AI provider. Sent as the Bearer token on every request.';
$string['aidialogue:addinstance'] = 'Add a new AI Dialogue activity';
$string['aidialogue:view'] = 'View AI Dialogue activity';
$string['aiheader'] = 'AI connection';
$string['aiheader_desc'] = 'Configure the OpenAI-compatible API endpoint used by AI Dialogue activities.';
$string['aimodel'] = 'Model';
$string['aimodel_desc'] = 'Model identifier to request from the provider (e.g. <code>gpt-4o</code>, <code>gpt-4.1</code>).';
$string['aiurl'] = 'API base URL';
$string['aiurl_desc'] = 'Base URL of an OpenAI-compatible API endpoint. The chat completions path (<code>/chat/completions</code>) will be appended automatically.';
$string['attemptsettingsheader'] = 'Attempt settings';
$string['bloom_analyse'] = 'Analyse';
$string['bloom_create'] = 'Create';
$string['bloom_custom'] = 'Custom';
$string['bloom_evaluate'] = 'Evaluate';
$string['bloomslevel'] = 'Bloom\'s level';
$string['bloomslevel_help'] = 'The cognitive level this criterion targets, based on Bloom\'s taxonomy.';
$string['completionexhausted'] = 'Mark session as complete when all attempts are exhausted';
$string['completionexhausted_help'] = 'When enabled, a session is marked complete when the student uses up all available attempts without passing. Only applies when both "Mark session as complete when student passes" and a maximum attempt limit are set.';
$string['completionpassed'] = 'Mark session as complete when student passes';
$string['completionpassed_help'] = 'When enabled, a session is considered complete once the student satisfies all criteria in a single attempt.';
$string['criteriondescription'] = 'Description';
$string['criteriondescription_help'] = 'Describe what evidence from the student would satisfy this criterion.';
$string['deletecriterion'] = 'Delete criterion';
$string['err_atleastonecriterion'] = 'You must define at least one criterion.';
$string['err_maxattemptspositive'] = 'Maximum attempts must be 0 or greater.';
$string['err_maxfivecriteria'] = 'You can define a maximum of 5 criteria.';
$string['err_maxturnsgtminturns'] = 'Max turns must be greater than min turns.';
$string['err_maxturnspositive'] = 'Max turns must be at least 1.';
$string['err_minturnspositive'] = 'Min turns must be at least 1.';
$string['knowledgeheader'] = 'Knowledge';
$string['knowledgetext'] = 'Knowledge text';
$string['knowledgetext_help'] = 'The source material the AI will use as its ground truth during the interview. Write in plain text; this content is injected directly into the AI system prompt.';
$string['maxattempts'] = 'Maximum attempts';
$string['maxattempts_help'] = 'The maximum number of times a student can attempt this activity. Set to 0 for unlimited attempts.';
$string['maxturns'] = 'Max turns';
$string['maxturns_help'] = 'The maximum number of exchanges allowed for this criterion. Must be greater than min turns.';
$string['minturns'] = 'Min turns';
$string['minturns_help'] = 'The minimum number of exchanges the AI must complete before closing this criterion.';
$string['modulename'] = 'AI Dialogue';
$string['modulename_help'] = 'An AI-powered dialogue activity where students engage in a conversation with an AI.';
$string['modulename_link'] = 'mod/aidialogue/view';
$string['modulename_summary'] = 'Create an AI conversation activity for students.';
$string['modulename_tip'] = 'Use the AI Dialogue module for interactive AI conversations.';
$string['modulenameplural'] = 'AI Dialogues';
$string['page-mod-aidialogue-x'] = 'Any AI Dialogue module page';
$string['placeholder'] = 'AI Dialogue activity placeholder. Implementation coming soon.';
$string['pluginadministration'] = 'AI Dialogue module administration';
$string['pluginname'] = 'AI Dialogue';
$string['privacy:metadata'] = 'The AI Dialogue activity plugin does not store any personal data.';
$string['rubricheader'] = 'Rubric';
$string['search:activity'] = 'AI Dialogue';
