<?php
/**
 * ServiceVisit Model
 */

class ServiceVisit extends Model {
    protected $table = 'service_visits';
    protected $fillable = ['site_id', 'technician_id', 'visit_status', 'visit_date', 'start_time', 'end_time', 'visit_notes', 'customer_signature_path', 'signature_timestamp', 'sync_status', 'idempotency_key'];
    
    /**
     * Validate visit data.
     */
    public function validate($data) {
        $errors = [];

        if (isset($data['site_id'])) {
            if (!is_numeric($data['site_id']) || $data['site_id'] <= 0) {
                $errors['site_id'] = 'Invalid site ID';
            }
        }

        if (isset($data['technician_id'])) {
            if (!is_numeric($data['technician_id']) || $data['technician_id'] <= 0) {
                $errors['technician_id'] = 'Invalid technician ID';
            }
        }

        if (isset($data['visit_status'])) {
            $validStatuses = ['scheduled', 'in-progress', 'pending-review', 'completed'];
            if (!in_array($data['visit_status'], $validStatuses)) {
                $errors['visit_status'] = 'Invalid visit status';
            }
        }

        if (isset($data['visit_date'])) {
            if (!strtotime($data['visit_date'])) {
                $errors['visit_date'] = 'Invalid date format';
            }
        }

        return $errors;
    }

    /**
     * Find visit by idempotency key (prevent duplicate submissions).
     */
    public function findByIdempotencyKey($key) {
        return $this->findWhere(['idempotency_key' => $key]);
    }

    /**
     * Get visits for a site.
     */
    public function getBySite($siteId) {
        return $this->where(['site_id' => $siteId], 100, 0);
    }

    /**
     * Get visits by technician.
     */
    public function getByTechnician($technicianId) {
        return $this->where(['technician_id' => $technicianId], 100, 0);
    }

    /**
     * Get pending sync visits (offline submissions).
     */
    public function getPendingSync() {
        return $this->where(['sync_status' => 'pending-sync'], 50, 0);
    }

    /**
     * Mark visit as synced.
     */
    public function markSynced($visitId) {
        return $this->updateById($visitId, ['sync_status' => 'synced']);
    }
}
