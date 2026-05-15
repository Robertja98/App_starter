<?php
/**
 * Validation & Database Helper
 * 
 * Standards from lessons_learned:
 * - Type mismatches in bind_param: use 'i' for INT, 'd' for DECIMAL, 's' for string.
 * - DECIMAL/FLOAT columns reject empty string '' — convert to null.
 * - Bind null values as 's' type (MySQLi correctly sends SQL NULL when PHP null is bound as 's').
 */

if (!function_exists('sanitizeEmail')) {
    /**
     * Validate and sanitize email address.
     */
    function sanitizeEmail($email) {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return strtolower($email);
    }
}

if (!function_exists('sanitizePhoneNumber')) {
    /**
     * Basic phone number validation (North American format or flexible).
     */
    function sanitizePhoneNumber($phone) {
        $phone = preg_replace('/[^0-9\-\+\(\) ]/', '', $phone);
        $phone = trim($phone);
        
        if (strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
            return null;
        }
        
        return $phone;
    }
}

if (!function_exists('sanitizeInt')) {
    /**
     * Sanitize integer value.
     * Returns null if not a valid integer.
     */
    function sanitizeInt($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        $value = trim($value);
        if (!is_numeric($value) || strpos($value, '.') !== false) {
            return null;
        }
        
        return (int) $value;
    }
}

if (!function_exists('sanitizeDecimal')) {
    /**
     * Sanitize decimal/float value.
     * Returns null if empty (DECIMAL columns reject empty string).
     * Bind result as 's' type in MySQLi to preserve precision.
     */
    function sanitizeDecimal($value, $precision = 2) {
        if ($value === null || trim($value) === '') {
            return null;
        }
        
        $value = trim($value);
        if (!is_numeric($value)) {
            return null;
        }
        
        return number_format((float) $value, $precision, '.', '');
    }
}

if (!function_exists('getBindTypes')) {
    /**
     * Get MySQLi bind types for an array of values.
     * 
     * Usage:
     *   $values = [1, 'john', 19.99, null];
     *   $types = getBindTypes($values);
     *   $stmt->bind_param($types, ...$values);
     * 
     * Types:
     *   'i' = int
     *   's' = string (also for null values; MySQLi will send SQL NULL)
     *   'd' = float/decimal (not recommended; use 's' with precision formatting instead)
     */
    function getBindTypes($values) {
        $types = '';
        foreach ($values as $value) {
            if ($value === null) {
                $types .= 's'; // MySQLi sends NULL correctly when PHP null bound as 's'
            } elseif (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's'; // String, DECIMAL (formatted), etc.
            }
        }
        return $types;
    }
}

if (!function_exists('validateAndPrepare')) {
    /**
     * Validate form data and prepare for database insert/update.
     * 
     * Usage:
     *   $schema = [
     *       'email' => ['type' => 'email', 'required' => true],
     *       'phone' => ['type' => 'phone', 'required' => false],
     *       'quantity' => ['type' => 'int', 'required' => true],
     *       'price' => ['type' => 'decimal', 'required' => false, 'precision' => 2],
     *   ];
     *   [$validated, $errors] = validateAndPrepare($_POST, $schema);
     *   if (!empty($errors)) return errorResponse($errors);
     */
    function validateAndPrepare($data, $schema) {
        $validated = [];
        $errors = [];
        
        foreach ($schema as $field => $rules) {
            $value = $data[$field] ?? null;
            $type = $rules['type'] ?? 'string';
            $required = $rules['required'] ?? false;
            
            // Check required
            if ($required && ($value === null || trim($value) === '')) {
                $errors[$field] = "$field is required";
                continue;
            }
            
            // Skip optional empty values
            if (!$required && ($value === null || trim($value) === '')) {
                $validated[$field] = null;
                continue;
            }
            
            // Validate and sanitize by type
            switch ($type) {
                case 'email':
                    $value = sanitizeEmail($value);
                    if ($value === null) {
                        $errors[$field] = "Invalid email format";
                    } else {
                        $validated[$field] = $value;
                    }
                    break;
                    
                case 'phone':
                    $value = sanitizePhoneNumber($value);
                    if ($value === null) {
                        $errors[$field] = "Invalid phone number";
                    } else {
                        $validated[$field] = $value;
                    }
                    break;
                    
                case 'int':
                    $value = sanitizeInt($value);
                    if ($value === null && !$required) {
                        $validated[$field] = null;
                    } elseif ($value === null) {
                        $errors[$field] = "$field must be an integer";
                    } else {
                        $validated[$field] = $value;
                    }
                    break;
                    
                case 'decimal':
                    $precision = $rules['precision'] ?? 2;
                    $value = sanitizeDecimal($value, $precision);
                    if ($value === null && !$required) {
                        $validated[$field] = null;
                    } elseif ($value === null) {
                        $errors[$field] = "$field must be a number";
                    } else {
                        $validated[$field] = $value;
                    }
                    break;
                    
                default: // 'string'
                    $validated[$field] = trim($value);
            }
        }
        
        return [$validated, $errors];
    }
}
