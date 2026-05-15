<?php
/**
 * Customer Model
 */

class Customer extends Model {
    protected $table = 'customers';
    protected $fillable = ['name', 'contact_email', 'contact_phone', 'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'country', 'notes', 'is_active'];
    
    /**
     * Validate customer data.
     */
    public function validate($data) {
        $errors = [];

        if (isset($data['name']) && trim($data['name']) === '') {
            $errors['name'] = 'Customer name is required';
        }

        if (isset($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Invalid email format';
        }

        return $errors;
    }

    /**
     * Get active customers with related sites.
     */
    public function getActiveWithSites() {
        $sql = "SELECT c.*, COUNT(s.id) as site_count FROM customers c 
                LEFT JOIN sites s ON c.id = s.customer_id 
                WHERE c.is_active = 1 
                GROUP BY c.id";
        
        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
