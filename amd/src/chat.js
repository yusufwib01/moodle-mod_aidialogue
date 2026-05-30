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
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';

const SELECTORS = {
    transcript: '#aidialogue-transcript',
    input: '#aidialogue-input',
    sendBtn: '#aidialogue-send',
    endBtn: '#aidialogue-end',
    status: '#aidialogue-status',
};

/**
 * Append a chat turn to the transcript and scroll it into view.
 *
 * @param {HTMLElement} transcript   The transcript container.
 * @param {string}      role         'student' or 'ai'.
 * @param {string}      content      The message text.
 * @param {string}      labeltext    Localised label for screen readers ('You' or 'AI').
 * @param {number|null} timecreated  Unix timestamp (seconds). Null omits the timestamp.
 */
const appendMessage = (transcript, role, content, labeltext, timecreated) => {
    const isStudent = role === 'student';

    // Screen-reader label (visually hidden).
    const srLabel = document.createElement('span');
    srLabel.className = 'sr-only visually-hidden';
    srLabel.textContent = labeltext;

    // Avatar circle — matches Full transcript styling from review.php.
    const avatar = document.createElement('span');
    avatar.className = 'badge rounded-circle d-inline-flex align-items-center justify-content-center '
        + 'flex-shrink-0 aidialogue-avatar '
        + (isStudent
            ? 'bg-warning bg-opacity-25 text-dark ms-2'
            : 'bg-primary bg-opacity-25 text-primary me-2');
    avatar.setAttribute('aria-hidden', 'true');
    if (isStudent) {
        const avatarInner = document.createElement('span');
        avatarInner.className = 'opacity-50';
        avatarInner.setAttribute('aria-hidden', 'true');
        avatarInner.textContent = 'S';
        avatar.appendChild(avatarInner);
    } else {
        const avatarIcon = document.createElement('i');
        avatarIcon.className = 'fa-solid fa-wand-magic-sparkles';
        avatarIcon.setAttribute('aria-hidden', 'true');
        avatar.appendChild(avatarIcon);
    }

    // Bubble.
    const bubble = document.createElement('div');
    bubble.className = isStudent ? 'aidialogue-bubble aidialogue-bubble-student' : 'aidialogue-bubble aidialogue-bubble-ai';
    bubble.textContent = content;

    // Timestamp.
    const timestamp = document.createElement('div');
    timestamp.className = 'aidialogue-timestamp' + (isStudent ? ' text-end' : '');
    if (timecreated) {
        const date = new Date(timecreated * 1000);
        timestamp.textContent = date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    }

    // Inner wrapper (bubble + timestamp).
    const inner = document.createElement('div');
    inner.className = isStudent
        ? 'flex-grow-1 d-flex flex-column align-items-end'
        : 'flex-grow-1';
    inner.appendChild(bubble);
    inner.appendChild(timestamp);

    // Outer turn row.
    const wrap = document.createElement('div');
    wrap.className = 'aidialogue-turn d-flex align-items-start mb-3'
        + (isStudent ? ' aidialogue-turn-student' : ' aidialogue-turn-ai');
    wrap.appendChild(srLabel);

    if (isStudent) {
        wrap.appendChild(inner);
        wrap.appendChild(avatar);
    } else {
        wrap.appendChild(avatar);
        wrap.appendChild(inner);
    }

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
        strEndSession,
        strAjaxError,
        strYou,
        strAi,
    ] = await getStrings([
        {key: 'thinking', component: 'mod_aidialogue'},
        {key: 'sessioncomplete', component: 'mod_aidialogue'},
        {key: 'viewresults', component: 'mod_aidialogue'},
        {key: 'endsession_confirm', component: 'mod_aidialogue'},
        {key: 'endsession', component: 'mod_aidialogue'},
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
        appendMessage(transcript, 'student', content, strYou, Math.floor(Date.now() / 1000));
        input.value = '';

        try {
            const result = await Ajax.call([{
                methodname: 'mod_aidialogue_submit_chat_message',
                args: {sessionid, cmid, message: content},
            }])[0];

            status.textContent = '';
            appendMessage(transcript, 'ai', result.aimessage, strAi, result.timecreated);

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
        const modal = await ModalSaveCancel.create({
            title: strEndSession,
            body: strEndConfirm,
            removeOnClose: true,
        });
        await modal.setSaveButtonText(strEndSession);
        modal.getRoot().on(ModalEvents.save, async() => {
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
        modal.show();
    });

    // Ctrl+Enter or Cmd+Enter sends the message.
    input.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            sendBtn.click();
        }
    });
};
