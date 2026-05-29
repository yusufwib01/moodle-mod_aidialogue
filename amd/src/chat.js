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
 * Chat interface for the AI Dialogue activity.
 *
 * Wires the transcript, message input, and end-session controls to the
 * mod_aidialogue external functions via core/ajax.
 *
 * @module     mod_aidialogue/chat
 * @copyright  2026 Muhammad Arnaldo <muhammad.arnaldo@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_strings as getStrings} from 'core/str';

const SELECTORS = {
    transcript: '#aidialogue-transcript',
    input: '#aidialogue-input',
    sendBtn: '#aidialogue-send',
    endBtn: '#aidialogue-end',
    status: '#aidialogue-status',
};

/**
 * Append a chat bubble to the transcript and scroll it into view.
 *
 * @param {HTMLElement} transcript The transcript container.
 * @param {string} role 'student' or 'ai'.
 * @param {string} content The message text.
 * @param {string} labeltext Localised label ('You' or 'AI').
 */
const appendMessage = (transcript, role, content, labeltext) => {
    const isStudent = role === 'student';

    const wrap = document.createElement('div');
    wrap.className = isStudent ? 'mb-2 text-end' : 'mb-2';

    const label = document.createElement('small');
    label.textContent = labeltext;

    const bubble = document.createElement('span');
    bubble.className = (isStudent ? 'badge bg-primary' : 'badge bg-secondary') + ' text-wrap aidialogue-bubble';
    bubble.textContent = content;

    const bubbleWrap = document.createElement('div');
    bubbleWrap.appendChild(bubble);

    wrap.appendChild(label);
    wrap.appendChild(bubbleWrap);
    transcript.appendChild(wrap);
    transcript.scrollTop = transcript.scrollHeight;
};

/**
 * Initialise the chat interface.
 *
 * Called from view.php via $PAGE->requires->js_call_amd().
 *
 * @param {Number} sessionid Active aidialogue_session.id.
 * @param {Number} cmid Course module ID.
 */
export const init = async(sessionid, cmid) => {
    const transcript = document.querySelector(SELECTORS.transcript);
    const input = document.querySelector(SELECTORS.input);
    const sendBtn = document.querySelector(SELECTORS.sendBtn);
    const endBtn = document.querySelector(SELECTORS.endBtn);
    const status = document.querySelector(SELECTORS.status);

    if (!transcript || !input || !sendBtn || !endBtn || !status) {
        return;
    }

    const [
        strThinking,
        strSessionComplete,
        strViewResults,
        strEndConfirm,
        strAjaxError,
        strYou,
        strAi,
    ] = await getStrings([
        {key: 'thinking', component: 'mod_aidialogue'},
        {key: 'sessioncomplete', component: 'mod_aidialogue'},
        {key: 'viewresults', component: 'mod_aidialogue'},
        {key: 'endsession_confirm', component: 'mod_aidialogue'},
        {key: 'error:ajax', component: 'mod_aidialogue'},
        {key: 'you', component: 'mod_aidialogue'},
        {key: 'ai', component: 'mod_aidialogue'},
    ]);

    const lockUI = () => {
        sendBtn.disabled = true;
        endBtn.disabled = true;
        input.disabled = true;
    };

    const unlockUI = () => {
        sendBtn.disabled = false;
        endBtn.disabled = false;
        input.disabled = false;
    };

    /**
     * Render the "session complete" status with a link back to the results view.
     */
    const showComplete = () => {
        lockUI();
        status.textContent = strSessionComplete + ' ';
        const link = document.createElement('a');
        link.href = `?id=${cmid}`;
        link.textContent = strViewResults;
        status.appendChild(link);
    };

    // Scroll to the latest message on load.
    transcript.scrollTop = transcript.scrollHeight;

    sendBtn.addEventListener('click', async() => {
        const content = input.value.trim();
        if (!content) {
            return;
        }

        lockUI();
        status.textContent = strThinking;
        appendMessage(transcript, 'student', content, strYou);
        input.value = '';

        try {
            const result = await Ajax.call([{
                methodname: 'mod_aidialogue_submit_chat_message',
                args: {sessionid, cmid, message: content},
            }])[0];

            status.textContent = '';
            appendMessage(transcript, 'ai', result.aimessage, strAi);

            if (result.iscomplete) {
                showComplete();
            } else {
                unlockUI();
                input.focus();
            }
        } catch (e) {
            status.textContent = e.message ? e.message : strAjaxError;
            unlockUI();
        }
    });

    endBtn.addEventListener('click', async() => {
        if (!window.confirm(strEndConfirm)) {
            return;
        }

        lockUI();
        status.textContent = strThinking;

        try {
            await Ajax.call([{
                methodname: 'mod_aidialogue_end_session',
                args: {sessionid, cmid},
            }])[0];

            status.textContent = '';
            showComplete();
        } catch (e) {
            status.textContent = e.message ? e.message : strAjaxError;
            unlockUI();
        }
    });

    // Ctrl+Enter or Cmd+Enter sends the message.
    input.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            sendBtn.click();
        }
    });
};
