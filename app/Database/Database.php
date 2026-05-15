<?php
/**
 * Database Connection Manager
 * 
 * MySQLi wrapper with error handling, prepared statements, and logging.
 * Follows lessons_learned standards:
 * - Type-safe binding ('i', 's', 'd')
 * - Proper null handling
 * - Error logging on connection/query failures
 */

class Database {
    private $connection;
    private $config;
    private $logFile;
    
    public function __construct($config) {
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../error_log.txt';
        $this->connect();
    }

    /**
     * Establish database connection.
     */
    private function connect() {
        try {
            $this->connection = new mysqli(
                $this->config['db']['host'],
                $this->config['db']['user'],
                $this->config['db']['password'],
                $this->config['db']['database']
            );

            if ($this->connection->connect_error) {
                throw new Exception('Connection failed: ' . $this->connection->connect_error);
            }

            // Set charset
            if (!$this->connection->set_charset($this->config['db']['charset'] ?? 'utf8mb4')) {
                throw new Exception('Set charset failed: ' . $this->connection->error);
            }

            // Set timezone
            $this->connection->query("SET time_zone='+00:00'");

        } catch (Exception $e) {
            $this->logError('Database connection failed', ['error' => $e->getMessage()]);
            
            if ($this->config['app']['debug']) {
                exit('Database connection error: ' . $e->getMessage());
            } else {
                exit('Database connection error. Please check the logs.');
            }
        }
    }

    /**
     * Get raw connection object (use with caution).
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Execute a prepared statement with type-safe binding.
     * 
     * @param string $sql SQL query with ? placeholders
     * @param array $params Array of parameters to bind
     * @param string $types Optional; if omitted, auto-detected from param types
     * @return mysqli_result|bool Query result or false on error
     */
    public function execute($sql, $params = [], $types = null) {
        try {
            $stmt = $this->connection->prepare($sql);
            
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $this->connection->error);
            }

            if (!empty($params)) {
                // Auto-detect types if not provided
                if ($types === null) {
                    $types = $this->getBindTypes($params);
                }

                // Bind parameters
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                throw new Exception('Execute failed: ' . $stmt->error);
            }

            if ($stmt->field_count > 0) {
                return $stmt->get_result();
            }

            return true;

        } catch (Exception $e) {
            $this->logError('Query execution failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get bind type string for parameters.
     * Returns 'iss' for [1, 'name', 'email'], etc.
     */
    public function getBindTypes($params) {
        $types = '';
        foreach ($params as $param) {
            if ($param === null) {
                $types .= 's'; // MySQLi sends NULL correctly when PHP null bound as 's'
            } elseif (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        return $types;
    }

    /**
     * Insert a record and return the inserted ID.
     * 
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return int|false Inserted ID or false on error
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";

        $result = $this->execute($sql, array_values($data));
        
        if ($result === false) {
            return false;
        }

        return $this->connection->insert_id;
    }

    /**
     * Update records by condition.
     * 
     * @param string $table Table name
     * @param array $data Columns to update
     * @param array $where Condition array: ['id' => 5, 'status' => 'active']
     * @return int|false Number of affected rows or false on error
     */
    public function update($table, $data, $where) {
        $setClauses = [];
        $params = [];

        // Build SET clause
        foreach ($data as $column => $value) {
            $setClauses[] = "$column = ?";
            $params[] = $value;
        }

        // Build WHERE clause
        $whereClauses = [];
        foreach ($where as $column => $value) {
            $whereClauses[] = "$column = ?";
            $params[] = $value;
        }

        $sql = "UPDATE $table SET " . implode(', ', $setClauses);
        $sql .= " WHERE " . implode(' AND ', $whereClauses);

        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            $this->logError('Update prepare failed', ['sql' => $sql, 'error' => $this->connection->error]);
            return false;
        }

        $types = $this->getBindTypes($params);
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            $this->logError('Update execute failed', ['sql' => $sql, 'error' => $stmt->error]);
            return false;
        }

        return $stmt->affected_rows;
    }

    /**
     * Delete records by condition.
     * 
     * @param string $table Table name
     * @param array $where Condition array: ['id' => 5]
     * @return int|false Number of affected rows or false on error
     */
    public function delete($table, $where) {
        $whereClauses = [];
        $params = [];

        foreach ($where as $column => $value) {
            $whereClauses[] = "$column = ?";
            $params[] = $value;
        }

        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $whereClauses);

        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            $this->logError('Delete prepare failed', ['sql' => $sql]);
            return false;
        }

        $types = $this->getBindTypes($params);
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            $this->logError('Delete execute failed', ['sql' => $sql]);
            return false;
        }

        return $stmt->affected_rows;
    }

    /**
     * Execute a raw query (use sparingly; prefer execute() for safety).
     */
    public function query($sql) {
        $result = $this->connection->query($sql);
        
        if (!$result) {
            $this->logError('Raw query failed', ['sql' => $sql, 'error' => $this->connection->error]);
            return false;
        }

        return $result;
    }

    /**
     * Get last insert ID.
     */
    public function lastInsertId() {
        return $this->connection->insert_id;
    }

    /**
     * Get number of affected rows from last operation.
     */
    public function affectedRows() {
        return $this->connection->affected_rows;
    }

    /**
     * Close database connection.
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    /**
     * Log database errors to file.
     */
    private function logError($message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf(
            "[%s] Database Error: %s\n%s\n---\n",
            $timestamp,
            $message,
            json_encode($context, JSON_PRETTY_PRINT)
        );

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Destructor: close connection on object destruction.
     */
    public function __destruct() {
        $this->close();
    }
}
