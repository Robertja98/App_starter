<?php
/**
 * User Model
 */

class User extends Model {
    protected $table = 'users';
    protected $fillable = ['email', 'name', 'phone', 'role', 'is_active'];
    
    /**
     * Find user by email.
     */
    public function findByEmail($email) {
        return $this->findWhere(['email' => strtolower(trim($email))]);
    }

    /**
     * Verify password against stored hash.
     */
    public function verifyPassword($plainPassword, $hash) {
        return password_verify($plainPassword, $hash);
    }

    /**
     * Hash a password for storage.
     */
    public function hashPassword($plainPassword) {
        return password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Validate user data.
     */
    public function validate($data) {
        $errors = [];

        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            }
        }

        if (isset($data['password']) && strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if (isset($data['role'])) {
            $validRoles = ['technician', 'admin', 'manager'];
            if (!in_array($data['role'], $validRoles)) {
                $errors['role'] = 'Invalid role';
            }
        }

        return $errors;
    }

    /**
     * Get active users.
     */
    public function getActive() {
        return $this->where(['is_active' => 1]);
    }
}
