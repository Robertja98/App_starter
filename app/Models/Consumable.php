<?php
/**
 * Consumable Model
 */

class Consumable extends Model {
    protected $table = 'consumables_used';
    protected $fillable = ['visit_id', 'equipment_id', 'consumable_name', 'quantity_used', 'unit', 'reason', 'is_billable'];
    
    /**
     * Validate consumable data.
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

        if (isset($data['consumable_name']) && trim($data['consumable_name']) === '') {
            $errors['consumable_name'] = 'Consumable name is required';
        }

        if (isset($data['quantity_used'])) {
            if (!is_numeric($data['quantity_used']) || $data['quantity_used'] <= 0) {
                $errors['quantity_used'] = 'Quantity must be a positive number';
            }
        }

        return $errors;
    }

    /**
     * Get consumables used in a visit.
     */
    public function getByVisit($visitId, $limit = 50, $offset = 0) {
        return $this->where(['visit_id' => $visitId], $limit, $offset);
    }

    /**
     * Get billable consumables for a visit.
     */
    public function getBillableByVisit($visitId, $limit = 50, $offset = 0) {
        return $this->where(['visit_id' => $visitId, 'is_billable' => 1], $limit, $offset);
    }
}
