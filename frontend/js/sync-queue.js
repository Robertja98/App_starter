/**
 * Sync Queue Manager
 * 
 * Handles offline queueing of form submissions and media uploads.
 * Syncs with server when connection is restored.
 */

class SyncQueue {
    constructor() {
        this.db = null;
        this.isOnline = navigator.onLine;
        this.isSyncing = false;
        this.csrfToken = null;
        this.telemetry = {
            queuedVisits: 0,
            queuedMedia: 0,
            lastError: '',
            lastSyncAt: '',
        };
        this.initIndexedDB();
        this.setupListeners();
        this.updateSyncStatus();
    }

    /**
     * Initialize IndexedDB for local queuing.
     */
    initIndexedDB() {
        const request = indexedDB.open('ServiceAppDB', 1);

        request.onerror = () => {
            console.error('IndexedDB init failed');
        };

        request.onsuccess = (event) => {
            this.db = event.target.result;
            console.log('IndexedDB initialized');
            this.updateSyncStatus();
        };

        request.onupgradeneeded = (event) => {
            const db = event.target.result;

            // Store for queued visit submissions
            if (!db.objectStoreNames.contains('queued_visits')) {
                db.createObjectStore('queued_visits', { keyPath: 'id', autoIncrement: true });
            }

            // Store for queued media uploads
            if (!db.objectStoreNames.contains('queued_media')) {
                db.createObjectStore('queued_media', { keyPath: 'id', autoIncrement: true });
            }

            // Store for change metadata
            if (!db.objectStoreNames.contains('change_metadata')) {
                db.createObjectStore('change_metadata', { keyPath: 'id' });
            }
        };
    }

    /**
     * Queue a form submission for later sync.
     */
    async queueSubmission(visitData) {
        if (!this.db) {
            console.error('IndexedDB not ready');
            return false;
        }

        const normalizedData = {
            ...visitData,
            idempotency_key: visitData.idempotency_key || this.generateIdempotencyKey(),
        };

        return new Promise((resolve) => {
            const transaction = this.db.transaction(['queued_visits'], 'readwrite');
            const store = transaction.objectStore('queued_visits');
            const item = {
                data: normalizedData,
                timestamp: new Date().toISOString(),
                status: 'pending',
                retries: 0,
            };

            const request = store.add(item);

            request.onsuccess = () => {
                console.log('Visit queued:', request.result);
                this.updateSyncStatus();
                resolve(true);
            };

            request.onerror = () => {
                console.error('Queue failed:', request.error);
                resolve(false);
            };
        });
    }

    /**
     * Queue a media upload for later sync.
     */
    async queueMedia(file, visitId) {
        if (!this.db) {
            console.error('IndexedDB not ready');
            return false;
        }

        const queueItem = {
            visitId,
            filename: file.name,
            fileSize: file.size,
            mimeType: file.type,
            timestamp: new Date().toISOString(),
            status: 'pending',
            retries: 0,
            idempotencyKey: this.generateIdempotencyKey(),
        };

        return new Promise((resolve) => {
            const transaction = this.db.transaction(['queued_media'], 'readwrite');
            const store = transaction.objectStore('queued_media');
            const request = store.add(queueItem);

            request.onsuccess = () => {
                console.log('Media metadata queued:', request.result);
                this.updateSyncStatus();
                resolve(true);
            };

            request.onerror = () => {
                console.error('Media queue failed:', request.error);
                resolve(false);
            };
        });
    }

    /**
     * Sync pending items when online.
     */
    async syncPending() {
        if (!this.isOnline || !this.db || this.isSyncing) {
            return;
        }

        const pendingCounts = await this.getPendingCounts();
        if ((pendingCounts.queuedVisits + pendingCounts.queuedMedia) === 0) {
            this.telemetry.lastError = '';
            this.telemetry.lastSyncAt = new Date().toISOString();
            this.renderTelemetry();
            return;
        }

        this.isSyncing = true;
        console.log('Starting sync...');
        this.renderTelemetry();

        try {
            // Sync queued visits
            await this.syncVisits();

            // Sync queued media
            await this.syncMedia();
            this.telemetry.lastError = '';
            this.telemetry.lastSyncAt = new Date().toISOString();
        } finally {
            this.isSyncing = false;
            this.updateSyncStatus();
        }
    }

