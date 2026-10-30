const CACHE = 'lchat-static-20261029-weba2';
const STATIC = [
  '/assets/style.css?v=20261026-login2',
  '/assets/theme.css?v=20261013-glass1',
  '/assets/details.css?v=20261014-path1',
  '/assets/settings.css?v=20261026-settings1',
  '/assets/bots.css?v=20261026-bots1',
  '/assets/weba.css?v=20261029-weba2',
  '/assets/weba-pattern.svg',
  '/assets/aurora.css?v=20261026-replykb1',
  '/assets/message-extra.css?v=20261026-reactions1',
  '/assets/app.js?v=20261029-weba2',
  '/assets/settings.js?v=20261026-modules1',
  '/assets/bots.js?v=20261026-botcreator1',
  '/assets/weba-ui.js?v=20261029-weba2',
  '/assets/weba-icons.woff2',
  '/assets/favicon.svg',
  '/assets/icon-192.png',
  '/assets/icon-512.png'
];
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(STATIC)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('lchat-static-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith('/assets/')) return;
  event.respondWith(caches.match(request).then(cached => cached || fetch(request).then(response => {
    if (response.ok && response.type === 'basic') {
      const copy = response.clone();
      caches.open(CACHE).then(cache => cache.put(request, copy)).catch(() => {});
    }
    return response;
  })));
});
