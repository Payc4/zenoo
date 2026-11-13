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
