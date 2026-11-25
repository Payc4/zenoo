/*
 * Small progressive enhancements. Every page also works without JavaScript.
 */
import './styles/app.css';

document.addEventListener('DOMContentLoaded', () => {
  // Mobile menu
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
  }

  // Language menu: close it on a click elsewhere or Escape.
  const langMenu = document.querySelector('.lang-switch');
  if (langMenu) {
    document.addEventListener('click', (e) => { if (langMenu.open && !langMenu.contains(e.target)) langMenu.open = false; });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && langMenu.open) { langMenu.open = false; langMenu.querySelector('summary').focus(); }
    });
  }

  // Show / hide password
  document.querySelectorAll('input[type=password]').forEach((input) => {
    const wrap = document.createElement('div');
    wrap.className = 'password-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    // Translated labels when the page gives them (data-show / data-hide on the input).
    const label = (shown) => (shown ? input.dataset.hide || 'Hide' : input.dataset.show || 'Show');
    btn.textContent = label(false);
    btn.setAttribute('aria-pressed', 'false');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = label(show);
      btn.setAttribute('aria-pressed', String(show));
    });
    wrap.appendChild(btn);
  });

  // Login tabs: password / email link
  const tabs = document.querySelectorAll('[role="tab"][aria-controls]');
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((t) => {
        const selected = t === tab;
        t.setAttribute('aria-selected', String(selected));
        document.getElementById(t.getAttribute('aria-controls')).hidden = !selected;
      });
      const first = document.getElementById(tab.getAttribute('aria-controls')).querySelector('input:not([type=hidden])');
      if (first) first.focus();
    });
  });

  // Ask before destructive actions
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  // Picking a file sends its form (the account photo)
  document.querySelectorAll('input[type=file][data-autosubmit]').forEach((input) => {
    input.addEventListener('change', () => { if (input.files.length) input.form.requestSubmit(); });
  });

  // Show the chosen photo's file name and enable its upload button
  document.querySelectorAll('input[type=file][data-enable]').forEach((input) => {
    input.addEventListener('change', () => {
      const btn = document.getElementById(input.dataset.enable);
      if (btn) btn.disabled = !input.files.length;
    });
  });
});

