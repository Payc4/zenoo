/*
 * The phone's side of "Send a photo from your phone" (templates/handoff/phone.html.twig).
 * Each photo is turned upright and shrunk to 1568px as a JPEG (quicker on a phone connection),
 * then sent to POST /p/{token}. The computer picks it up from there.
 */
(() => {
  const box = document.getElementById('handoff');
  if (!box || !box.dataset.uploadUrl) return;
  let T = {};
  try { T = JSON.parse(box.dataset.i18n || '{}'); } catch (e) { /* English */ }
  const status = document.getElementById('handoff-status');
  const thumbs = document.getElementById('handoff-thumbs');
  const take = document.getElementById('handoff-take');
  const max = +box.dataset.max || 1;
  let sent = +box.dataset.sent || 0;

  const shrink = async (file) => {
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, 1568 / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      return (await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.85))) || file;
    } catch (e) {
      return file;
    }
  };

  const done = () => {
    box.querySelectorAll('input[type=file]').forEach((i) => { i.disabled = true; i.closest('label').hidden = true; });
    status.textContent = T.sentAll || 'All done.';
  };
  if (sent >= max) done();

  const send = async (file) => {
    status.classList.remove('is-error');
    status.textContent = T.sending || 'Sending…';
    box.classList.add('is-busy');
    try {
      const blob = await shrink(file);
      const form = new FormData();
      form.append('photo', blob, 'photo.jpg');
      const res = await fetch(box.dataset.uploadUrl, { method: 'POST', body: form });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || T.error);
      sent = data.sent;
      const img = document.createElement('img');
      img.src = URL.createObjectURL(blob);
      img.alt = '';
      thumbs.appendChild(img);
      if (data.full) done();
      else {
        status.textContent = T.sent || 'Sent!';
        take.querySelector('[data-take-text]').textContent = T.another || 'Take another photo';
      }
    } catch (e) {
      status.classList.add('is-error');
      status.textContent = e.message || T.error || 'Something went wrong.';
    } finally {
      box.classList.remove('is-busy');
    }
  };

  box.querySelectorAll('input[type=file]').forEach((input) => input.addEventListener('change', () => {
    if (input.files[0]) send(input.files[0]);
    input.value = '';
  }));
})();
