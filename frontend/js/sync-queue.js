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

        return new Promise((resolve) => {
            const transaction = this.db.transaction(['queued_visits'], 'readwrite');
            const store = transaction.objectStore('queued_visits');
            const item = {
                data: visitData,
                timestamp: new Date().toISOString(),
                status: 'pending',
                retries: 0,
            };

            const request = store.add(item);

            request.onsuccess = () => {
                console.log('Visit queued:', request.result);
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

        return new Promise((resolve) => {
            const reader = new FileReader();

            reader.onload = () => {
                const transaction = this.db.transaction(['queued_media'], 'readwrite');
                const store = transaction.objectStore('queued_media');
                const item = {
                    visitId,
                    filename: file.name,
                    fileData: reader.result,
                    mimeType: file.type,
                    timestamp: new Date().toISOString(),
                    status: 'pending',
                    retries: 0,
                };

                const request = store.add(item);

                request.onsuccess = () => {
                    console.log('Media queued:', request.result);
                    resolve(true);
                };

                request.onerror = () => {
                    console.error('Media queue failed:', request.error);
                    resolve(false);
                };
            };

            reader.readAsArrayBuffer(file);
        });
    }

    /**
     * Sync pending items when online.
     */
    async syncPending() {
        if (!this.isOnline || !this.db) {
            return;
        }

        console.log('Starting sync...');

        // Sync queued visits
        await this.syncVisits();

        // Sync queued media
        await this.syncMedia();

        this.updateSyncStatus();
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
            const response = await fetch('/api/visits', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Idempotency-Key': visit.data.idempotency_key,
                },
                body: JSON.stringify(visit.data),
            });

            if (response.ok) {
                await this.removeQueuedVisit(visit.id);
                console.log('Visit synced:', visit.id);
            } else {
                console.error('Visit sync failed:', response.status);
            }
        } catch (error) {
            console.error('Visit submit error:', error);
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
            const blob = new Blob([media.fileData], { type: media.mimeType });
            const formData = new FormData();
            formData.append('file', blob, media.filename);
            formData.append('visit_id', media.visitId);

            const response = await fetch('/api/media/upload', {
                method: 'POST',
                body: formData,
            });

            if (response.ok) {
                await this.removeQueuedMedia(media.id);
                console.log('Media synced:', media.id);
            } else {
                console.error('Media sync failed:', response.status);
            }
        } catch (error) {
            console.error('Media upload error:', error);
        }
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
                resolve();
            };
        });
    }

    /**
     * Update UI sync status indicator.
     */
    updateSyncStatus() {
        const statusEl = document.getElementById('sync-status');
        if (!statusEl) return;

        if (this.isOnline) {
            statusEl.textContent = 'Online - syncing...';
            statusEl.classList.remove('offline');
            this.syncPending();
        } else {
            statusEl.textContent = 'Offline - changes queued';
            statusEl.classList.add('offline');
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
