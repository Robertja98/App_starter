/**
 * Service Worker
 * 
 * Enables offline functionality, asset caching, and installability.
 */

const CACHE_NAME = 'service-app-v1';
const OFFLINE_URL = '/';

const ASSETS_TO_CACHE = [
    '/',
    '/app.html',
    '/css/tablet-ui.css',
    '/js/sync-queue.js',
    '/js/visit-form.js',
    '/manifest.json',
];

// Install event - cache essential assets
self.addEventListener('install', (event) => {
    console.log('Service Worker installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(ASSETS_TO_CACHE))
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('Service Worker activating...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter(name => name !== CACHE_NAME)
                    .map(name => caches.delete(name))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests and external URLs
    if (request.method !== 'GET' || url.origin !== location.origin) {
        return;
    }

    // API requests: network first, fallback to offline queue
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    // Cache successful API responses
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // Offline: attempt to serve from cache
                    return caches.match(request)
                        .then((response) => response || new Response('Offline', { status: 503 }));
                })
        );
        return;
    }

    // Static assets: cache first, fallback to network
    event.respondWith(
        caches.match(request)
            .then((response) => {
                if (response) {
                    return response;
                }

                return fetch(request)
                    .then((response) => {
                        // Only cache successful responses
                        if (!response || response.status !== 200 || response.type === 'error') {
                            return response;
                        }

                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(request, responseClone);
                        });

                        return response;
                    })
                    .catch(() => {
                        // Offline: try cache again or return offline page
                        return caches.match(OFFLINE_URL);
                    });
            })
    );
});

// Message from client: handle sync requests
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
