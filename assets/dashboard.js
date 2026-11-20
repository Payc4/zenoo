/*
 * "My dog" dashboard (templates/dashboard/index.html.twig).
 */
import './styles/dashboard.css';

// Dog photo: the form goes off as soon as a photo is picked.
document.querySelectorAll('[data-photo-form] input[type=file]').forEach((input) => {
  input.addEventListener('change', () => {
    if (!input.files.length) return;
    input.closest('form').classList.add('is-sending');
    input.closest('form').submit();
  });
});

// Share the score: the phone's share sheet where there is one, else copy the link.
document.querySelectorAll('[data-share]').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const url = btn.dataset.share;
    try {
      if (navigator.share) {
        await navigator.share({ title: btn.dataset.shareTitle, url });
      } else {
        await navigator.clipboard.writeText(url);
        btn.textContent = '✓';
      }
    } catch (e) { /* closed the share sheet */ }
  });
});

// The log sheet (Today): one sheet for all four logs, opened on the right tab; nothing pre-selected.
const sheet = document.getElementById('log-sheet');
if (sheet) {
  const show = (type) => {
    sheet.querySelectorAll('[data-log-tab]').forEach((t) => t.setAttribute('aria-selected', String(t.dataset.logTab === type)));
    sheet.querySelectorAll('[data-log-panel]').forEach((p) => { p.hidden = p.dataset.logPanel !== type; });
    const first = sheet.querySelector(`[data-log-panel="${type}"] input:not([type=hidden])`);
    if (first && first.type !== 'radio') first.focus();
  };
  document.querySelectorAll('[data-log-open]').forEach((b) => b.addEventListener('click', () => {
    show(b.dataset.logOpen);
    sheet.showModal ? sheet.showModal() : sheet.setAttribute('open', '');
  }));
  sheet.querySelectorAll('[data-log-tab]').forEach((t) => t.addEventListener('click', () => show(t.dataset.logTab)));
  sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.close(); }); // tap outside closes
}

// Removing a dog: a confirmation that names what's lost, with "Keep" as the main button.
const removeSheet = document.getElementById('remove-sheet');
document.querySelector('[data-remove-open]')?.addEventListener('click', () => removeSheet?.showModal());

// Old links to the plan, notes or trackers on the dashboard now live on the dog's tab.
if (location.pathname === '/dashboard' && /^#(zenoo-h|plan-h|knows-h|trackers-h|share-h)$/.test(location.hash)) {
  location.replace('/dashboard/dog' + location.hash);
}
