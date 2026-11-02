/*
 * The chat page (templates/chat/index.html.twig, styles in assets/styles/chat.css).
 * Sends a message to /chat/send and renders the streamed reply
 * (server-sent events: meta, delta, done, error).
 */
import './styles/chat.css';

const app = document.getElementById('chat');
if (app) {
  const $ = (id) => document.getElementById(id);
  const thread = $('chat-thread');
  const scroller = $('chat-scroll');
  const form = $('chat-form');
  const input = $('chat-input');
  const sendBtn = $('chat-send');
  const counter = $('chat-counter');
  const limitNote = $('chat-limit-note');
  const remainingEl = $('chat-remaining');
  const side = $('chat-side');
  const scrim = $('chat-scrim');
  const toggle = $('chat-toggle');
  const convs = $('chat-convs');
  const maxChars = Number(app.dataset.maxChars) || 2000;
  const configured = app.dataset.configured === 'true';
  let conversationId = app.dataset.conversationId || '';
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
      limitNote.textContent = `You’ve used all ${app.dataset.limit} messages for today. You can chat again after ${app.dataset.resetLabel}.`;
    }
  };

  const toggleSide = (open = !side.classList.contains('is-open')) => {
    side.classList.toggle('is-open', open);
    scrim.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
  };
  toggle.addEventListener('click', () => toggleSide());
  scrim.addEventListener('click', () => toggleSide(false));

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

  const onMeta = (d) => {
    setRemaining(d.remaining);
    const wasNew = !conversationId;
    conversationId = String(d.conversationId);
    if (wasNew && d.url) {
      history.replaceState({}, '', d.url);
      document.title = `${d.title} · Chat · ZENOO`;
      $('chat-title').textContent = d.title;
      $('chat-convs-empty')?.remove();
      convs.querySelectorAll('.chat-conv.is-active').forEach((a) => a.classList.remove('is-active'));
      const a = document.createElement('a');
      a.className = 'chat-conv is-active';
      a.href = d.url;
      const t = document.createElement('span');
      t.className = 'chat-conv__title';
      t.textContent = d.title;
      const date = document.createElement('span');
      date.className = 'chat-conv__date';
      date.textContent = 'Now';
      a.append(t, date);
      convs.prepend(a);
    }
  };

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
    try {
      const res = await fetch(app.dataset.sendUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'X-CSRF-Token': app.dataset.csrf },
        body: JSON.stringify({ conversationId: conversationId || null, message: text }),
      });
      const type = res.headers.get('content-type') || '';
      if (!res.ok || !type.includes('text/event-stream')) {
        let message = 'Something went wrong. Please try again.';
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
        done: () => {},
        error: (d) => {
          if (d.removedConversation) conversationId = '';
          setRemaining(d.remaining);
          throw new Error(d.message);
        },
      });
      if (!raw.trim()) throw new Error('No reply came back. Please try again.');
    } catch (e) {
      if (frame) cancelAnimationFrame(frame);
      reply.el.classList.add('is-error');
      reply.answer.textContent = e.message || 'Something went wrong. Please try again.';
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

  scrollToBottom(true);
  window.addEventListener('load', () => scrollToBottom(true), { once: true });
  resize();
  if (configured && !input.disabled && window.matchMedia('(min-width: 900px)').matches) input.focus();
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