    /**
     * Sync queued visit submissions.
     */
    async syncVisits() {
        const transaction = this.db.transaction(['queued_visits'], 'readonly');
        const store = transaction.objectStore('queued_visits');
        const getAllRequest = store.getAll();

        return new Promise((resolve) => {
            getAllRequest.onsuccess = async () => {
                const visits = getAllRequest.result;

                for (const visit of visits) {
                    await this.submitVisit(visit);
                }

                resolve();
            };
        });
    }

    /**
     * Submit a single visit to the server.
     */
    async submitVisit(visit) {
        try {
            const idempotencyKey = visit.data.idempotency_key || this.generateIdempotencyKey();
            const visitDate = visit.data.visit_date || new Date().toISOString().slice(0, 10);
            const queueItem = {
                type: 'visit',
                action: 'create',
                idempotency_key: idempotencyKey,
                timestamp: visit.timestamp || new Date().toISOString(),
                data: {
                    site_id: visit.data.site_id,
                    equipment_id: visit.data.equipment_id,
                    technician_id: visit.data.technician_id,
                    visit_status: visit.data.visit_status || visit.data.status || 'scheduled',
                    visit_date: visitDate,
                    visit_notes: visit.data.visit_notes || visit.data.notes || visit.data.narrative || '',
                },
            };

            const syncResult = await this.submitSyncItem(queueItem);

            if (syncResult && (syncResult.status === 'success' || syncResult.status === 'duplicate')) {
                await this.removeQueuedVisit(visit.id);
                console.log('Visit synced:', visit.id);
            } else {
                console.error('Visit sync failed:', syncResult);
                this.setLastError(`Visit sync failed (item ${visit.id || 'unknown'})`);
            }
        } catch (error) {
            console.error('Visit submit error:', error);
            this.setLastError('Visit sync failed due to network or server error');
        }
    }

    /**
     * Sync queued media uploads.
     */
    async syncMedia() {
        const transaction = this.db.transaction(['queued_media'], 'readonly');
        const store = transaction.objectStore('queued_media');
        const getAllRequest = store.getAll();

        return new Promise((resolve) => {
            getAllRequest.onsuccess = async () => {
                const mediaItems = getAllRequest.result;

                for (const media of mediaItems) {
                    await this.uploadMedia(media);
                }

                resolve();
            };
        });
    }

    /**
     * Upload a single media item to the server.
     */
    async uploadMedia(media) {
        try {
            const queueItem = {
                type: 'media',
                action: 'create',
                idempotency_key: media.idempotencyKey || this.generateIdempotencyKey(),
                timestamp: media.timestamp || new Date().toISOString(),
                data: {
                    visit_id: media.visitId,
                    media_type: this.getMediaType(media.mimeType),
                    original_filename: media.filename,
                    stored_filename: media.filename,
                    file_size: media.fileSize,
                    mime_type: media.mimeType,
                    is_uploaded: 0,
                },
            };

            const syncResult = await this.submitSyncItem(queueItem);

            if (syncResult && (syncResult.status === 'success' || syncResult.status === 'duplicate')) {
                await this.removeQueuedMedia(media.id);
                console.log('Media synced:', media.id);
            } else {
                console.error('Media sync failed:', syncResult);
                this.setLastError(`Media sync failed (item ${media.id || 'unknown'})`);
            }
        } catch (error) {
            console.error('Media upload error:', error);
            this.setLastError('Media sync failed due to network or server error');
        }
    }

    async submitSyncItem(queueItem) {
        if (!queueItem || !queueItem.type || !queueItem.action || !queueItem.data) {
            return null;
        }

        const token = await this.getCsrfToken();
        if (!token) {
            console.error('Cannot sync without CSRF token');
            this.setLastError('Cannot sync: missing CSRF token');
            return null;
        }

        const body = {
            csrf_token: token,
            queue: [queueItem],
        };

        let response = await fetch('/api/sync', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(body),
        });

