<?php
/**
 * Measurement Endpoints
 * 
 * - GET /api/measurements – List measurements
 * - GET /api/measurements/{id} – Get single measurement
 * - POST /api/measurements – Log a chemical measurement
 */

require_once __DIR__ . '/../Models/Measurement.php';

class MeasurementController extends Controller {

    /**
     * GET /api/measurements
     * List measurements (optionally filtered by visit_id or equipment_id)
     */
    public function index() {
        $this->requireAuth();
        
        $model = new Measurement($this->db);
        $visitId = $this->getQuery('visit_id');
        $equipmentId = $this->getQuery('equipment_id');
        $limit = (int)($this->getQuery('limit') ?: 50);
        $offset = (int)($this->getQuery('offset') ?: 0);

        if (!$visitId && !$equipmentId) {
            $this->badRequest('Required: visit_id or equipment_id');
        }

        try {
            if ($visitId && is_numeric($visitId)) {
                $measurements = $model->getByVisit($visitId, $limit, $offset);
            } elseif ($equipmentId && is_numeric($equipmentId)) {
                // Get latest measurements for this equipment
                $stmt = $this->db->execute(
                    "SELECT * FROM measurements WHERE equipment_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
                    [$equipmentId, $limit, $offset],
                    'iii'
                );
                $measurements = [];
                while ($row = $stmt->fetch_assoc()) {
                    $measurements[] = $row;
                }
            } else {
                $this->badRequest('Invalid visit_id or equipment_id');
            }

            $this->success([
                'measurements' => $measurements ?: [],
                'limit' => $limit,
                'offset' => $offset,
            ], 200);
        } catch (Exception $e) {
            $this->logError('MeasurementController::index', $e->getMessage());
            $this->internalError('Failed to fetch measurements');
        }
    }

    /**
     * GET /api/measurements/{id}
     */
    public function show($measurementId) {
        $this->requireAuth();
        
        if (!is_numeric($measurementId)) {
            $this->badRequest('Invalid measurement ID');
        }

        $model = new Measurement($this->db);
        try {
            $measurement = $model->find($measurementId);
            if (!$measurement) {
                $this->error('Measurement not found', 404);
            }
            $this->success($measurement, 200);
        } catch (Exception $e) {
            $this->logError('MeasurementController::show', $e->getMessage());
            $this->internalError('Failed to fetch measurement');
        }
    }

    /**
     * POST /api/measurements
     * Log a chemical measurement (pH, chlorine, etc.)
     * 
     * Request Body:
     *   {
     *     "service_visit_id": X,
     *     "equipment_id": Y,
     *     "measurement_type": "pH",
     *     "value": 7.2,
     *     "unit": "pH",
     *     "status": "normal",  // normal, warning, critical
     *     "notes": "..."
     *   }
     */
    public function store() {
        $this->requireAuth();
        $this->requireCsrf();

        $this->logPostArrival('MeasurementController::store', [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
        ]);

        $model = new Measurement($this->db);
        
        $data = [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
            'equipment_id' => $this->getPost('equipment_id'),
            'measurement_type' => $this->getPost('measurement_type'),
            'value' => $this->getPost('value'),
            'unit' => $this->getPost('unit'),
            'status' => $this->getPost('status') ?: 'normal',
            'notes' => $this->getPost('notes'),
        ];

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $id = $model->insert($data);
            if (!$id) {
                $this->internalError('Failed to record measurement');
            }
            
            $measurement = $model->find($id);
            $this->success($measurement, 201);
        } catch (Exception $e) {
            $this->logError('MeasurementController::store', $e->getMessage());
            $this->internalError('Failed to record measurement');
        }
    }
}
