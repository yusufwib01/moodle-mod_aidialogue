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
 * AMD module for the AI Dialogue chat interface.
 *
 * Handles send message and end session interactions via core/ajax
 * external function calls.
 *
 * @module     mod_aidialogue/chat
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_strings as getStrings} from 'core/str';

const Selectors = {
    transcript: '#aidialogue-transcript',
    input:      '#aidialogue-input',
    sendBtn:    '#aidialogue-send',
    endBtn:     '#aidialogue-end',
    status:     '#aidialogue-status',
};

/**
 * Append a chat bubble to the transcript.
 *
 * @param {HTMLElement} transcript
 * @param {string} role       'student' or 'ai'
 * @param {string} content
 * @param {string} labeltext  Localised label ('You' or 'AI').
 */
const appendMessage = (transcript, role, content, labeltext) => {
    const isstudent = role === 'student';

    const wrap = document.createElement('div');
    wrap.className = isstudent ? 'mb-2 text-end' : 'mb-2';

    const label = document.createElement('small');
    label.textContent = labeltext;

    const bubble = document.createElement('span');
    bubble.className = (isstudent ? 'badge bg-primary' : 'badge bg-secondary') + ' text-wrap aidialogue-bubble';
    bubble.textContent = content;

    const bdiv = document.createElement('div');
    bdiv.appendChild(bubble);

    wrap.appendChild(label);
    wrap.appendChild(bdiv);
    transcript.appendChild(wrap);
    transcript.scrollTop = transcript.scrollHeight;
};

/**
 * Lock all interactive elements during an in-flight request.
 *
 * @param {HTMLElement} sendbtn
 * @param {HTMLElement} endbtn
 * @param {HTMLElement} inputel
 */
const lockui = (sendbtn, endbtn, inputel) => {
    sendbtn.disabled = true;
    endbtn.disabled = true;
    inputel.disabled = true;
};

/**
 * Unlock all interactive elements after a request completes.
 *
 * @param {HTMLElement} sendbtn
 * @param {HTMLElement} endbtn
 * @param {HTMLElement} inputel
 */
const unlockui = (sendbtn, endbtn, inputel) => {
    sendbtn.disabled = false;
    endbtn.disabled = false;
    inputel.disabled = false;
};

/**
 * Initialise the chat interface.
 *
 * Called by view.php via $PAGE->requires->js_call_amd().
 *
 * @param {number} sessionid  Active aidialogue_session.id.
 * @param {number} cmid       Course module ID.
 */
export const init = async(sessionid, cmid) => {
    const transcript = document.querySelector(Selectors.transcript);
    const inputel = document.querySelector(Selectors.input);
    const sendbtn = document.querySelector(Selectors.sendBtn);
    const endbtn = document.querySelector(Selectors.endBtn);
    const statusel = document.querySelector(Selectors.status);

    if (!transcript || !inputel || !sendbtn || !endbtn || !statusel) {
        return;
    }

    // Pre-fetch strings in one call.
    const [strthinking, strsessioncomplete, strviewresults, strendsessionconfirm, strajaxerror, stryou, strai] = await getStrings([
        {key: 'thinking',          component: 'mod_aidialogue'},
        {key: 'sessioncomplete',   component: 'mod_aidialogue'},
        {key: 'viewresults',       component: 'mod_aidialogue'},
        {key: 'endsession_confirm', component: 'mod_aidialogue'},
        {key: 'error:ajax',        component: 'mod_aidialogue'},
        {key: 'you',               component: 'mod_aidialogue'},
        {key: 'ai',                component: 'mod_aidialogue'},
    ]);

    // Scroll to latest message on load.
    transcript.scrollTop = transcript.scrollHeight;

    // Send message.
    sendbtn.addEventListener('click', async() => {
        const content = inputel.value.trim();
        if (!content) {
            return;
        }

        lockui(sendbtn, endbtn, inputel);
        statusel.textContent = strthinking;
        appendMessage(transcript, 'student', content, stryou);
        inputel.value = '';

        try {
            const result = await Ajax.call([{
                methodname: 'mod_aidialogue_submit_chat_message',
                args: {sessionid, cmid, message: content},
            }])[0];

            statusel.textContent = '';
            appendMessage(transcript, 'ai', result.aimessage, strai);

            if (result.iscomplete) {
                // Session closed — lock UI permanently and show view results link.
                lockui(sendbtn, endbtn, inputel);
                const link = document.createElement('a');
                link.href = `?id=${cmid}`;
                link.textContent = strviewresults;
                statusel.textContent = strsessioncomplete + ' ';
                statusel.appendChild(link);
            } else {
                unlockui(sendbtn, endbtn, inputel);
                inputel.focus();
            }
        } catch (e) {
            statusel.textContent = e.message ?? strajaxerror;
            unlockui(sendbtn, endbtn, inputel);
        }
    });

    // End session early.
    endbtn.addEventListener('click', async() => {
        if (!window.confirm(strendsessionconfirm)) {
            return;
        }

        lockui(sendbtn, endbtn, inputel);
        statusel.textContent = strthinking;

        try {
            await Ajax.call([{
                methodname: 'mod_aidialogue_end_session',
                args: {sessionid, cmid},
            }])[0];

            statusel.textContent = '';
            const link = document.createElement('a');
            link.href = `?id=${cmid}`;
            link.textContent = strviewresults;
            statusel.textContent = strsessioncomplete + ' ';
            statusel.appendChild(link);
        } catch (e) {
            statusel.textContent = e.message ?? strajaxerror;
            unlockui(sendbtn, endbtn, inputel);
        }
    });

    // Ctrl+Enter / Cmd+Enter to send.
    inputel.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            sendbtn.click();
        }
    });
};