        // Retry once with a refreshed token if the session token has rotated.
        if (response.status === 403) {
            this.csrfToken = null;
            body.csrf_token = await this.getCsrfToken(true);
            response = await fetch('/api/sync', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(body),
            });
        }

        if (!response.ok) {
            this.setLastError(`Sync endpoint returned ${response.status}`);
            return { status: 'error', httpStatus: response.status };
        }

        const payload = await response.json();
        const results = payload && payload.data && Array.isArray(payload.data.results)
            ? payload.data.results
            : [];

        return results[0] || null;
    }

    async getCsrfToken(forceRefresh = false) {
        if (!forceRefresh && this.csrfToken) {
            return this.csrfToken;
        }

        try {
            const response = await fetch('/api/auth/csrf', {
                method: 'GET',
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return null;
            }

            const payload = await response.json();
            this.csrfToken = payload && payload.data ? payload.data.csrf_token : null;
            return this.csrfToken;
        } catch (error) {
            console.error('CSRF token fetch failed:', error);
            this.setLastError('Unable to fetch CSRF token');
            return null;
        }
    }

    generateIdempotencyKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return `id-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    getMediaType(mimeType) {
        if (typeof mimeType !== 'string' || mimeType === '') {
            return 'document';
        }

        if (mimeType.indexOf('image/') === 0) {
            return 'photo';
        }

        if (mimeType.indexOf('video/') === 0) {
            return 'video';
        }

        return 'document';
    }

    /**
     * Remove synced visit from queue.
     */
    removeQueuedVisit(id) {
        return new Promise((resolve) => {
            const transaction = this.db.transaction(['queued_visits'], 'readwrite');
            const store = transaction.objectStore('queued_visits');
            const request = store.delete(id);

            request.onsuccess = () => {
                this.updateSyncStatus();
                resolve();
            };
        });
    }

    /**
     * Remove synced media from queue.
     */
    removeQueuedMedia(id) {
        return new Promise((resolve) => {
            const transaction = this.db.transaction(['queued_media'], 'readwrite');
            const store = transaction.objectStore('queued_media');
            const request = store.delete(id);

            request.onsuccess = () => {
                this.updateSyncStatus();
                resolve();
            };
        });
    }

    /**
     * Update UI sync status indicator.
     */
    async updateSyncStatus() {
        const statusEl = document.getElementById('sync-status');
        if (!statusEl) return;

        const counts = await this.getPendingCounts();
        this.telemetry.queuedVisits = counts.queuedVisits;
        this.telemetry.queuedMedia = counts.queuedMedia;
        this.renderTelemetry();

        if (this.isOnline) {
            if (this.isSyncing) {
                statusEl.textContent = 'Online - syncing...';
            } else if ((counts.queuedVisits + counts.queuedMedia) > 0) {
                statusEl.textContent = 'Online - pending sync';
                this.syncPending();
            } else {
                statusEl.textContent = 'Online - all changes synced';
            }
            statusEl.classList.remove('offline');
        } else {
            statusEl.textContent = 'Offline - changes queued';
            statusEl.classList.add('offline');
        }
    }

    async getPendingCounts() {
        if (!this.db) {
            return { queuedVisits: 0, queuedMedia: 0 };
        }

        const queuedVisits = await this.countStoreRecords('queued_visits');
        const queuedMedia = await this.countStoreRecords('queued_media');
        return { queuedVisits, queuedMedia };
    }

    countStoreRecords(storeName) {
        return new Promise((resolve) => {
            if (!this.db || !this.db.objectStoreNames.contains(storeName)) {
                resolve(0);
                return;
            }

            const transaction = this.db.transaction([storeName], 'readonly');
            const store = transaction.objectStore(storeName);
            const request = store.count();

            request.onsuccess = () => resolve(request.result || 0);
            request.onerror = () => resolve(0);
        });
    }

    setLastError(message) {
        this.telemetry.lastError = message;
        this.renderTelemetry();
    }

    renderTelemetry() {
        const queueEl = document.getElementById('sync-queue-count');
        const lastSyncEl = document.getElementById('sync-last-success');
        const errorEl = document.getElementById('sync-last-error');

        if (queueEl) {
            queueEl.textContent = `Queue: ${this.telemetry.queuedVisits} visits, ${this.telemetry.queuedMedia} media`;
        }

        if (lastSyncEl) {
            if (this.telemetry.lastSyncAt) {
                const date = new Date(this.telemetry.lastSyncAt);
                lastSyncEl.textContent = `Last sync: ${date.toLocaleString()}`;
            } else {
                lastSyncEl.textContent = 'Last sync: not yet';
            }
        }

        if (errorEl) {
            if (this.telemetry.lastError) {
                errorEl.textContent = `Last error: ${this.telemetry.lastError}`;
                errorEl.classList.remove('hidden');
            } else {
                errorEl.textContent = '';
                errorEl.classList.add('hidden');
            }
        }
    }

    /**
     * Setup online/offline listeners.
     */
    setupListeners() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            console.log('App is online');
            this.updateSyncStatus();
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            console.log('App is offline');
            this.updateSyncStatus();
        });
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    window.syncQueue = new SyncQueue();
});
