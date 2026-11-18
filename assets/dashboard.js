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
