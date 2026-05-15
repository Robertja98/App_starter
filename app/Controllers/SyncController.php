<?php
/**
 * Sync Endpoint (Offline Queue)
 * 
 * - POST /api/sync – Process offline queue from tablet IndexedDB
 * 
 * This endpoint handles the offline-first workflow:
 * 1. Tablet collects data offline (visits, measurements, consumables, etc.)
 * 2. When online, tablet POSTs all queued items to /api/sync
 * 3. Each item has an idempotency_key to prevent duplicates on retry
 * 4. Server processes queue, returns sync results
 */

require_once __DIR__ . '/../Models/ServiceVisit.php';
require_once __DIR__ . '/../Models/Measurement.php';
require_once __DIR__ . '/../Models/Consumable.php';
require_once __DIR__ . '/../Models/RepairRecommendation.php';
require_once __DIR__ . '/../Models/MediaItem.php';

class SyncController extends Controller {

    /**
     * POST /api/sync
     * 
     * Receive and process offline queue from tablet
     * 
     * Request Body:
     *   {
     *     "queue": [
     *       {
     *         "type": "visit",
     *         "action": "create",
     *         "data": {"site_id": X, "equipment_id": Y, ...},
     *         "idempotency_key": "uuid-v4",
     *         "timestamp": 1234567890
     *       },
     *       {
     *         "type": "measurement",
     *         "action": "create",
     *         "data": {"service_visit_id": X, "value": 7.2, ...},
     *         "idempotency_key": "uuid-v4",
     *         "timestamp": 1234567890
     *       },
     *       ...
     *     ]
     *   }
     * 
     * Response:
     *   {
     *     "status": "success",
     *     "data": {
     *       "processed": N,
     *       "failed": M,
     *       "results": [
     *         {"type": "visit", "status": "success", "id": X, "timestamp": ...},
     *         {"type": "measurement", "status": "success", "id": Y, "timestamp": ...},
     *         {"type": "visit", "status": "duplicate", "id": Z, "timestamp": ...},
     *         {"type": "measurement", "status": "error", "message": "...", "timestamp": ...}
     *       ]
     *     }
     *   }
     */
    public function sync() {
        $authMode = $this->requireAuthOrApiKey(['sync:write']);
        // CSRF check skipped for offline sync (idempotency keys are the safety mechanism)

        $this->logPostArrival('SyncController::sync', [
            'auth_mode' => $authMode,
        ]);

        // Get queue from request
        $queue = $this->getJsonBody()['queue'] ?? [];

        if (empty($queue) || !is_array($queue)) {
            $this->badRequest('Invalid or empty queue');
        }

        $results = [];
        $processed = 0;
        $failed = 0;

        // Process each queued item
        foreach ($queue as $item) {
            $result = $this->processQueueItem($item);
            $results[] = $result;
            
            if (in_array($result['status'], ['success', 'duplicate'], true)) {
                $processed++;
            } else {
                $failed++;
            }
        }

        $this->success([
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($queue),
            'results' => $results,
        ], 200);
    }

    /**
     * Process a single queue item
     * 
     * Returns result object:
     *   {"type": "visit", "status": "success"|"duplicate"|"error", "id": X|null, "message": "...", "timestamp": ...}
     */
    private function processQueueItem($item) {
        $result = [
            'type' => $item['type'] ?? 'unknown',
            'status' => 'error',
            'id' => null,
            'message' => '',
            'errors' => null,
            'timestamp' => $item['timestamp'] ?? time(),
        ];

        // Validate item structure
        if (empty($item['type']) || empty($item['action']) || empty($item['data'])) {
            $result['message'] = 'Invalid queue item structure';
            return $result;
        }

        $type = $item['type'];
        $action = $item['action'];
        $data = $item['data'];
        $idempotencyKey = $item['idempotency_key'] ?? null;

        try {
            switch ($type) {
                case 'visit':
                    $result = $this->syncVisit($action, $data, $idempotencyKey, $result);
                    break;
                case 'measurement':
                    $result = $this->syncMeasurement($action, $data, $result);
                    break;
                case 'consumable':
                    $result = $this->syncConsumable($action, $data, $result);
                    break;
                case 'repair':
                    $result = $this->syncRepair($action, $data, $result);
                    break;
                case 'media':
                    $result = $this->syncMedia($action, $data, $result);
                    break;
                default:
                    $result['message'] = "Unknown queue item type: $type";
            }
        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
            $this->logError('SyncController::processQueueItem', $e->getMessage());
        }

        return $result;
    }

    /**
     * Sync visit
     */
    private function syncVisit($action, $data, $idempotencyKey, $result) {
        $model = new ServiceVisit($this->db);

        if ($action === 'create') {
            // Check for duplicate
            if ($idempotencyKey) {
                $existing = $model->findByIdempotencyKey($idempotencyKey);
                if ($existing) {
                    $result['status'] = 'duplicate';
                    $result['id'] = $existing['id'];
                    return $result;
                }
            }

            // Create new visit
            $data['idempotency_key'] = $idempotencyKey;
            $data['sync_status'] = 'synced';

            $id = $model->insert($data);
            if ($id) {
                $result['status'] = 'success';
                $result['id'] = $id;
            } else {
                $result['message'] = 'Failed to create visit';
            }
        } else {
            $result['message'] = "Unsupported action for visit: $action";
        }

        return $result;
    }

