<?php
/**
 * Media Upload Endpoints
 * 
 * - POST /api/media/upload – Upload photo/video/document
 * - GET /api/media/{id} – Get media metadata
 * - DELETE /api/media/{id} – Delete media
 */

require_once __DIR__ . '/../Models/MediaItem.php';

class MediaController extends Controller {

    /**
     * POST /api/media/upload
     * Upload media file attached to a service visit
     * 
     * Expects multipart/form-data:
     *   - service_visit_id (required, int)
     *   - equipment_id (optional, int)
     *   - file (required, multipart file, max 5MB)
     *   - media_type (optional, auto-detected: photo, video, document)
     *   - notes (optional, string)
     * 
     * Response:
     *   {"status": "success", "data": {"id": X, "file_path": "...", "url": "..."}}
     */
    public function upload() {
        $this->requireAuth();
        $this->requireCsrf();

        $this->logPostArrival('MediaController::upload', [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
            'files' => count($_FILES),
        ]);

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->badRequest('No file uploaded or upload error');
        }

        $file = $_FILES['file'];
        $config = $GLOBALS['config'];

        // Validate file size
        if ($file['size'] > $config['upload']['max_size']) {
            $this->error('File too large (max ' . ($config['upload']['max_size'] / 1024 / 1024) . 'MB)', 413);
        }

        // Get MIME type with a fallback for environments where mime_content_type() is unavailable.
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        } elseif (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mimeMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'pdf' => 'application/pdf',
                'mp4' => 'video/mp4',
            ];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        }
        
        // Determine media type
        if (strpos($mime, 'image/') === 0) {
            $mediaType = 'photo';
        } elseif (strpos($mime, 'video/') === 0) {
            $mediaType = 'video';
        } else {
            $mediaType = 'document';
        }

        // Generate unique filename
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $uploadPath = $config['upload']['storage_path'];
        
        // Ensure storage directory exists
        if (!is_dir($uploadPath)) {
            @mkdir($uploadPath, 0755, true);
        }

        $filePath = $uploadPath . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $this->internalError('Failed to save uploaded file');
        }

        // Create media record
        $model = new MediaItem($this->db);
        
        $data = [
            'visit_id' => $this->getPost('visit_id', $this->getPost('service_visit_id')),
            'equipment_id' => $this->getPost('equipment_id'),
            'media_type' => $mediaType,
            'original_filename' => $file['name'],
            'stored_filename' => $filename,
            'file_path' => $filePath,
            'file_size' => $file['size'],
            'mime_type' => $mime,
            'is_uploaded' => 1,
        ];

        $errors = $model->validate($data);
        if (!empty($errors)) {
            @unlink($filePath);  // Clean up uploaded file
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $id = $model->insert($data);
            if (!$id) {
                @unlink($filePath);
                $this->internalError('Failed to save media record');
            }

            $media = $model->find($id);
            $this->auditAction('insert', 'media_item', $id, null, $media, ['controller' => static::class]);
            $this->success($media, 201);
        } catch (Exception $e) {
            @unlink($filePath);
            $this->logError('MediaController::upload', $e->getMessage());
            $this->internalError('Failed to process upload');
        }
    }

    /**
     * GET /api/media/{id}
     * Get media metadata
     */
    public function show($mediaId) {
        $this->requireAuth();
        
        if (!is_numeric($mediaId)) {
            $this->badRequest('Invalid media ID');
        }

        $model = new MediaItem($this->db);
        try {
            $media = $model->find($mediaId);
            if (!$media) {
                $this->error('Media not found', 404);
            }
            $this->success($media, 200);
        } catch (Exception $e) {
            $this->logError('MediaController::show', $e->getMessage());
            $this->internalError('Failed to fetch media');
        }
    }

    /**
     * DELETE /api/media/{id}
     * Delete media file and record
     */
    public function destroy($mediaId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($mediaId)) {
            $this->badRequest('Invalid media ID');
        }

        $model = new MediaItem($this->db);
        
        try {
            $media = $model->find($mediaId);
            if (!$media) {
                $this->error('Media not found', 404);
            }

            // Delete physical file
            if (!empty($media['file_path']) && file_exists($media['file_path'])) {
                @unlink($media['file_path']);
            }

            // Delete record after removing the physical file.
            $deleted = $model->deleteById($mediaId);
            if (!$deleted) {
                $this->internalError('Failed to delete media');
            }

            $this->auditAction('delete', 'media_item', $mediaId, $media, ['deleted' => true], ['controller' => static::class]);
            $this->success(['id' => $mediaId, 'deleted' => true], 200);
        } catch (Exception $e) {
            $this->logError('MediaController::destroy', $e->getMessage());
            $this->internalError('Failed to delete media');
        }
    }
}
