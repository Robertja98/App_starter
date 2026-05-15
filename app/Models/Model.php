<?php
/**
 * Base Model Class
 * 
 * Provides common CRUD methods for all models.
 * Extend this class and override $table and validation methods as needed.
 */

class Model {
    protected $db;
    protected $table;
    protected $fillable = []; // Columns that can be mass-assigned
    protected $guarded = ['id', 'created_at', 'updated_at']; // Columns that cannot be mass-assigned
    
    public function __construct(Database $db) {
        $this->db = $db;
    }

    /**
     * Fetch a single record by ID.
     */
    public function find($id) {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
        $result = $this->db->execute($sql, [$id]);

        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }

        return null;
    }

    /**
     * Fetch all records (with optional limit/offset).
     */
    public function all($limit = null, $offset = 0) {
        $sql = "SELECT * FROM {$this->table}";

        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $result = $this->db->execute($sql, [$limit, $offset]);
        } else {
            $result = $this->db->execute($sql);
        }

        if ($result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    /**
     * Fetch records by condition.
     * 
     * @param array $where Conditions: ['status' => 'active', 'is_deleted' => false]
     * @param int|null $limit Optional limit
     * @param int $offset Optional offset
     * @return array Array of records
     */
    public function where($where, $limit = null, $offset = 0) {
        $whereClauses = [];
        $params = [];

        foreach ($where as $column => $value) {
            $whereClauses[] = "$column = ?";
            $params[] = $value;
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $whereClauses);

        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $result = $this->db->execute($sql, $params);

        if ($result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    /**
     * Fetch a single record by condition.
     */
    public function findWhere($where) {
        $records = $this->where($where, 1);
        return !empty($records) ? $records[0] : null;
    }

    /**
     * Count records by condition.
     */
    public function count($where = []) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        $params = [];

        if (!empty($where)) {
            $whereClauses = [];
            foreach ($where as $column => $value) {
                $whereClauses[] = "$column = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $result = $this->db->execute($sql, $params);

        if ($result) {
            $row = $result->fetch_assoc();
            return (int) $row['count'];
        }

        return 0;
    }

    /**
     * Insert a new record.
     * 
     * @param array $data Column => value pairs
     * @return int|false Inserted ID or false on error
     */
    public function insert($data) {
        // Filter data based on fillable/guarded
        $data = $this->filterFillable($data);

        if (empty($data)) {
            return false;
        }

        return $this->db->insert($this->table, $data);
    }

    /**
     * Update a record by ID.
     * 
     * @param int $id Record ID
     * @param array $data Columns to update
     * @return int|false Affected rows or false on error
     */
    public function updateById($id, $data) {
        $data = $this->filterFillable($data);

        if (empty($data)) {
            return false;
        }

        return $this->db->update($this->table, $data, ['id' => $id]);
    }

    /**
     * Update records by condition.
     */
    public function updateWhere($where, $data) {
        $data = $this->filterFillable($data);

        if (empty($data)) {
            return false;
        }

        return $this->db->update($this->table, $data, $where);
    }

    /**
     * Delete a record by ID.
     */
    public function deleteById($id) {
        return $this->db->delete($this->table, ['id' => $id]);
    }

    /**
     * Delete records by condition.
     */
    public function deleteWhere($where) {
        return $this->db->delete($this->table, $where);
    }

    /**
     * Filter data based on fillable/guarded columns.
     * If fillable is set, only allow those columns.
     * Otherwise, remove guarded columns.
     */
    protected function filterFillable($data) {
        if (!empty($this->fillable)) {
            // Whitelist mode: only allow fillable columns
            return array_intersect_key($data, array_flip($this->fillable));
        } else {
            // Blacklist mode: remove guarded columns
            return array_diff_key($data, array_flip($this->guarded));
        }
    }

    /**
     * Validate data before insert/update.
     * Override in child classes.
     * 
     * @return array Empty array if valid, or array of error messages
     */
    public function validate($data) {
        return [];
    }

    /**
     * Get table name.
     */
    public function getTable() {
        return $this->table;
    }

    /**
     * Get database connection.
     */
    public function getDb() {
        return $this->db;
    }
}
