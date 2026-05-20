<?php
/**
 * Service Visit Endpoints (Core Workflow)
 * 
 * - GET /api/visits – List visits
 * - GET /api/visits/{id} – Get visit with all related data
 * - POST /api/visits – Create visit (supports offline idempotency key)
 * - PUT /api/visits/{id} – Update visit status
 * - POST /api/visits/{id}/complete – Mark visit complete
 */

require_once __DIR__ . '/../Models/ServiceVisit.php';
require_once __DIR__ . '/../Models/Equipment.php';

class ServiceVisitController extends Controller {

    /**
     * GET /api/visits/recent
     * Return recent visits for a technician.
     */
    public function recent() {
        $this->requireAuth();

        $model = new ServiceVisit($this->db);
        $requestedTechnicianId = $this->getQuery('technician_id');
        $limit = (int)($this->getQuery('limit') ?: 10);
        if ($limit < 1) {
            $limit = 10;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $currentUser = $this->getUser();
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $currentRole = $currentUser['role'] ?? '';

        if ($currentRole === 'technician') {
            $technicianId = $currentUserId;
        } elseif ($requestedTechnicianId && is_numeric($requestedTechnicianId)) {
            $technicianId = (int)$requestedTechnicianId;
        } else {
            $this->badRequest('technician_id is required for non-technician users');
        }

        try {
            $visits = $model->getRecentByTechnician($technicianId, $limit);
            $this->success([
                'technician_id' => $technicianId,
                'recent_visits' => $visits ?: [],
                'limit' => $limit,
            ], 200);
        } catch (Exception $e) {
            $this->logError('ServiceVisitController::recent', $e->getMessage());
            $this->internalError('Failed to fetch recent visits');
        }
    }

    /**
     * GET /api/visits
     * List service visits
     * 
     * Query Parameters:
     *   ?site_id=X – Filter by site
     *   ?status=in-progress – Filter by status (scheduled, in-progress, pending-review, completed)
     *   ?limit=20&offset=0 – Pagination
     * 
     * Response:
     *   {"status": "success", "data": {"visits": [...], "total": N}}
     */
    public function index() {
        $this->requireAuth();
        
        $model = new ServiceVisit($this->db);
        $siteId = $this->getQuery('site_id');
        $status = $this->getQuery('status');
        $limit = (int)($this->getQuery('limit') ?: 20);
        $offset = (int)($this->getQuery('offset') ?: 0);

        try {
            $conditions = [];
            
            if ($siteId && is_numeric($siteId)) {
                $conditions['site_id'] = $siteId;
            }
            
            if ($status && in_array($status, ['scheduled', 'in-progress', 'pending-review', 'completed'])) {
                $conditions['visit_status'] = $status;
            }

            $visits = $model->where($conditions, $limit, $offset);
            $total = $model->count($conditions);

            $this->success([
                'visits' => $visits ?: [],
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
            ], 200);
        } catch (Exception $e) {
            $this->logError('ServiceVisitController::index', $e->getMessage());
            $this->internalError('Failed to fetch visits');
        }
    }

    /**
     * GET /api/visits/{id}
     * Get complete visit with all related data (measurements, consumables, repairs, media)
     */
    public function show($visitId) {
        $this->requireAuth();
        
        if (!is_numeric($visitId)) {
            $this->badRequest('Invalid visit ID');
        }

        $model = new ServiceVisit($this->db);
        try {
            $visit = $model->find($visitId);
            if (!$visit) {
                $this->error('Visit not found', 404);
            }

            // Add related data
            $visit['measurements'] = $this->getRelatedData('measurements', 'visit_id', $visitId);
            $visit['consumables'] = $this->getRelatedData('consumables_used', 'visit_id', $visitId);
            $visit['repairs'] = $this->getRelatedData('repair_recommendations', 'visit_id', $visitId);
            $visit['media'] = $this->getRelatedData('media_items', 'visit_id', $visitId);

            $this->success($visit, 200);
        } catch (Exception $e) {
            $this->logError('ServiceVisitController::show', $e->getMessage());
            $this->internalError('Failed to fetch visit');
        }
    }

    /**
     * POST /api/visits
     * Create new service visit
     * 
     * Supports offline idempotency: if idempotency_key is provided and a visit with that key exists,
     * return the existing visit (prevents duplicates when offline queue syncs).
     * 
     * Request Body:
     *   {
     *     "site_id": X,
     *     "technician_id": Z,
     *     "visit_status": "in-progress",
     *     "visit_date": "2026-05-15",
     *     "idempotency_key": "uuid-v4-string",  // Optional, for offline sync
     *     "visit_notes": "..."
     *   }
     */
    public function store() {
        $this->requireAuth();
        $this->requireCsrf();

        $this->logPostArrival('ServiceVisitController::store', [
            'has_idempotency_key' => (bool)$this->getPost('idempotency_key'),
            'site_id' => $this->getPost('site_id'),
        ]);

        $model = new ServiceVisit($this->db);
        
        $idempotencyKey = $this->getPost('idempotency_key');
        
        // Check for duplicate offline submission
        if ($idempotencyKey) {
            $existing = $model->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                $this->success(['id' => $existing['id'], 'duplicate' => true], 200);
            }
        }

        $data = [
            'site_id' => $this->getPost('site_id'),
            'technician_id' => $this->getPost('technician_id'),
            'visit_status' => $this->getPost('visit_status', $this->getPost('status', 'scheduled')),
            'visit_date' => $this->getPost('visit_date', $this->getPost('scheduled_date')),
            'visit_notes' => $this->getPost('visit_notes', $this->getPost('notes')),
            'idempotency_key' => $idempotencyKey,
            'sync_status' => $idempotencyKey ? 'pending-sync' : 'synced',
        ];

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $id = $model->insert($data);
            if (!$id) {
                $this->internalError('Failed to create visit');
            }
            
            $visit = $model->find($id);
            $this->auditAction('insert', 'service_visit', $id, null, $visit, ['controller' => static::class]);
            $this->success($visit, 201);
        } catch (Exception $e) {
            $this->logError('ServiceVisitController::store', $e->getMessage());
            $this->internalError('Failed to create visit');
        }
    }

    /**
     * PUT /api/visits/{id}
     * Update visit (status, notes, etc.)
     */
    public function update($visitId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($visitId)) {
            $this->badRequest('Invalid visit ID');
        }

        $model = new ServiceVisit($this->db);
        
        // Verify visit exists
        $existing = $model->find($visitId);
        if (!$existing) {
            $this->error('Visit not found', 404);
        }

        // Merge existing data with new data
        $data = array_merge($existing, [
            'visit_status' => $this->getPost('visit_status', $this->getPost('status', $existing['visit_status'])),
            'visit_notes' => $this->getPost('visit_notes', $this->getPost('notes', $existing['visit_notes'])),
            'visit_date' => $this->getPost('visit_date', $this->getPost('scheduled_date', $existing['visit_date'])),
        ]);

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $updated = $model->updateById($visitId, $data);
            if (!$updated) {
                $this->internalError('Failed to update visit');
            }

            $visit = $model->find($visitId);
            $this->auditAction('update', 'service_visit', $visitId, $existing, $visit, ['controller' => static::class]);
            $this->success($visit, 200);
        } catch (Exception $e) {
            $this->logError('ServiceVisitController::update', $e->getMessage());
            $this->internalError('Failed to update visit');
        }
    }

    /**
     * POST /api/visits/{id}/complete
     * Mark visit as complete
     */
    public function complete($visitId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($visitId)) {
            $this->badRequest('Invalid visit ID');
        }

        $model = new ServiceVisit($this->db);
        
        // Verify visit exists
        if (!$model->find($visitId)) {
            $this->error('Visit not found', 404);
        }

        try {
            $completed = $model->updateById($visitId, [
                'visit_status' => 'completed',
                'end_time' => date('H:i:s'),
                'sync_status' => 'synced',
            ]);
            
            if (!$completed) {
                $this->internalError('Failed to complete visit');
            }

            $visit = $model->find($visitId);
            $this->auditAction('complete', 'service_visit', $visitId, null, $visit, ['controller' => static::class]);
            $this->success($visit, 200);
        } catch (Exception $e) {
            $this->logError('ServiceVisitController::complete', $e->getMessage());
            $this->internalError('Failed to complete visit');
        }
    }

    /**
     * Helper: Get related data for a visit
     */
    private function getRelatedData($table, $foreignKeyColumn, $visitId) {
        try {
            $stmt = $this->db->execute(
                "SELECT * FROM $table WHERE $foreignKeyColumn = ? ORDER BY created_at DESC",
                [$visitId],
                'i'
            );
            $result = [];
            while ($row = $stmt->fetch_assoc()) {
                $result[] = $row;
            }
            return $result;
        } catch (Exception $e) {
            $this->logError('ServiceVisitController::getRelatedData', $e->getMessage());
            return [];
        }
    }

}
