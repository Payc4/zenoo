/*
 * The chat page (templates/chat/index.html.twig, styles in assets/styles/chat.css).
 * Sends a message to /chat/send and renders the streamed reply
 * (server-sent events: meta, delta, questions, done, error).
 *
 * Question cards: the starting questions (empty chat) and the ones Claude asks with its
 * ask_questions tool are shown one at a time above the message box ("2 of 4", options,
 * "Type your answer…", Skip / Next). All answers go back as one chat message.
 *
 * Check-ins (#chat-checkin, every few days): the same cards, but the answers are saved to the
 * dog's profile (POST /chat/checkin) instead of being sent as a message, so they cost no AI call.
 */
import './styles/chat.css';

const app = document.getElementById('chat');
if (app) {
  // Words shown by this script, translated by the page (data-i18n on #chat).
  let T = {};
  try { T = JSON.parse(app.dataset.i18n || '{}'); } catch (e) { /* keep English below */ }
  const t = (key, fallback, vars = {}) => (T[key] || fallback).replace(/%(\w+)%/g, (m, k) => (k in vars ? vars[k] : m));
  const $ = (id) => document.getElementById(id);
  const thread = $('chat-thread');
  const scroller = $('chat-scroll');
  const form = $('chat-form');
  const input = $('chat-input');
  const sendBtn = $('chat-send');
  const counter = $('chat-counter');
  const limitNote = $('chat-limit-note');
  const remainingEl = $('chat-remaining');
  const maxChars = Number(app.dataset.maxChars) || 2000;
  const configured = app.dataset.configured === 'true';
  let busy = false;

  const scrollToBottom = (force = false) => {
    const nearBottom = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 160;
    if (force || nearBottom) scroller.scrollTo({ top: scroller.scrollHeight, behavior: force ? 'instant' : 'smooth' });
  };

  const resize = () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 200) + 'px';
    const n = input.value.length;
    counter.textContent = n > maxChars * 0.8 ? `${n} / ${maxChars}` : '';
  };

  const setRemaining = (n) => {
    if (n === null || n === undefined || !remainingEl) return;
    remainingEl.textContent = n;
    if (n <= 0) {
      input.disabled = true;
      sendBtn.disabled = true;
      limitNote.textContent = t('limitAll', `You’ve used all ${app.dataset.limit} messages for today. You can chat again after ${app.dataset.resetLabel}.`);
    }
  };

  const addUserMessage = (text) => {
    const wrap = document.createElement('div');
    wrap.className = 'chat-msg chat-msg--user';
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble';
    bubble.textContent = text;
    wrap.appendChild(bubble);
    thread.appendChild(wrap);
  };

  const addBotMessage = () => {
    const el = document.createElement('div');
    el.className = 'chat-msg chat-msg--bot is-pending';
    el.innerHTML = '<img class="chat-avatar" src="/favicon.png" alt="" width="32" height="32">'
      + '<div class="chat-answer" aria-live="polite"><span class="chat-dots" aria-label="Writing"><i></i><i></i><i></i></span></div>';
    thread.appendChild(el);
    return { el, answer: el.querySelector('.chat-answer') };
  };

  let frame = null;
  const renderInto = (el, raw) => {
    if (frame) cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
      el.innerHTML = render(raw);
      scrollToBottom();
    });
  };

  const onMeta = (d) => setRemaining(d.remaining);

  const readStream = async (res, handlers) => {
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let idx;
      while ((idx = buffer.indexOf('\n\n')) !== -1) {
        const chunk = buffer.slice(0, idx);
        buffer = buffer.slice(idx + 2);
        let event = 'message';
        let data = '';
        for (const line of chunk.split('\n')) {
          if (line.startsWith('event:')) event = line.slice(6).trim();
          else if (line.startsWith('data:')) data += line.slice(5).trim();
        }
        if (data && handlers[event]) handlers[event](JSON.parse(data));
      }
    }
  };

  const send = async (text) => {
    text = (text ?? input.value).trim();
    if (!text || busy || !configured || input.disabled) return;

    busy = true;
    sendBtn.disabled = true;
    input.value = '';
    resize();
    $('chat-empty')?.remove();
    addUserMessage(text);
    const reply = addBotMessage();
    scrollToBottom(true);

    let raw = '';
    let cards = null;
    try {
      const res = await fetch(app.dataset.sendUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'X-CSRF-Token': app.dataset.csrf },
        body: JSON.stringify({ message: text }),
      });
      const type = res.headers.get('content-type') || '';
      if (!res.ok || !type.includes('text/event-stream')) {
        let message = t('error', 'Something went wrong. Please try again.');
        let remaining;
        try { const j = await res.json(); message = j.error || message; remaining = j.remaining; } catch (e) { /* not JSON */ }
        if (remaining === 0) {
          setRemaining(0);
          limitNote.textContent = message;
        }
        throw new Error(message);
      }

      await readStream(res, {
        meta: onMeta,
        delta: (d) => { raw += d.text; renderInto(reply.answer, raw); },
        questions: (d) => { cards = d.questions; },
        done: () => {},
        error: (d) => {
          setRemaining(d.remaining);
          throw new Error(d.message);
        },
      });
      if (!raw.trim() && !cards) throw new Error(t('noReply', 'No reply came back. Please try again.'));
      if (cards) {
        if (frame) cancelAnimationFrame(frame);
        reply.answer.innerHTML = (raw.trim() ? render(raw) : '<p>I have a few quick questions for you.</p>') + askedList(cards);
        const again = document.createElement('button');
        again.type = 'button';
        again.className = 'btn btn-small chat-reopen';
        again.textContent = t('answerQuestions', 'Answer the questions');
        again.hidden = true;
        again.dataset.reopen = '';
        reply.answer.appendChild(again);
        again.addEventListener('click', () => openCards(cards, false));
        openCards(cards, false);
      }
    } catch (e) {
      if (frame) cancelAnimationFrame(frame);
      reply.el.classList.add('is-error');
      reply.answer.textContent = e.message || t('error', 'Something went wrong. Please try again.');
      // Give the text back so it can be sent again.
      if (!input.value && !input.disabled) { input.value = text; resize(); }
    } finally {
      reply.el.classList.remove('is-pending');
      busy = false;
      sendBtn.disabled = input.disabled;
      scrollToBottom();
      if (!input.disabled) input.focus();
    }
  };

  form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
  input.addEventListener('input', resize);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
  });
  document.querySelectorAll('[data-suggest]').forEach((b) => b.addEventListener('click', () => send(b.dataset.suggest)));

  // ───────── Question cards ─────────
  const card = $('chat-qcard');
  let q = null; // { questions, intro, index, answers: [{ choice, text, skipped }] }

  function askedList(questions) {
    return '<ol class="chat-asked">' + questions.map((x) => `<li>${escapeHtml(x.question)}</li>`).join('') + '</ol>';
  }

  function openCards(questions, intro, checkin = false) {
    if (!questions || !questions.length || (input.disabled && !checkin)) return;
    q = { questions, intro, checkin, index: 0, answers: questions.map(() => ({ choice: null, text: '', skipped: false })) };
    document.querySelectorAll('[data-reopen]').forEach((b) => { b.hidden = true; });
    form.hidden = true;
    card.hidden = false;
    renderCard();
    scrollToBottom(true);
  }

  function closeCards(sent = false) {
    if (!q) return;
    const wasIntro = q.intro;
    q = null;
    card.hidden = true;
    card.innerHTML = '';
    form.hidden = false;
    resize();
    if (sent) return;
    if (wasIntro) {
      $('chat-suggest')?.removeAttribute('hidden');
      const note = $('chat-intro-note');
      if (note) note.textContent = t('askAnything', 'Ask anything about feeding your dog or using ZENOO.');
    } else {
      // Claude's questions stay answerable from its last message.
      const last = [...thread.querySelectorAll('[data-reopen]')].pop();
      if (last) last.hidden = false;
    }
    input.focus();
  }

  const answered = (a) => a.skipped || a.choice !== null || a.text.trim() !== '';
  const answerText = (x, a) => (a.text.trim() !== '' ? a.text.trim() : (a.choice !== null ? x.options[a.choice].label : null));

  function renderCard() {
    const { questions, index, answers } = q;
    const x = questions[index];
    const a = answers[index];
    const last = index === questions.length - 1;
    const canNext = a.choice !== null || a.text.trim() !== '';

    card.innerHTML = `
      <div class="qcard__head">
        <button type="button" class="qcard__nav" data-q="prev" aria-label="${t('prevQ', 'Previous question')}" ${index === 0 ? 'disabled' : ''}>‹</button>
        <span class="qcard__count">${q.checkin ? `<span class="qcard__kind">${t('checkin', 'Quick check-in')}</span> · ` : ''}${t('count', '%n% of %total%', { n: index + 1, total: questions.length })}</span>
        <button type="button" class="qcard__nav" data-q="next-nav" aria-label="${t('nextQ', 'Next question')}" ${last || !answered(a) ? 'disabled' : ''}>›</button>
        <button type="button" class="qcard__close" data-q="close" aria-label="${t('closeQ', 'Close the questions')}">×</button>
      </div>
      <h2 class="qcard__question" id="qcard-question">${escapeHtml(x.question)}</h2>
      ${x.options.length ? `<div class="qcard__options" role="radiogroup" aria-labelledby="qcard-question">${x.options.map((o, i) => `
        <button type="button" class="qcard__option${a.choice === i ? ' is-selected' : ''}" role="radio" aria-checked="${a.choice === i}" data-option="${i}">
          <span class="qcard__label">${escapeHtml(o.label)}</span>
          ${o.description ? `<span class="qcard__desc">${escapeHtml(o.description)}</span>` : ''}
        </button>`).join('')}</div>` : ''}
      <label class="visually-hidden" for="qcard-text">${t('ownAnswer', 'Your own answer')}</label>
      <input type="text" id="qcard-text" class="qcard__text" maxlength="300" autocomplete="off"
             placeholder="${t('typeAnswer', 'Type your answer…')}" value="${escapeHtml(a.text)}">
      <div class="qcard__actions">
        <button type="button" class="btn btn-outline btn-small" data-q="skip">${t('skip', 'Skip')}</button>
        <button type="button" class="btn btn-small" data-q="next" ${canNext ? '' : 'disabled'}>${last ? (q.checkin ? t('saveAnswers', 'Save') : t('sendAnswers', 'Send answers')) : t('next', 'Next →')}</button>
      </div>`;

    const text = card.querySelector('#qcard-text');
    text.addEventListener('input', () => {
      a.text = text.value;
      a.skipped = false;
      if (a.text.trim() !== '' && a.choice !== null) {
        a.choice = null;
        card.querySelectorAll('.qcard__option').forEach((b) => { b.classList.remove('is-selected'); b.setAttribute('aria-checked', 'false'); });
      }
      card.querySelector('[data-q="next"]').disabled = !(a.choice !== null || a.text.trim() !== '');
    });
    text.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); if (a.text.trim() !== '') next(); }
    });
    (x.options.length ? card.querySelector('.qcard__option.is-selected, .qcard__option') : text)?.focus({ preventScroll: true });
  }

  function next() {
    if (q.index < q.questions.length - 1) {
      q.index += 1;
      renderCard();
    } else {
      submitCards();
    }
  }

  function submitCards() {
    const { questions, answers, intro, checkin } = q;
    if (answers.every((a) => a.choice === null && a.text.trim() === '')) {
      closeCards(checkin); // everything skipped: nothing to send
      return;
    }
    if (checkin) {
      saveCheckin(questions.map((x, i) => ({
        id: x.id,
        value: answers[i].choice !== null ? x.options[answers[i].choice].value : '',
        text: answers[i].text.trim(),
      })));
      closeCards(true);
      return;
    }
    const lines = questions.map((x, i) => `${x.question} — ${answerText(x, answers[i]) ?? t('skipped', 'skipped')}`);
    const message = (intro ? t('aboutDog', 'A bit about my dog:') + '\n' : '') + lines.join('\n');
    closeCards(true);
    send(message);
  }

  // Check-in answers go to the dog's profile, not to the chat (no AI call).
  async function saveCheckin(answers) {
    const note = document.createElement('p');
    note.className = 'chat-note';
    thread.appendChild(note);
    try {
      const res = await fetch(app.dataset.checkinUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf },
        body: JSON.stringify({ answers }),
      });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error || '');
      if (j.message) note.textContent = '✓ ' + j.message; else note.remove();
    } catch (e) {
      note.textContent = e.message || t('error', 'Something went wrong. Please try again.');
      note.classList.add('is-error');
    }
    scrollToBottom(true);
  }

  card.addEventListener('click', (e) => {
    if (!q) return;
    const opt = e.target.closest('[data-option]');
    if (opt) {
      const a = q.answers[q.index];
      a.choice = Number(opt.dataset.option);
      a.text = '';
      a.skipped = false;
      renderCard();
      // Picking an option moves on by itself, except on the last question.
      if (q.index < q.questions.length - 1) setTimeout(() => { if (q) next(); }, 220);
      return;
    }
    const action = e.target.closest('[data-q]')?.dataset.q;
    if (action === 'prev' && q.index > 0) { q.index -= 1; renderCard(); }
    if (action === 'next-nav' && q.index < q.questions.length - 1) { q.index += 1; renderCard(); }
    if (action === 'next') next();
    if (action === 'skip') { const a = q.answers[q.index]; a.choice = null; a.text = ''; a.skipped = true; next(); }
    if (action === 'close') closeCards(false);
  });
  card.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeCards(false); });

  // Reopen buttons on Claude's last message (rendered by the server).
  const pending = $('chat-cards') ? JSON.parse($('chat-cards').textContent) : null;
  document.querySelectorAll('[data-reopen]').forEach((b) => b.addEventListener('click', () => pending && openCards(pending.questions, false)));
  if (pending) openCards(pending.questions, pending.intro);
  const checkinCards = $('chat-checkin') ? JSON.parse($('chat-checkin').textContent) : null;
  if (!pending && checkinCards) openCards(checkinCards, false, true);

  scrollToBottom(true);
  window.addEventListener('load', () => scrollToBottom(true), { once: true });
  resize();
  if (configured && !input.disabled && !q && window.matchMedia('(min-width: 900px)').matches) input.focus();
}

