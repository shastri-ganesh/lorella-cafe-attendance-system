/**
 * Lorella Speciality Cafe - Progressive Web App Service Worker
 *
 * CRITICAL ARCHITECTURAL POLICY:
 * Attendance verification, check-in, check-out, and all /api/* requests
 * MUST NEVER be cached or processed offline. Server-authoritative validation
 * of GPS, geofencing, server timestamps, and biometric photos requires live
 * network connectivity.
 */

const CACHE_NAME = 'lorella-static-v1.0.0';

const STATIC_ASSETS = [
    '/',
    '/manifest.json',
    '/assets/css/lorella-theme.css',
    '/assets/js/api-client.js',
    '/assets/js/app.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // 1. All API routes, mutation methods, and authentication must ALWAYS bypass cache
    if (url.pathname.startsWith('/api/') || event.request.method !== 'GET') {
        event.respondWith(fetch(event.request));
        return;
    }

    // 2. Static UI shell assets can use Network-First strategy with cache fallback
    event.respondWith(
        fetch(event.request)
            .then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return networkResponse;
            })
            .catch(() => {
                return caches.match(event.request);
            })
    );
});
