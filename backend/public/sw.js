const OFFLINE_CACHE = 'lions-offline-v1';
const OFFLINE_ASSETS = ['/offline.html', '/images/lions-icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(OFFLINE_CACHE).then((cache) => cache.addAll(OFFLINE_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(Promise.all([
        caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('lions-offline-') && key !== OFFLINE_CACHE).map((key) => caches.delete(key)))),
        self.clients.claim(),
    ]));
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate' || new URL(event.request.url).origin !== self.location.origin) return;
    event.respondWith(fetch(event.request).catch(async () => (await caches.match('/offline.html')) || Response.error()));
});