// Minimal, safe Markdown → HTML (mirrors App\Twig\ChatMarkdownExtension).
function escapeHtml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function inline(s) {
  return escapeHtml(s.trim())
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/g, '$1<em>$2</em>');
}

export function render(md) {
  const text = md.replace(/\r\n?/g, '\n')
    .replace(/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/gm, '**$1**')
    .replace(/^(\s*)\*\s+/gm, '$1- ')
    .trim();
  if (!text) return '';

  const html = [];
  for (const block of text.split(/\n\s*\n/)) {
    let buffer = [];
    let listType = null;
    let items = [];
    const flushList = () => {
      if (items.length) html.push(`<${listType}>${items.map((i) => `<li>${i}</li>`).join('')}</${listType}>`);
      items = [];
      listType = null;
    };
    const flushPara = () => {
      if (buffer.length) html.push(`<p>${buffer.join('<br>')}</p>`);
      buffer = [];
    };
    for (const line of block.split('\n')) {
      let m;
      if ((m = line.match(/^\s*[-•]\s+(.*)$/))) {
        flushPara();
        if (listType !== 'ul') { flushList(); listType = 'ul'; }
        items.push(inline(m[1]));
      } else if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) {
        flushPara();
        if (listType !== 'ol') { flushList(); listType = 'ol'; }
        items.push(inline(m[1]));
      } else {
        flushList();
        buffer.push(inline(line));
      }
    }
    flushList();
    flushPara();
  }
  return html.join('\n');
}
