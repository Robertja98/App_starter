<?php
/**
 * API key model for machine-to-machine or device integrations.
 */

class ApiKey extends Model {
    protected $table = 'api_keys';
    protected $fillable = ['name', 'key_prefix', 'key_hash', 'scopes', 'is_active', 'last_used_at', 'expires_at', 'created_by_user_id'];

    public function findActiveByHash($keyHash) {
        $sql = "SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1";
        $result = $this->db->execute($sql, [$keyHash], 's');
        return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    public function touchUsage($id) {
        return $this->updateById($id, ['last_used_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function createKeyRecord($name, $plainTextKey, array $scopes, $createdByUserId = null, $expiresAt = null) {
        $config = $this->db->getConnection() ? $GLOBALS['config'] : null;
        if (!$config) {
            throw new RuntimeException('Global config unavailable for API key hashing');
        }

        $keyPrefix = substr($plainTextKey, 0, 15);
        $keyHash = hashApiKeyValue($plainTextKey, $config);

        return $this->insert([
            'name' => $name,
            'key_prefix' => $keyPrefix,
            'key_hash' => $keyHash,
            'scopes' => json_encode(array_values($scopes)),
            'is_active' => 1,
            'expires_at' => $expiresAt,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function getScopes(array $row) {
        $decoded = json_decode($row['scopes'] ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }
}