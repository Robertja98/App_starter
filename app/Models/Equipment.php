<?php
/**
 * Equipment Model
 */

class Equipment extends Model {
    protected $table = 'equipment';
    protected $fillable = ['site_id', 'equipment_type', 'model', 'serial_number', 'capacity_liters', 'size_dimension', 'installation_date', 'last_service_date', 'is_active'];
    
    /**
     * Validate equipment data.
     */
    public function validate($data) {
        $errors = [];

        if (isset($data['site_id'])) {
            if (!is_numeric($data['site_id']) || $data['site_id'] <= 0) {
                $errors['site_id'] = 'Invalid site ID';
            }
        }

        if (isset($data['equipment_type']) && trim($data['equipment_type']) === '') {
            $errors['equipment_type'] = 'Equipment type is required';
        }

        if (isset($data['capacity_liters'])) {
            if (!is_numeric($data['capacity_liters']) || $data['capacity_liters'] < 0) {
                $errors['capacity_liters'] = 'Capacity must be a positive number';
            }
        }

        return $errors;
    }

    /**
     * Get equipment at a site.
     */
    public function getBySite($siteId, $limit = 20, $offset = 0) {
        return $this->where(['site_id' => $siteId, 'is_active' => 1], $limit, $offset);
    }

    /**
     * Get all active equipment with site info.
     */
    public function getActiveWithSite($siteId, $limit = 20, $offset = 0) {
        $sql = "SELECT e.*, s.site_name, c.name as customer_name FROM equipment e
                JOIN sites s ON e.site_id = s.id
                JOIN customers c ON s.customer_id = c.id
                WHERE e.is_active = 1 AND e.site_id = ?
                ORDER BY c.name, s.site_name, e.equipment_type
                LIMIT ? OFFSET ?";

        $result = $this->db->execute($sql, [(int)$siteId, (int)$limit, (int)$offset], 'iii');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
