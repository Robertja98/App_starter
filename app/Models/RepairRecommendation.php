<?php
/**
 * RepairRecommendation Model
 */

class RepairRecommendation extends Model {
    protected $table = 'repair_recommendations';
    protected $fillable = ['visit_id', 'equipment_id', 'issue_description', 'recommendation', 'priority', 'estimated_cost', 'status'];
    
    /**
     * Validate repair recommendation data.
     */
    public function validate($data) {
        $errors = [];

        if (!array_key_exists('visit_id', $data) || $data['visit_id'] === null || $data['visit_id'] === '') {
            $errors['visit_id'] = 'Visit ID is required';
        } elseif (!is_numeric($data['visit_id']) || $data['visit_id'] <= 0) {
            $errors['visit_id'] = 'Invalid visit ID';
        }

        if (!array_key_exists('equipment_id', $data) || $data['equipment_id'] === null || $data['equipment_id'] === '') {
            $errors['equipment_id'] = 'Equipment ID is required';
        } elseif (!is_numeric($data['equipment_id']) || $data['equipment_id'] <= 0) {
            $errors['equipment_id'] = 'Invalid equipment ID';
        }

        if (!array_key_exists('issue_description', $data) || trim((string) $data['issue_description']) === '') {
            $errors['issue_description'] = 'Issue description is required';
        }

        if (isset($data['priority'])) {
            $validPriorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($data['priority'], $validPriorities)) {
                $errors['priority'] = 'Invalid priority';
            }
        }

        if (isset($data['status'])) {
            $validStatuses = ['recommended', 'approved', 'completed', 'declined'];
            if (!in_array($data['status'], $validStatuses)) {
                $errors['status'] = 'Invalid status';
            }
        }

        if (isset($data['estimated_cost'])) {
            if (!is_numeric($data['estimated_cost']) || $data['estimated_cost'] < 0) {
                $errors['estimated_cost'] = 'Estimated cost must be a positive number';
            }
        }

        return $errors;
    }

    /**
     * Get recommendations for a visit.
     */
    public function getByVisit($visitId, $limit = 50, $offset = 0) {
        return $this->where(['visit_id' => $visitId], $limit, $offset);
    }

    /**
     * Get urgent repairs.
     */
    public function getUrgent($limit = 50, $offset = 0) {
        return $this->where(['priority' => 'urgent', 'status' => 'recommended'], $limit, $offset);
    }
}
