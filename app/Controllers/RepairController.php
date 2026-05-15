<?php
/**
 * Repair Recommendation Endpoints
 * 
 * - GET /api/repairs – List repair recommendations
 * - GET /api/repairs/{id} – Get single repair
 * - POST /api/repairs – Create repair recommendation
 * - PUT /api/repairs/{id} – Update repair status (approved, completed, declined)
 */

require_once __DIR__ . '/../Models/RepairRecommendation.php';

class RepairController extends Controller {

    /**
     * GET /api/repairs
     * List repair recommendations (optionally filtered by visit_id or priority)
     */
    public function index() {
        $this->requireAuth();
        
        $model = new RepairRecommendation($this->db);
        $visitId = $this->getQuery('visit_id');
        $priority = $this->getQuery('priority');
        $urgentOnly = $this->getQuery('urgent_only');
        $limit = (int)($this->getQuery('limit') ?: 50);
        $offset = (int)($this->getQuery('offset') ?: 0);

        try {
            if ($urgentOnly) {
                $repairs = $model->getUrgent($limit, $offset);
            } elseif ($visitId && is_numeric($visitId)) {
                $repairs = $model->getByVisit($visitId, $limit, $offset);
            } elseif ($priority) {
                // Filter by priority
                $stmt = $this->db->execute(
                    "SELECT * FROM repair_recommendations WHERE priority = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
                    [$priority, $limit, $offset],
                    'sii'
                );
                $repairs = [];
                while ($row = $stmt->fetch_assoc()) {
                    $repairs[] = $row;
                }
            } else {
                // List all repairs
                $stmt = $this->db->execute(
                    "SELECT * FROM repair_recommendations ORDER BY created_at DESC LIMIT ? OFFSET ?",
                    [$limit, $offset],
                    'ii'
                );
                $repairs = [];
                while ($row = $stmt->fetch_assoc()) {
                    $repairs[] = $row;
                }
            }

            $this->success([
                'repairs' => $repairs ?: [],
                'limit' => $limit,
                'offset' => $offset,
            ], 200);
        } catch (Exception $e) {
            $this->logError('RepairController::index', $e->getMessage());
            $this->internalError('Failed to fetch repairs');
        }
    }

    /**
     * GET /api/repairs/{id}
     */
    public function show($repairId) {
        $this->requireAuth();
        
        if (!is_numeric($repairId)) {
            $this->badRequest('Invalid repair ID');
        }

        $model = new RepairRecommendation($this->db);
        try {
            $repair = $model->find($repairId);
            if (!$repair) {
                $this->error('Repair not found', 404);
            }
            $this->success($repair, 200);
        } catch (Exception $e) {
            $this->logError('RepairController::show', $e->getMessage());
            $this->internalError('Failed to fetch repair');
        }
    }

    /**
     * POST /api/repairs
     * Create repair recommendation
     * 
     * Request Body:
     *   {
     *     "service_visit_id": X,
     *     "equipment_id": Y,
     *     "issue": "Pump seal is leaking",
     *     "priority": "high",  // low, medium, high, urgent
     *     "estimated_cost": 500.00,
     *     "status": "recommended",  // recommended, approved, completed, declined
     *     "notes": "..."
     *   }
     */
    public function store() {
        $this->requireAuth();
        // Skip CSRF if offline
        if (!$this->getPost('offline_mode')) {
            $this->requireCsrf();
        }

        $this->logPostArrival('RepairController::store', [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
            'offline' => (bool) $this->getPost('offline_mode'),
        ]);

        $model = new RepairRecommendation($this->db);
        
        $data = [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
            'equipment_id' => $this->getPost('equipment_id'),
            'issue_description' => $this->getPost('issue_description', $this->getPost('issue')),
            'recommendation' => $this->getPost('recommendation', $this->getPost('notes')),
            'priority' => $this->getPost('priority') ?: 'medium',
            'estimated_cost' => $this->getPost('estimated_cost'),
            'status' => $this->getPost('status') ?: 'recommended',
        ];

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $id = $model->insert($data);
            if (!$id) {
                $this->internalError('Failed to create repair recommendation');
            }
            
            $repair = $model->find($id);
            $this->auditAction('insert', 'repair_recommendation', $id, null, $repair, ['controller' => static::class]);
            $this->success($repair, 201);
        } catch (Exception $e) {
            $this->logError('RepairController::store', $e->getMessage());
            $this->internalError('Failed to create repair recommendation');
        }
    }

    /**
     * PUT /api/repairs/{id}
     * Update repair status (typically admin approves, technician marks complete)
     */
    public function update($repairId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($repairId)) {
            $this->badRequest('Invalid repair ID');
        }

        $model = new RepairRecommendation($this->db);
        
        // Verify repair exists
        $existing = $model->find($repairId);
        if (!$existing) {
            $this->error('Repair not found', 404);
        }

        // Only update allowed fields
        $data = array_merge($existing, [
            'status' => $this->getPost('status') ?: $existing['status'],
            'recommendation' => $this->getPost('recommendation', $this->getPost('notes')) !== null
                ? $this->getPost('recommendation', $this->getPost('notes'))
                : ($existing['recommendation'] ?? null),
        ]);

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $updated = $model->updateById($repairId, $data);
            if (!$updated) {
                $this->internalError('Failed to update repair');
            }

            $repair = $model->find($repairId);
            $this->auditAction('update', 'repair_recommendation', $repairId, $existing, $repair, ['controller' => static::class]);
            $this->success($repair, 200);
        } catch (Exception $e) {
            $this->logError('RepairController::update', $e->getMessage());
            $this->internalError('Failed to update repair');
        }
    }

    /**
     * DELETE /api/repairs/{id}
     * Soft-delete repair
     */
    public function destroy($repairId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($repairId)) {
            $this->badRequest('Invalid repair ID');
        }

        $model = new RepairRecommendation($this->db);
        
        // Verify repair exists
        if (!$model->find($repairId)) {
            $this->error('Repair not found', 404);
        }

        try {
            $existing = $model->find($repairId);
            $deleted = $model->deleteById($repairId);
            if (!$deleted) {
                $this->internalError('Failed to delete repair');
            }

            $this->auditAction('delete', 'repair_recommendation', $repairId, $existing, ['deleted' => true], ['controller' => static::class]);
            $this->success(['id' => $repairId, 'deleted' => true], 200);
        } catch (Exception $e) {
            $this->logError('RepairController::destroy', $e->getMessage());
            $this->internalError('Failed to delete repair');
        }
    }
}
