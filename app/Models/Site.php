<?php
/**
 * Site Model
 */

class Site extends Model {
    protected $table = 'sites';
    protected $fillable = ['customer_id', 'site_name', 'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'contact_person', 'contact_phone', 'notes', 'is_active'];
    
    /**
     * Validate site data.
     */
    public function validate($data) {
        $errors = [];

        if (isset($data['customer_id'])) {
            if (!is_numeric($data['customer_id']) || $data['customer_id'] <= 0) {
                $errors['customer_id'] = 'Invalid customer ID';
            }
        }

        if (isset($data['site_name']) && trim($data['site_name']) === '') {
            $errors['site_name'] = 'Site name is required';
        }

        return $errors;
    }

    /**
     * Get sites for a customer.
     */
    public function getByCustomer($customerId, $limit = 20, $offset = 0) {
        return $this->where(['customer_id' => $customerId, 'is_active' => 1], $limit, $offset);
    }

    /**
     * Get all active sites with customer info.
     */
    public function getActiveWithCustomer($limit = 20, $offset = 0) {
        $sql = "SELECT s.*, c.name as customer_name FROM sites s
                JOIN customers c ON s.customer_id = c.id
                WHERE s.is_active = 1
                ORDER BY c.name, s.site_name
                LIMIT ? OFFSET ?";

        $result = $this->db->execute($sql, [(int)$limit, (int)$offset], 'ii');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
