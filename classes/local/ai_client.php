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
 * Thin wrapper around an OpenAI-compatible chat completions endpoint.
 *
 * Supports any provider that implements the OpenAI chat/completions API
 * (OpenAI, Azure OpenAI, Ollama, LM Studio, etc.).
 *
 * Deliberately minimal — one public method, synchronous, no streaming.
 * All error conditions throw typed moodle_exceptions so callers can
 * present sensible error messages without catching generic \Exceptions.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Moodle HQ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_client {

    /** @var int Maximum seconds to wait for a response from the AI endpoint. */
    const TIMEOUT_SECONDS = 60;

    /** @var int Maximum tokens to request in the completion response. */
    const MAX_TOKENS = 1024;

    /**
     * Send a messages array to the AI and return the assistant's reply text.
     *
     * The $messages array follows the standard OpenAI format:
     *   [
     *     ['role' => 'system',    'content' => '...'],
     *     ['role' => 'user',      'content' => '...'],
     *     ['role' => 'assistant', 'content' => '...'],
     *     ...
     *   ]
     *
     * @param string $aiurl    Base URL of the endpoint, e.g. https://api.openai.com/v1
     *                         The path /chat/completions is appended automatically.
     * @param string $aiapikey Bearer token / API key.
     * @param string $aimodel  Model name, e.g. 'gpt-4o'.
     * @param array  $messages Array of message objects (role + content).
     * @return string  The assistant's reply text (trimmed).
     * @throws \moodle_exception On HTTP error, timeout, or malformed response.
     */
    public function chat(string $aiurl, string $aiapikey, string $aimodel, array $messages): string {
        $endpoint = rtrim($aiurl, '/') . '/chat/completions';

        $payload = json_encode([
            'model'      => $aimodel,
            'messages'   => $messages,
            'max_tokens' => self::MAX_TOKENS,
        ]);

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'        => self::TIMEOUT_SECONDS,
            'CURLOPT_POST'           => true,
            'CURLOPT_POSTFIELDS'     => $payload,
            'CURLOPT_HTTPHEADER'     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $aiapikey,
            ],
        ]);

        $rawresponse = $curl->post($endpoint, $payload);
        $httpcode    = $curl->get_info()['http_code'] ?? 0;

        if ($curl->get_errno()) {
            throw new \moodle_exception(
                'error:aicurlfailed',
                'mod_aidialogue',
                '',
                $curl->error,
            );
        }

        if ($httpcode === 401) {
            throw new \moodle_exception('error:aiunauthorised', 'mod_aidialogue');
        }

        if ($httpcode === 429) {
            throw new \moodle_exception('error:airatelimited', 'mod_aidialogue');
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \moodle_exception(
                'error:aihttperror',
                'mod_aidialogue',
                '',
                $httpcode,
            );
        }

        $decoded = json_decode($rawresponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('error:aiinvalidjson', 'mod_aidialogue');
        }

        $text = $decoded['choices'][0]['message']['content'] ?? null;

        if ($text === null) {
            throw new \moodle_exception('error:aiemptyresponse', 'mod_aidialogue');
        }

        return trim($text);
    }
}
