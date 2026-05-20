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

    /**
     * Get most recent visits for a technician.
     */
    public function getRecentByTechnician($technicianId, $limit = 10) {
        $sql = "SELECT sv.*, s.site_name
                FROM service_visits sv
                JOIN sites s ON s.id = sv.site_id
                WHERE sv.technician_id = ?
                ORDER BY sv.visit_date DESC, sv.id DESC
                LIMIT ?";

        $result = $this->db->execute($sql, [(int)$technicianId, (int)$limit], 'ii');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Get visit history aggregate metrics for a site.
     */
    public function getSiteHistoryMetrics($siteId) {
        $sql = "SELECT
                    COUNT(*) AS total_visits,
                    SUM(CASE WHEN visit_status = 'completed' THEN 1 ELSE 0 END) AS completed_visits,
                    SUM(CASE WHEN visit_status = 'pending-review' THEN 1 ELSE 0 END) AS pending_review_visits,
                    SUM(CASE WHEN visit_status = 'in-progress' THEN 1 ELSE 0 END) AS in_progress_visits,
                    SUM(CASE WHEN visit_status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_visits,
                    MAX(visit_date) AS last_visit_date,
                    MIN(visit_date) AS first_visit_date
                FROM service_visits
                WHERE site_id = ?";

        $result = $this->db->execute($sql, [(int)$siteId], 'i');
        if (!$result) {
            return [
                'total_visits' => 0,
                'completed_visits' => 0,
                'pending_review_visits' => 0,
                'in_progress_visits' => 0,
                'scheduled_visits' => 0,
                'last_visit_date' => null,
                'first_visit_date' => null,
            ];
        }

        $row = $result->fetch_assoc() ?: [];
        return [
            'total_visits' => (int)($row['total_visits'] ?? 0),
            'completed_visits' => (int)($row['completed_visits'] ?? 0),
            'pending_review_visits' => (int)($row['pending_review_visits'] ?? 0),
            'in_progress_visits' => (int)($row['in_progress_visits'] ?? 0),
            'scheduled_visits' => (int)($row['scheduled_visits'] ?? 0),
            'last_visit_date' => $row['last_visit_date'] ?? null,
            'first_visit_date' => $row['first_visit_date'] ?? null,
        ];
    }

    /**
     * Get recent visits for a site.
     */
    public function getRecentBySite($siteId, $limit = 10) {
        $sql = "SELECT id, site_id, technician_id, visit_status, visit_date, start_time, end_time, sync_status, created_at
                FROM service_visits
                WHERE site_id = ?
                ORDER BY visit_date DESC, id DESC
                LIMIT ?";

        $result = $this->db->execute($sql, [(int)$siteId, (int)$limit], 'ii');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
