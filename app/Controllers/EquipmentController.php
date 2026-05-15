<?php
/**
 * Equipment Endpoints
 * 
 * - GET /api/equipment – List equipment (optionally filtered by site_id)
 * - GET /api/equipment/{id} – Get single equipment details
 * - POST /api/equipment – Create equipment
 * - PUT /api/equipment/{id} – Update equipment
 * - DELETE /api/equipment/{id} – Delete equipment
 */

require_once __DIR__ . '/../Models/Equipment.php';

class EquipmentController extends Controller {

    /**
     * GET /api/equipment
     * List equipment (optionally filtered by site_id)
     * 
     * Query Parameters:
     *   ?site_id=X – Filter equipment by site (required for field techs)
     *   ?limit=20&offset=0 – Pagination
     * 
     * Response:
     *   {"status": "success", "data": {"equipment": [...], "total": N}}
     */
    public function index() {
        $this->requireAuth();
        
        $model = new Equipment($this->db);
        $siteId = $this->getQuery('site_id');
        $limit = (int)($this->getQuery('limit') ?: 20);
        $offset = (int)($this->getQuery('offset') ?: 0);

        if (!$siteId) {
            $this->badRequest('Required parameter missing: site_id');
        }

        if (!is_numeric($siteId)) {
            $this->badRequest('Invalid site_id');
        }

        try {
            // Get equipment at this site with related customer/site info
            $equipment = $model->getActiveWithSite($siteId, $limit, $offset);
            
            // Count total equipment at this site
            $countStmt = $this->db->execute(
                "SELECT COUNT(*) as cnt FROM equipment WHERE site_id = ? AND is_active = 1",
                [$siteId],
                'i'
            );
            $total = $countStmt->fetch_assoc()['cnt'] ?? 0;

            $this->success([
                'equipment' => $equipment ?: [],
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
            ], 200);
        } catch (Exception $e) {
            $this->logError('EquipmentController::index', $e->getMessage());
            $this->internalError('Failed to fetch equipment');
        }
    }

    /**
     * GET /api/equipment/{id}
     * Get single equipment with detailed info
     */
    public function show($equipmentId) {
        $this->requireAuth();
        
        if (!is_numeric($equipmentId)) {
            $this->badRequest('Invalid equipment ID');
        }

        $model = new Equipment($this->db);
        try {
            $equipment = $model->find($equipmentId);
            if (!$equipment) {
                $this->error('Equipment not found', 404);
            }

            // Add recent measurements count
            $measurementsStmt = $this->db->execute(
                "SELECT COUNT(*) as cnt FROM measurements WHERE equipment_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                [$equipmentId],
                'i'
            );
            $recentMeasurements = $measurementsStmt->fetch_assoc()['cnt'] ?? 0;
            
            $equipment['recent_measurements'] = (int)$recentMeasurements;

            $this->success($equipment, 200);
        } catch (Exception $e) {
            $this->logError('EquipmentController::show', $e->getMessage());
            $this->internalError('Failed to fetch equipment');
        }
    }

    /**
     * POST /api/equipment
     * Create new equipment at a site
     * 
     * Request Body:
    *   {"site_id": X, "equipment_type": "tank", "model": "Softener Tank", "capacity_liters": 1000, ...}
     */
    public function store() {
        $this->requireAuth();
        $this->requireCsrf();

        $model = new Equipment($this->db);
        
        $data = [
            'site_id' => $this->getPost('site_id'),
            'equipment_type' => $this->getPost('equipment_type'),
            'model' => $this->getPost('model', $this->getPost('name')),
            'serial_number' => $this->getPost('serial_number'),
            'capacity_liters' => $this->getPost('capacity_liters'),
            'size_dimension' => $this->getPost('size_dimension'),
            'installation_date' => $this->getPost('installation_date'),
            'last_service_date' => $this->getPost('last_service_date'),
        ];

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $id = $model->insert($data);
            if (!$id) {
                $this->internalError('Failed to create equipment');
            }
            
            $equipment = $model->find($id);
            $this->auditAction('insert', 'equipment', $id, null, $equipment, ['controller' => static::class]);
            $this->success($equipment, 201);
        } catch (Exception $e) {
            $this->logError('EquipmentController::store', $e->getMessage());
            $this->internalError('Failed to create equipment');
        }
    }

    /**
     * PUT /api/equipment/{id}
     * Update equipment
     */
    public function update($equipmentId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($equipmentId)) {
            $this->badRequest('Invalid equipment ID');
        }

        $model = new Equipment($this->db);
        
        // Verify equipment exists
        $existing = $model->find($equipmentId);
        if (!$existing) {
            $this->error('Equipment not found', 404);
        }

        // Merge existing data with new data
        $data = array_merge($existing, [
            'equipment_type' => $this->getPost('equipment_type', $existing['equipment_type']),
            'model' => $this->getPost('model', $this->getPost('name', $existing['model'])),
            'serial_number' => $this->getPost('serial_number', $existing['serial_number']),
            'capacity_liters' => $this->getPost('capacity_liters', $existing['capacity_liters']),
            'size_dimension' => $this->getPost('size_dimension', $existing['size_dimension']),
            'installation_date' => $this->getPost('installation_date', $existing['installation_date']),
            'last_service_date' => $this->getPost('last_service_date', $existing['last_service_date']),
        ]);

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $updated = $model->updateById($equipmentId, $data);
            if (!$updated) {
                $this->internalError('Failed to update equipment');
            }

            $equipment = $model->find($equipmentId);
            $this->auditAction('update', 'equipment', $equipmentId, $existing, $equipment, ['controller' => static::class]);
            $this->success($equipment, 200);
        } catch (Exception $e) {
            $this->logError('EquipmentController::update', $e->getMessage());
            $this->internalError('Failed to update equipment');
        }
    }

    /**
     * DELETE /api/equipment/{id}
     * Soft-delete equipment
     */
    public function destroy($equipmentId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($equipmentId)) {
            $this->badRequest('Invalid equipment ID');
        }

        $model = new Equipment($this->db);
        
        // Verify equipment exists
        if (!$model->find($equipmentId)) {
            $this->error('Equipment not found', 404);
        }

        try {
            $deleted = $model->updateById($equipmentId, ['is_active' => 0]);
            if (!$deleted) {
                $this->internalError('Failed to delete equipment');
            }

            $this->auditAction('delete', 'equipment', $equipmentId, ['is_active' => 1], ['is_active' => 0], ['controller' => static::class]);

            $this->success(['id' => $equipmentId, 'deleted' => true], 200);
        } catch (Exception $e) {
            $this->logError('EquipmentController::destroy', $e->getMessage());
            $this->internalError('Failed to delete equipment');
        }
    }
}
