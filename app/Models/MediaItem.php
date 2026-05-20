<?php
/**
 * MediaItem Model
 */

class MediaItem extends Model {
    protected $table = 'media_items';
    protected $fillable = ['visit_id', 'equipment_id', 'media_type', 'original_filename', 'stored_filename', 'file_path', 'file_size', 'mime_type', 'is_uploaded', 'idempotency_key'];
    
    /**
     * Validate media item data.
     */
    public function validate($data) {
        $errors = [];

        if (!array_key_exists('visit_id', $data) || $data['visit_id'] === null || $data['visit_id'] === '') {
            $errors['visit_id'] = 'Visit ID is required';
        } elseif (!is_numeric($data['visit_id']) || $data['visit_id'] <= 0) {
            $errors['visit_id'] = 'Invalid visit ID';
        }

        if (isset($data['equipment_id']) && $data['equipment_id'] !== null && $data['equipment_id'] !== '') {
            if (!is_numeric($data['equipment_id']) || $data['equipment_id'] <= 0) {
                $errors['equipment_id'] = 'Invalid equipment ID';
            }
        }

        if (!array_key_exists('media_type', $data) || trim((string) $data['media_type']) === '') {
            $errors['media_type'] = 'Media type is required';
        } else {
            $validTypes = ['photo', 'video', 'document'];
            if (!in_array($data['media_type'], $validTypes, true)) {
                $errors['media_type'] = 'Invalid media type';
            }
        }

        if (!array_key_exists('stored_filename', $data) || trim((string) $data['stored_filename']) === '') {
            $errors['stored_filename'] = 'Stored filename is required';
        }

        if (isset($data['mime_type'])) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'application/pdf'];
            if (!in_array($data['mime_type'], $allowedMimes, true)) {
                $errors['mime_type'] = 'File type not allowed';
            }
        }

        if (isset($data['file_size'])) {
            $maxSize = 5 * 1024 * 1024; // 5 MB
            if ($data['file_size'] > $maxSize) {
                $errors['file_size'] = 'File size exceeds limit (5 MB)';
            }
        }

        return $errors;
    }

    /**
     * Get media items for a visit.
     */
    public function getByVisit($visitId) {
        return $this->where(['visit_id' => $visitId]);
    }

    /**
     * Get media for equipment in a visit.
     */
    public function getByEquipmentAndVisit($equipmentId, $visitId) {
        return $this->where(['equipment_id' => $equipmentId, 'visit_id' => $visitId]);
    }

    /**
     * Get unuploaded media (offline submissions waiting for sync).
     */
    public function getUnuploaded() {
        return $this->where(['is_uploaded' => 0], 50);
    }

    /**
     * Find a media item by idempotency key.
     */
    public function findByIdempotencyKey($idempotencyKey) {
        return $this->findWhere(['idempotency_key' => $idempotencyKey]);
    }
}
