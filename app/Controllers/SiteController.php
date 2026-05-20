<?php
/**
 * Site Endpoints
 * 
 * - GET /api/sites – List all sites (with optional filtering)
 * - GET /api/sites/{id} – Get single site details
 * - POST /api/sites – Create site (admin only)
 * - PUT /api/sites/{id} – Update site
 * - DELETE /api/sites/{id} – Delete site
 */

require_once __DIR__ . '/../Models/Site.php';
require_once __DIR__ . '/../Models/Customer.php';
require_once __DIR__ . '/../Models/ServiceVisit.php';

class SiteController extends Controller {

    /**
     * GET /api/sites/{id}/history
     * Return aggregate history metrics and recent visits for a site.
     */
    public function history($siteId) {
        $this->requireAuth();

        if (!is_numeric($siteId)) {
            $this->badRequest('Invalid site ID');
        }

        $siteModel = new Site($this->db);
        $visitModel = new ServiceVisit($this->db);
        $limit = (int)($this->getQuery('limit') ?: 10);
        if ($limit < 1) {
            $limit = 10;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        try {
            $site = $siteModel->find($siteId);
            if (!$site) {
                $this->error('Site not found', 404);
            }

            $metrics = $visitModel->getSiteHistoryMetrics((int)$siteId);
            $recentVisits = $visitModel->getRecentBySite((int)$siteId, $limit);

            $this->success([
                'site' => [
                    'id' => (int)$site['id'],
                    'site_name' => $site['site_name'],
                    'customer_id' => (int)$site['customer_id'],
                ],
                'metrics' => $metrics,
                'recent_visits' => $recentVisits ?: [],
                'limit' => $limit,
            ], 200);
        } catch (Exception $e) {
            $this->logError('SiteController::history', $e->getMessage());
            $this->internalError('Failed to fetch site history');
        }
    }

    /**
     * GET /api/sites
     * List sites (optionally filtered by customer_id)
     * 
     * Query Parameters:
     *   ?customer_id=X – Filter sites by customer
     *   ?limit=20&offset=0 – Pagination
     * 
     * Response:
     *   {"status": "success", "data": {"sites": [...], "total": N}}
     */
    public function index() {
        $this->requireAuth();
        
        $model = new Site($this->db);
        $customerId = $this->getQuery('customer_id');
        $limit = (int)($this->getQuery('limit') ?: 20);
        $offset = (int)($this->getQuery('offset') ?: 0);

        try {
            if ($customerId) {
                // Filter by customer
                $sites = $model->getByCustomer($customerId, $limit, $offset);
                $totalStmt = $this->db->execute(
                    "SELECT COUNT(*) as cnt FROM sites WHERE customer_id = ? AND is_active = 1",
                    [$customerId],
                    'i'
                );
                $total = $totalStmt->fetch_assoc()['cnt'] ?? 0;
            } else {
                // List all active sites with customer info
                $sites = $model->getActiveWithCustomer($limit, $offset);
                $totalStmt = $this->db->execute(
                    "SELECT COUNT(*) as cnt FROM sites WHERE is_active = 1"
                );
                $total = $totalStmt->fetch_assoc()['cnt'] ?? 0;
            }

            $this->success([
                'sites' => $sites ?: [],
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
            ], 200);
        } catch (Exception $e) {
            $this->logError('SiteController::index', $e->getMessage());
            $this->internalError('Failed to fetch sites');
        }
    }

    /**
     * GET /api/sites/{id}
     * Get single site with equipment count
     */
    public function show($siteId) {
        $this->requireAuth();
        
        if (!is_numeric($siteId)) {
            $this->badRequest('Invalid site ID');
        }

        $model = new Site($this->db);
        try {
            $site = $model->find($siteId);
            if (!$site) {
                $this->error('Site not found', 404);
            }

            // Add equipment count
            $equipmentStmt = $this->db->execute(
                "SELECT COUNT(*) as cnt FROM equipment WHERE site_id = ? AND is_active = 1",
                [$siteId],
                'i'
            );
            $equipmentCount = $equipmentStmt->fetch_assoc()['cnt'] ?? 0;
            
            $site['equipment_count'] = (int)$equipmentCount;

            $this->success($site, 200);
        } catch (Exception $e) {
            $this->logError('SiteController::show', $e->getMessage());
            $this->internalError('Failed to fetch site');
        }
    }

    /**
     * POST /api/sites
     * Create new site
     * 
     * Request Body:
    *   {"customer_id": X, "site_name": "Site Name", "address_line1": "...", "city": "...", ...}
     */
    public function store() {
        $this->requireAuth();
        $this->requireCsrf();

        $model = new Site($this->db);
        
        $data = [
            'customer_id' => $this->getPost('customer_id'),
            'site_name' => $this->getPost('site_name', $this->getPost('name')),
            'address_line1' => $this->getPost('address_line1', $this->getPost('address')),
            'address_line2' => $this->getPost('address_line2'),
            'city' => $this->getPost('city'),
            'province' => $this->getPost('province'),
            'postal_code' => $this->getPost('postal_code'),
            'contact_phone' => $this->getPost('contact_phone', $this->getPost('phone')),
            'contact_person' => $this->getPost('contact_person'),
            'notes' => $this->getPost('notes'),
        ];

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $id = $model->insert($data);
            if (!$id) {
                $this->internalError('Failed to create site');
            }
            
            $site = $model->find($id);
            $this->auditAction('insert', 'site', $id, null, $site, ['controller' => static::class]);
            $this->success($site, 201);
        } catch (Exception $e) {
            $this->logError('SiteController::store', $e->getMessage());
            $this->internalError('Failed to create site');
        }
    }

