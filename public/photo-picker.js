/*
 * ZenooPhoto.pick(options) → Promise<File[]> (empty when the person closes it).
 * Used by the chat, the dog's photo and the quiz's label scan (templates/_photo_picker.html.twig).
 *
 *  - Phones: "Take a photo" opens the camera straight away (capture="environment"), or "Choose
 *    from your photos". With { direct: true } (e.g. "Snap the food label") the camera opens at once.
 *  - Computers: choose a file, use the webcam (getUserMedia), or "Send from your phone": a QR code
 *    whose page takes the photo on the phone; it lands here (App\Controller\PhotoHandoffController).
 *
 * options: { max = 1, pdf = false, direct = false, title, hint }
 */
(() => {
  const cfgEl = document.getElementById('zn-photo-config');
  let cfg = {};
  try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : '{}'); } catch (e) { /* defaults */ }
  const T = (k, f) => (cfg.i18n && cfg.i18n[k]) || f;
  const isPhone = () => matchMedia('(hover: none) and (pointer: coarse)').matches;
  const canWebcam = () => !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) && window.isSecureContext;
  const el = (tag, attrs = {}, ...kids) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v); else if (v !== false && v != null) n.setAttribute(k, v === true ? '' : v);
    }
    kids.flat().forEach((c) => c && n.append(c));
    return n;
  };
  const put = (node, ...kids) => node.replaceChildren(...kids.flat().filter(Boolean)); // skips left-out parts
  const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'ZenooPhoto' };

  // The phone's own picker: a file input, with the camera straight away when asked.
  const nativePick = ({ camera = false, pdf = false, multiple = false } = {}) => new Promise((resolve) => {
    const input = el('input', { type: 'file', accept: pdf ? 'image/*,application/pdf' : 'image/*', hidden: true });
    if (camera) input.setAttribute('capture', 'environment');
    if (multiple) input.multiple = true;
    let settled = false;
    const finish = (files) => { if (!settled) { settled = true; input.remove(); resolve(files); } };
    input.addEventListener('change', () => finish([...input.files]));
    input.addEventListener('cancel', () => finish([]));
    document.body.append(input);
    input.click();
  });

  let qrLoaded = null;
  const loadQr = () => qrLoaded || (qrLoaded = new Promise((ok, fail) => {
    if (window.qrcode) return ok(window.qrcode);
    const s = el('script', { src: cfg.qrScript || '/js/qrcode.js' });
    s.onload = () => ok(window.qrcode);
    s.onerror = fail;
    document.head.append(s);
  }));

  function pick(options = {}) {
    const opts = { max: 1, pdf: false, direct: false, ...options };
    opts.max = Math.max(1, Math.min(3, opts.max | 0));
    if (isPhone() && opts.direct) return nativePick({ camera: true });
    return sheet(opts);
  }

  function sheet(opts) {
    return new Promise((resolve) => {
      let cleanup = () => {};
      let result = [];
      const body = el('div', { class: 'znp__body' });
      const titleEl = el('h2', { class: 'znp__title', id: 'znp-title', text: opts.title || T('title', 'Add a photo') });
      const backBtn = el('button', { type: 'button', class: 'znp__back', 'aria-label': T('back', 'Back'), text: '‹', hidden: true, onclick: () => menu() });
      const dialog = el('dialog', { class: 'znp', 'aria-labelledby': 'znp-title' },
        el('div', { class: 'znp__head' }, backBtn, titleEl,
          el('button', { type: 'button', class: 'znp__close', 'aria-label': T('close', 'Close'), text: '×', onclick: () => finish([]) })),
        body);
      const finish = (files) => { result = files; dialog.close(); };
      dialog.addEventListener('close', () => { cleanup(); dialog.remove(); resolve(result); });
      dialog.addEventListener('click', (e) => { if (e.target === dialog) finish([]); }); // click on the backdrop
      document.body.append(dialog);

      const view = (...kids) => { cleanup(); cleanup = () => {}; put(body, ...kids); };
      const option = (icon, label, onclick) => el('button', { type: 'button', class: 'znp__opt', onclick },
        el('span', { class: 'znp__icon', 'aria-hidden': 'true', text: icon }), el('span', { text: label }));

      function menu() {
        backBtn.hidden = true;
        const hint = opts.hint ? el('p', { class: 'znp__hint', text: opts.hint }) : null;
        if (isPhone()) {
          view(hint,
            option('📷', T('take', 'Take a photo'), async () => { const f = await nativePick({ camera: true }); if (f.length) finish(f); }),
            option('🖼️', opts.pdf ? T('choosePhonePdf', 'Choose a photo or PDF') : T('choosePhone', 'Choose from your photos'),
              async () => { const f = await nativePick({ pdf: opts.pdf, multiple: opts.max > 1 }); if (f.length) finish(f.slice(0, opts.max)); }));
        } else {
          view(hint,
            option('📁', T('choose', 'Choose a file on this computer'), async () => { const f = await nativePick({ pdf: opts.pdf, multiple: opts.max > 1 }); if (f.length) finish(f.slice(0, opts.max)); }),
            canWebcam() ? option('🎥', T('webcam', 'Use this computer’s camera'), webcam) : null,
            cfg.createUrl ? option('📱', T('phone', 'Send from your phone'), phone) : null);
        }
        body.querySelector('button')?.focus();
      }

      // ── The computer's camera ──
      async function webcam() {
        backBtn.hidden = false;
        const status = el('p', { class: 'znp__status', role: 'status', text: T('starting', 'Starting the camera…') });
        const video = el('video', { class: 'znp__video', autoplay: true, playsinline: true, muted: true });
        const still = el('img', { class: 'znp__video', alt: '', hidden: true });
        const actions = el('div', { class: 'znp__actions' });
        view(el('div', { class: 'znp__frame' }, video, still), el('p', { class: 'znp__hint', text: opts.hint || T('webcamHint', 'Hold the label flat and close, in good light.') }), status, actions);
        let stream = null; let devices = []; let current = 0; let shot = null;
        const stop = () => { if (stream) stream.getTracks().forEach((t) => t.stop()); stream = null; };
        cleanup = () => { stop(); if (still.src) URL.revokeObjectURL(still.src); };
        const start = async () => {
          stop();
          try {
            const video_ = devices[current] ? { deviceId: { exact: devices[current].deviceId } } : { facingMode: 'environment' };
            stream = await navigator.mediaDevices.getUserMedia({ video: { ...video_, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
            video.srcObject = stream;
            await video.play().catch(() => {});
            status.textContent = '';
            if (!devices.length) devices = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
            live();
          } catch (e) {
            status.classList.add('is-error');
            status.textContent = e && e.name === 'NotAllowedError' ? T('camBlocked', 'Camera access is blocked.')
              : e && (e.name === 'NotFoundError' || e.name === 'OverconstrainedError') ? T('noCam', 'No camera was found.') : T('error', 'Something went wrong.');
            put(actions, el('button', { type: 'button', class: 'znp__btn znp__btn--quiet', text: T('choose', 'Choose a file'), onclick: async () => { const f = await nativePick({ pdf: opts.pdf }); if (f.length) finish(f); } }));
          }
        };
        const live = () => {
          video.hidden = false; still.hidden = true;
          put(actions, 
            devices.length > 1 ? el('button', { type: 'button', class: 'znp__btn znp__btn--quiet', text: T('switchCam', 'Switch camera'), onclick: () => { current = (current + 1) % devices.length; start(); } }) : null,
            el('button', { type: 'button', class: 'znp__btn', text: T('snap', 'Take the photo'), onclick: snap }));
          actions.lastChild.focus();
        };
        const snap = () => {
          const w = video.videoWidth; const h = video.videoHeight;
          if (!w) return;
          const canvas = el('canvas'); canvas.width = w; canvas.height = h;
          canvas.getContext('2d').drawImage(video, 0, 0, w, h);
          canvas.toBlob((blob) => {
            if (!blob) return;
            shot = new File([blob], 'camera.jpg', { type: 'image/jpeg' });
            if (still.src) URL.revokeObjectURL(still.src);
            still.src = URL.createObjectURL(blob);
            video.hidden = true; still.hidden = false;
            put(actions, 
              el('button', { type: 'button', class: 'znp__btn znp__btn--quiet', text: T('retake', 'Retake'), onclick: live }),
              el('button', { type: 'button', class: 'znp__btn', text: T('usePhoto', 'Use this photo'), onclick: () => finish([shot]) }));
            actions.lastChild.focus();
          }, 'image/jpeg', 0.9);
        };
        start();
      }

      // ── Send from the phone (QR code) ──
      async function phone() {
        backBtn.hidden = false;
        const code = el('div', { class: 'znp__qr', 'aria-hidden': 'true' });
        const status = el('p', { class: 'znp__status', role: 'status', 'aria-live': 'polite', text: T('waiting', 'Waiting for your phone…') });
        const thumbs = el('div', { class: 'znp__thumbs' });
        const actions = el('div', { class: 'znp__actions' });
        view(el('p', { class: 'znp__hint', text: T('phoneHint', 'Scan the code with your phone’s camera.') }), code, status, thumbs, actions);
        let stopped = false; let timer = null; let handoff = null; const files = [];
        cleanup = () => {
          stopped = true; clearTimeout(timer);
          if (handoff) fetch(handoff.close, { method: 'POST', headers, body: '{}', keepalive: true }).catch(() => {});
          thumbs.querySelectorAll('img').forEach((i) => URL.revokeObjectURL(i.src));
        };
        try {
          const [res, qr] = await Promise.all([
            fetch(cfg.createUrl, { method: 'POST', headers, body: JSON.stringify({ max: opts.max }) }),
            loadQr(),
          ]);
          if (!res.ok) throw new Error();
          handoff = await res.json();
          if (stopped) { cleanup(); return; }
          const q = qr(0, 'M'); q.addData(handoff.url); q.make();
          code.innerHTML = q.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
        } catch (e) {
          status.classList.add('is-error'); status.textContent = T('error', 'Something went wrong. Please try again.');
          return;
        }
        const useBtn = el('button', { type: 'button', class: 'znp__btn', text: T('use', 'Use these photos'), hidden: true, onclick: () => finish(files.slice()) });
        actions.append(useBtn);
        const poll = async () => {
          if (stopped) return;
          try {
            const res = await fetch(handoff.poll, { headers: { 'X-Requested-With': 'ZenooPhoto' }, cache: 'no-store' });
            const data = await res.json();
            for (const url of data.files.slice(files.length)) {
              const blob = await (await fetch(url, { cache: 'no-store' })).blob();
              const file = new File([blob], 'phone-photo.jpg', { type: 'image/jpeg' });
              files.push(file);
              thumbs.append(el('img', { src: URL.createObjectURL(blob), alt: '' }));
            }
            if (files.length) {
              status.textContent = T('got', 'Got it!');
              code.hidden = true;
              if (files.length >= opts.max || data.full) { finish(files.slice()); return; }
              useBtn.hidden = false;
            }
            if (data.expired) {
              status.classList.add('is-error'); status.textContent = T('expired', 'This code has ended.');
              code.hidden = true;
              put(actions, files.length ? useBtn : el('button', { type: 'button', class: 'znp__btn', text: T('newCode', 'Make a new code'), onclick: phone }));
              return;
            }
          } catch (e) { /* try again on the next round */ }
          timer = setTimeout(poll, 2000);
        };
        timer = setTimeout(poll, 2000);
      }

      dialog.showModal();
      menu();
    });
  }

  window.ZenooPhoto = { pick, isPhone };
})();
