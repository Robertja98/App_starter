<?php
/**
 * Consumable Endpoints
 * 
 * - GET /api/consumables – List consumables used
 * - GET /api/consumables/{id} – Get single consumable
 * - POST /api/consumables – Log consumable used
 */

require_once __DIR__ . '/../Models/Consumable.php';

class ConsumableController extends Controller {

    /**
     * GET /api/consumables
     * List consumables (optionally filtered by visit_id)
     */
    public function index() {
        $this->requireAuth();
        
        $model = new Consumable($this->db);
        $visitId = $this->getQuery('visit_id');
        $billableOnly = $this->getQuery('billable_only');
        $limit = (int)($this->getQuery('limit') ?: 50);
        $offset = (int)($this->getQuery('offset') ?: 0);

        try {
            if ($visitId && is_numeric($visitId)) {
                if ($billableOnly) {
                    $consumables = $model->getBillableByVisit($visitId, $limit, $offset);
                } else {
                    $consumables = $model->getByVisit($visitId, $limit, $offset);
                }
            } else {
                // List all consumables
                $stmt = $this->db->execute(
                    "SELECT * FROM consumables_used ORDER BY created_at DESC LIMIT ? OFFSET ?",
                    [$limit, $offset],
                    'ii'
                );
                $consumables = [];
                while ($row = $stmt->fetch_assoc()) {
                    $consumables[] = $row;
                }
            }

            $this->success([
                'consumables' => $consumables ?: [],
                'limit' => $limit,
                'offset' => $offset,
            ], 200);
        } catch (Exception $e) {
            $this->logError('ConsumableController::index', $e->getMessage());
            $this->internalError('Failed to fetch consumables');
        }
    }

    /**
     * GET /api/consumables/{id}
     */
    public function show($consumableId) {
        $this->requireAuth();
        
        if (!is_numeric($consumableId)) {
            $this->badRequest('Invalid consumable ID');
        }

        $model = new Consumable($this->db);
        try {
            $consumable = $model->find($consumableId);
            if (!$consumable) {
                $this->error('Consumable not found', 404);
            }
            $this->success($consumable, 200);
        } catch (Exception $e) {
            $this->logError('ConsumableController::show', $e->getMessage());
            $this->internalError('Failed to fetch consumable');
        }
    }

    /**
     * POST /api/consumables
     * Log consumable used during visit
     * 
     * Request Body:
     *   {
     *     "service_visit_id": X,
     *     "equipment_id": Y,
     *     "name": "Water Filter",
     *     "quantity_used": 1,
     *     "unit": "unit",
     *     "cost": 45.00,
     *     "is_billable": true,
     *     "reason": "Routine replacement",
     *     "notes": "..."
     *   }
     */
    public function store() {
        $this->requireAuth();
        $this->requireCsrf();

        $this->logPostArrival('ConsumableController::store', [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
        ]);

        $model = new Consumable($this->db);
        
        $data = [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
            'equipment_id' => $this->getPost('equipment_id'),
            'consumable_name' => $this->getPost('consumable_name', $this->getPost('name')),
            'quantity_used' => $this->getPost('quantity_used'),
            'unit' => $this->getPost('unit') ?: 'unit',
            'is_billable' => (int)$this->getPost('is_billable'),
            'reason' => $this->getPost('reason'),
        ];

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $id = $model->insert($data);
            if (!$id) {
                $this->internalError('Failed to record consumable');
            }
            
            $consumable = $model->find($id);
            $this->success($consumable, 201);
        } catch (Exception $e) {
            $this->logError('ConsumableController::store', $e->getMessage());
            $this->internalError('Failed to record consumable');
        }
    }
}
