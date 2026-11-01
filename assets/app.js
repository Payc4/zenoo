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

  // Show / hide password
  document.querySelectorAll('input[type=password]').forEach((input) => {
    const wrap = document.createElement('div');
    wrap.className = 'password-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    btn.textContent = 'Show';
    btn.setAttribute('aria-label', 'Show password');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
    wrap.appendChild(btn);
  });

  // Login tabs: password / email link
  const tabs = document.querySelectorAll('[role="tab"]');
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

  // Show the chosen photo's file name and enable its upload button
  document.querySelectorAll('input[type=file][data-enable]').forEach((input) => {
    input.addEventListener('change', () => {
      const btn = document.getElementById(input.dataset.enable);
      if (btn) btn.disabled = !input.files.length;
    });
  });
});
