// ZENOO cookie banner and measurement (see src/Tracking). Nothing optional loads before consent.
// window.znTrack(step) counts a funnel step (POST /t for browser steps) and, with consent, sends it
// to GA4 and the Meta pixel. The choice lives in the zn_consent cookie for 180 days.
(function () {
  const T = window.ZN_TRACKING || {};
  if (!T.text) return;
  const BROWSER_STEPS = ['quiz_start', 'quiz_result', 'plan_cart'];
  const read = () => (document.cookie.match(/(?:^|; )zn_consent=([^;]*)/) || [])[1];
  const allows = (cat) => (read() || '').split(',').includes(cat);
  const queue = [];
  let gaLoaded = false; let pixelLoaded = false;

  function loadGa() {
    if (gaLoaded || !T.ga4 || !allows('analytics')) return;
    gaLoaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted' });
    if (allows('marketing')) window.gtag('consent', 'update', { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
    const s = document.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(T.ga4);
    document.head.appendChild(s);
    window.gtag('js', new Date()); window.gtag('config', T.ga4);
  }
  function loadPixel() {
    if (pixelLoaded || !T.pixel || !allows('marketing')) return;
    pixelLoaded = true;
    /* eslint-disable */
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    /* eslint-enable */
    window.fbq('init', T.pixel); window.fbq('track', 'PageView');
  }
  function send(step, params) {
    if (gaLoaded && window.gtag) window.gtag('event', step, params || {});
    const meta = (T.pixelEvents || {})[step];
    if (pixelLoaded && window.fbq && meta) window.fbq('track', meta, params || {});
  }
  function start() {
    loadGa(); loadPixel();
    queue.splice(0).forEach(([s, p]) => send(s, p));
  }

  window.znTrack = function (step, params) {
    if (BROWSER_STEPS.includes(step)) {
      const body = JSON.stringify({ e: step });
      if (!(navigator.sendBeacon && navigator.sendBeacon('/t', new Blob([body], { type: 'application/json' })))) {
        fetch('/t', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true }).catch(() => {});
      }
    }
    if (gaLoaded || pixelLoaded) send(step, params); else queue.push([step, params]);
  };
  (T.events || []).forEach((s) => window.znTrack(s));

  function save(value) {
    document.cookie = 'zn_consent=' + value + '; Max-Age=' + 180 * 86400 + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    document.getElementById('zn-consent')?.remove();
    start();
  }
  function banner() {
    const t = T.text;
    const el = document.createElement('div');
    el.id = 'zn-consent'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', t.title);
    el.innerHTML = '<p><strong>' + t.title + '</strong> ' + t.body + ' <a href="' + T.privacyUrl + '">' + t.privacy + '</a></p>'
      + '<div class="zn-consent__choose" hidden>'
      + (T.ga4 ? '<label><input type="checkbox" data-cat="analytics"> ' + t.analytics + '</label>' : '')
      + (T.pixel ? '<label><input type="checkbox" data-cat="marketing"> ' + t.marketing + '</label>' : '')
      + '<button type="button" data-act="save">' + t.save + '</button></div>'
      + '<div class="zn-consent__buttons"><button type="button" data-act="all" class="is-main">' + t.all + '</button>'
      + '<button type="button" data-act="necessary">' + t.necessary + '</button>'
      + '<button type="button" data-act="choose">' + t.choose + '</button></div>';
    el.addEventListener('click', (e) => {
      const act = e.target.dataset && e.target.dataset.act;
      if (act === 'all') save('necessary,analytics,marketing');
      else if (act === 'necessary') save('necessary');
      else if (act === 'choose') el.querySelector('.zn-consent__choose').hidden = false;
      else if (act === 'save') save(['necessary'].concat([...el.querySelectorAll('input[data-cat]:checked')].map((i) => i.dataset.cat)).join(','));
    });
    const css = document.createElement('style');
    css.textContent = '#zn-consent{position:fixed;left:16px;right:16px;bottom:16px;max-width:560px;margin:0 auto;z-index:9999;background:#fff;color:#2b2b2b;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.18);padding:16px 18px;font:15px/1.45 Inter,system-ui,sans-serif}'
      + '#zn-consent p{margin:0 0 12px}#zn-consent a{color:inherit}#zn-consent button{font:inherit;font-weight:700;border:2px solid #445942;background:#fff;color:#445942;border-radius:99px;padding:8px 16px;cursor:pointer}'
      + '#zn-consent button.is-main{background:#445942;color:#fff}.zn-consent__buttons{display:flex;gap:8px;flex-wrap:wrap}.zn-consent__choose{display:grid;gap:8px;margin:0 0 12px}.zn-consent__choose label{display:flex;gap:8px;align-items:center}';
    document.head.appendChild(css);
    document.body.appendChild(el);
  }
  // Ask only when there is something optional to ask about.
  if ((T.ga4 || T.pixel) && read() === undefined) {
    if (document.body) banner(); else document.addEventListener('DOMContentLoaded', banner);
  } else {
    start();
  }
  // "Cookie settings" links: data-cookie-settings.
  document.addEventListener('click', (e) => { if (e.target.closest && e.target.closest('[data-cookie-settings]')) { e.preventDefault(); document.cookie = 'zn_consent=; Max-Age=0; Path=/'; location.reload(); } });
})();
