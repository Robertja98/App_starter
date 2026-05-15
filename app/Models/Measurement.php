<?php
/**
 * Measurement Model
 */

class Measurement extends Model {
    protected $table = 'measurements';
    protected $fillable = ['visit_id', 'equipment_id', 'measurement_type', 'value', 'unit', 'status'];
    
    /**
     * Validate measurement data.
     */
    public function validate($data) {
        $errors = [];

        if (isset($data['visit_id'])) {
            if (!is_numeric($data['visit_id']) || $data['visit_id'] <= 0) {
                $errors['visit_id'] = 'Invalid visit ID';
            }
        }

        if (isset($data['equipment_id'])) {
            if (!is_numeric($data['equipment_id']) || $data['equipment_id'] <= 0) {
                $errors['equipment_id'] = 'Invalid equipment ID';
            }
        }

        if (isset($data['measurement_type']) && trim($data['measurement_type']) === '') {
            $errors['measurement_type'] = 'Measurement type is required';
        }

        if (isset($data['value'])) {
            if (!is_numeric($data['value'])) {
                $errors['value'] = 'Value must be numeric';
            }
        }

        if (isset($data['status'])) {
            $validStatuses = ['normal', 'warning', 'critical'];
            if (!in_array($data['status'], $validStatuses)) {
                $errors['status'] = 'Invalid status';
            }
        }

        return $errors;
    }

    /**
     * Get measurements for a visit.
     */
    public function getByVisit($visitId) {
        return $this->where(['visit_id' => $visitId]);
    }

    /**
     * Get measurements for equipment at a visit.
     */
    public function getByEquipmentAndVisit($equipmentId, $visitId) {
        return $this->where(['equipment_id' => $equipmentId, 'visit_id' => $visitId]);
    }
}
