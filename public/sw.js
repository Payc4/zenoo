// ZENOO service worker: makes the site installable and shows a friendly page when offline.
// Pages are never cached (they hold personal data); only static files and the offline page are.
const CACHE = 'zenoo-static-v2';
const OFFLINE = '/offline.html';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE, '/icons/icon-192.png', '/images/logo-white.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET' || new URL(req.url).origin !== location.origin) return;
  if (req.mode === 'navigate') {
    // One quiet retry before the offline page: a redeploy swaps containers with a blip of a second or two.
    e.respondWith(fetch(req)
      .catch(() => new Promise((r) => setTimeout(r, 2000)).then(() => fetch(req)))
      .catch(() => caches.match(OFFLINE)));
    return;
  }
  // Fingerprinted assets and icons: cache first.
  if (/^\/(assets|icons|quiz\/assets)\//.test(new URL(req.url).pathname)) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
      return res;
    })));
  }
});