    /**
     * PUT /api/sites/{id}
     * Update site
     */
    public function update($siteId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($siteId)) {
            $this->badRequest('Invalid site ID');
        }

        $model = new Site($this->db);
        
        // Verify site exists
        $existing = $model->find($siteId);
        if (!$existing) {
            $this->error('Site not found', 404);
        }

        // Merge existing data with new data (only update provided fields)
        $data = array_merge($existing, [
            'site_name' => $this->getPost('site_name', $this->getPost('name', $existing['site_name'])),
            'address_line1' => $this->getPost('address_line1', $this->getPost('address', $existing['address_line1'])),
            'address_line2' => $this->getPost('address_line2', $existing['address_line2']),
            'city' => $this->getPost('city', $existing['city']),
            'province' => $this->getPost('province', $existing['province']),
            'postal_code' => $this->getPost('postal_code', $existing['postal_code']),
            'contact_phone' => $this->getPost('contact_phone', $this->getPost('phone', $existing['contact_phone'])),
            'contact_person' => $this->getPost('contact_person', $existing['contact_person']),
            'notes' => $this->getPost('notes', $existing['notes']),
        ]);

        $errors = $model->validate($data);
        if (!empty($errors)) {
            $this->unprocessable('Validation failed', $errors);
        }

        try {
            $updated = $model->updateById($siteId, $data);
            if (!$updated) {
                $this->internalError('Failed to update site');
            }

            $site = $model->find($siteId);
            $this->auditAction('update', 'site', $siteId, $existing, $site, ['controller' => static::class]);
            $this->success($site, 200);
        } catch (Exception $e) {
            $this->logError('SiteController::update', $e->getMessage());
            $this->internalError('Failed to update site');
        }
    }

    /**
     * DELETE /api/sites/{id}
     * Soft-delete site
     */
    public function destroy($siteId) {
        $this->requireAuth();
        $this->requireCsrf();

        if (!is_numeric($siteId)) {
            $this->badRequest('Invalid site ID');
        }

        $model = new Site($this->db);
        
        // Verify site exists
        if (!$model->find($siteId)) {
            $this->error('Site not found', 404);
        }

        try {
            $deleted = $model->updateById($siteId, ['is_active' => 0]);
            if (!$deleted) {
                $this->internalError('Failed to delete site');
            }

            $this->auditAction('delete', 'site', $siteId, ['is_active' => 1], ['is_active' => 0], ['controller' => static::class]);

            $this->success(['id' => $siteId, 'deleted' => true], 200);
        } catch (Exception $e) {
            $this->logError('SiteController::destroy', $e->getMessage());
            $this->internalError('Failed to delete site');
        }
    }
}