    /**
     * Sync measurement
     */
    private function syncMeasurement($action, $data, $result) {
        $model = new Measurement($this->db);

        if ($action === 'create') {
            $id = $model->insert($data);
            if ($id) {
                $result['status'] = 'success';
                $result['id'] = $id;
            } else {
                $result['message'] = 'Failed to create measurement';
            }
        } else {
            $result['message'] = "Unsupported action for measurement: $action";
        }

        return $result;
    }

    /**
     * Sync consumable
     */
    private function syncConsumable($action, $data, $result) {
        $model = new Consumable($this->db);

        if ($action === 'create') {
            $id = $model->insert($data);
            if ($id) {
                $result['status'] = 'success';
                $result['id'] = $id;
            } else {
                $result['message'] = 'Failed to create consumable';
            }
        } else {
            $result['message'] = "Unsupported action for consumable: $action";
        }

        return $result;
    }

    /**
     * Sync repair
     */
    private function syncRepair($action, $data, $result) {
        $model = new RepairRecommendation($this->db);

        if ($action === 'create') {
            $repair = $this->normalizeSyncRepairData($data);
            $errors = $model->validate($repair);
            if (!empty($errors)) {
                $result['message'] = 'Validation failed';
                $result['errors'] = $errors;
                return $result;
            }

            $id = $model->insert($repair);
            if ($id) {
                $result['status'] = 'success';
                $result['id'] = $id;
                $createdRepair = $model->find($id);
                $this->auditAction('insert', 'repair_recommendation', $id, null, $createdRepair, [
                    'controller' => static::class,
                    'source' => 'sync',
                ]);
            } else {
                $result['message'] = 'Failed to create repair';
            }
        } else {
            $result['message'] = "Unsupported action for repair: $action";
        }

        return $result;
    }

    /**
     * Sync media (file data stored base64 in queue - tablet handles actual file)
     */
    private function syncMedia($action, $data, $result) {
        $model = new MediaItem($this->db);

        if ($action === 'create') {
            // Media from offline queue is metadata only
            // Actual file upload happens via separate /api/media/upload endpoint
            $media = $this->normalizeSyncMediaData($data);
            $errors = $model->validate($media);
            if (!empty($errors)) {
                $result['message'] = 'Validation failed';
                $result['errors'] = $errors;
                return $result;
            }

            $id = $model->insert($media);
            if ($id) {
                $result['status'] = 'success';
                $result['id'] = $id;
                $createdMedia = $model->find($id);
                $this->auditAction('insert', 'media_item', $id, null, $createdMedia, [
                    'controller' => static::class,
                    'source' => 'sync',
                ]);
            } else {
                $result['message'] = 'Failed to create media record';
            }
        } else {
            $result['message'] = "Unsupported action for media: $action";
        }

        return $result;
    }

    private function normalizeSyncRepairData($data) {
        return [
            'visit_id' => $data['visit_id'] ?? ($data['service_visit_id'] ?? null),
            'equipment_id' => $data['equipment_id'] ?? null,
            'issue_description' => $data['issue_description'] ?? ($data['issue'] ?? null),
            'recommendation' => $data['recommendation'] ?? ($data['notes'] ?? null),
            'priority' => $data['priority'] ?? 'medium',
            'estimated_cost' => $data['estimated_cost'] ?? null,
            'status' => $data['status'] ?? 'recommended',
        ];
    }

    private function normalizeSyncMediaData($data) {
        $originalFilename = $data['original_filename'] ?? ($data['file_name'] ?? ($data['name'] ?? null));
        $storedFilename = $data['stored_filename'] ?? ($originalFilename ? basename($originalFilename) : null);
        $mimeType = $data['mime_type'] ?? null;
        $mediaType = $data['media_type'] ?? $this->deriveMediaTypeFromMime($mimeType);

        return [
            'visit_id' => $data['visit_id'] ?? ($data['service_visit_id'] ?? null),
            'equipment_id' => $data['equipment_id'] ?? null,
            'media_type' => $mediaType,
            'original_filename' => $originalFilename,
            'stored_filename' => $storedFilename,
            'file_path' => $data['file_path'] ?? null,
            'file_size' => $data['file_size'] ?? null,
            'mime_type' => $mimeType,
            'is_uploaded' => array_key_exists('is_uploaded', $data) ? (int) $data['is_uploaded'] : 0,
        ];
    }

    private function deriveMediaTypeFromMime($mimeType) {
        if (!is_string($mimeType) || $mimeType === '') {
            return 'document';
        }

        if (strpos($mimeType, 'image/') === 0) {
            return 'photo';
        }

        if (strpos($mimeType, 'video/') === 0) {
            return 'video';
        }

        return 'document';
    }
}
