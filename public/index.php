<?php
/**
 * Service App Router & Entrypoint
 * 
 * This file is the single entry point for all HTTP requests.
 * GoDaddy hosting: configure your domain to serve from /public directory.
 */

// Bootstrap: Load configuration and dependencies
require_once __DIR__ . '/../app/bootstrap.php';

// $config, $db, $auth are now available globally

// Error handling
if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../error_log.txt');
}

// Simple router
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip public from path if present
$path = preg_replace('~^/public~i', '', $path) ?: '/';

sendSecurityHeaders($config, strpos($path, '/api/') === 0);

// API routes
if (strpos($path, '/api/') === 0) {
    header('Content-Type: application/json');
    
    // Load controllers as needed
    
    // Auth endpoints
    if (strpos($path, '/api/auth') === 0) {
        require_once __DIR__ . '/../app/Controllers/AuthController.php';
        $controller = new AuthController($config, $db, $auth, $apiKeyAuth);
        
        if ($path === '/api/auth/csrf' && $method === 'GET') {
            $controller->csrf();
        } elseif ($path === '/api/auth/login' && $method === 'POST') {
            $controller->login();
        } elseif ($path === '/api/auth/logout' && $method === 'POST') {
            $controller->logout();
        } elseif ($path === '/api/auth/user' && $method === 'GET') {
            $controller->user();
        }
    }
    
    // Site endpoints
    if (strpos($path, '/api/sites') === 0) {
        require_once __DIR__ . '/../app/Controllers/SiteController.php';
        $controller = new SiteController($config, $db, $auth, $apiKeyAuth);
        
        // Extract ID from path if present
        $pathMatch = preg_match('~^/api/sites/(\d+)(?:/|$)~', $path, $matches);
        $id = $pathMatch ? $matches[1] : null;
        
        if ($path === '/api/sites' && $method === 'GET') {
            $controller->index();
        } elseif ($pathMatch && $method === 'GET') {
            $controller->show($id);
        } elseif ($path === '/api/sites' && $method === 'POST') {
            $controller->store();
        } elseif ($pathMatch && $method === 'PUT') {
            $controller->update($id);
        } elseif ($pathMatch && $method === 'DELETE') {
            $controller->destroy($id);
        }
    }
    
    // Equipment endpoints
    if (strpos($path, '/api/equipment') === 0) {
        require_once __DIR__ . '/../app/Controllers/EquipmentController.php';
        $controller = new EquipmentController($config, $db, $auth, $apiKeyAuth);
        
        // Extract ID from path if present
        $pathMatch = preg_match('~^/api/equipment/(\d+)(?:/|$)~', $path, $matches);
        $id = $pathMatch ? $matches[1] : null;
        
        if ($path === '/api/equipment' && $method === 'GET') {
            $controller->index();
        } elseif ($pathMatch && $method === 'GET') {
            $controller->show($id);
        } elseif ($path === '/api/equipment' && $method === 'POST') {
            $controller->store();
        } elseif ($pathMatch && $method === 'PUT') {
            $controller->update($id);
        } elseif ($pathMatch && $method === 'DELETE') {
            $controller->destroy($id);
        }
    }
    
    // Service Visit endpoints (core workflow)
    if (strpos($path, '/api/visits') === 0) {
        require_once __DIR__ . '/../app/Controllers/ServiceVisitController.php';
        $controller = new ServiceVisitController($config, $db, $auth, $apiKeyAuth);
        
        // Extract ID and sub-action from path
        $pathMatch = preg_match('~^/api/visits/(\d+)(?:/(complete|sync))?(?:/|$)~', $path, $matches);
        $id = $pathMatch ? $matches[1] : null;
        $action = $pathMatch && isset($matches[2]) ? $matches[2] : null;
        
        if ($path === '/api/visits' && $method === 'GET') {
            $controller->index();
        } elseif ($pathMatch && !$action && $method === 'GET') {
            $controller->show($id);
        } elseif ($path === '/api/visits' && $method === 'POST') {
            $controller->store();
        } elseif ($pathMatch && !$action && $method === 'PUT') {
            $controller->update($id);
        } elseif ($action === 'complete' && $method === 'POST') {
            $controller->complete($id);
        }
    }
    
    // Measurement endpoints
    if (strpos($path, '/api/measurements') === 0) {
        require_once __DIR__ . '/../app/Controllers/MeasurementController.php';
        $controller = new MeasurementController($config, $db, $auth, $apiKeyAuth);
        
        $pathMatch = preg_match('~^/api/measurements/(\d+)(?:/|$)~', $path, $matches);
        $id = $pathMatch ? $matches[1] : null;
        
        if ($path === '/api/measurements' && $method === 'GET') {
            $controller->index();
        } elseif ($pathMatch && $method === 'GET') {
            $controller->show($id);
        } elseif ($path === '/api/measurements' && $method === 'POST') {
            $controller->store();
        }
    }
    
    // Consumable endpoints
    if (strpos($path, '/api/consumables') === 0) {
        require_once __DIR__ . '/../app/Controllers/ConsumableController.php';
        $controller = new ConsumableController($config, $db, $auth, $apiKeyAuth);
        
        $pathMatch = preg_match('~^/api/consumables/(\d+)(?:/|$)~', $path, $matches);
        $id = $pathMatch ? $matches[1] : null;
        
        if ($path === '/api/consumables' && $method === 'GET') {
            $controller->index();
        } elseif ($pathMatch && $method === 'GET') {
            $controller->show($id);
        } elseif ($path === '/api/consumables' && $method === 'POST') {
            $controller->store();
        }
    }
    
    // Repair endpoints
    if (strpos($path, '/api/repairs') === 0) {
        require_once __DIR__ . '/../app/Controllers/RepairController.php';
        $controller = new RepairController($config, $db, $auth, $apiKeyAuth);
        
        $pathMatch = preg_match('~^/api/repairs/(\d+)(?:/|$)~', $path, $matches);
        $id = $pathMatch ? $matches[1] : null;
        
        if ($path === '/api/repairs' && $method === 'GET') {
            $controller->index();
        } elseif ($pathMatch && $method === 'GET') {
            $controller->show($id);
        } elseif ($path === '/api/repairs' && $method === 'POST') {
            $controller->store();
        } elseif ($pathMatch && $method === 'PUT') {
            $controller->update($id);
        } elseif ($pathMatch && $method === 'DELETE') {
            $controller->destroy($id);
        }
    }
    
    // Media endpoints
    if (strpos($path, '/api/media') === 0) {
        require_once __DIR__ . '/../app/Controllers/MediaController.php';
        $controller = new MediaController($config, $db, $auth, $apiKeyAuth);
        
        $pathMatch = preg_match('~^/api/media/(\d+)(?:/|$)~', $path, $matches);
        $id = $pathMatch ? $matches[1] : null;
        
        if ($path === '/api/media/upload' && $method === 'POST') {
            $controller->upload();
        } elseif ($pathMatch && $method === 'GET') {
            $controller->show($id);
        } elseif ($pathMatch && $method === 'DELETE') {
            $controller->destroy($id);
        }
    }
    
    // Sync endpoint (offline queue)
    if (strpos($path, '/api/sync') === 0) {
        require_once __DIR__ . '/../app/Controllers/SyncController.php';
        $controller = new SyncController($config, $db, $auth, $apiKeyAuth);
        
        if ($path === '/api/sync' && $method === 'POST') {
            $controller->sync();
        }
    }
    
    // If no route matched, return 404
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Endpoint not found']);
    exit;
}

// Web routes
if ($path === '/') {
    // Serve the PWA shell
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/app.html';
    exit;
}

// Static assets
if (preg_match('~\.(js|css|json|png|jpg|jpeg|gif|svg|woff2|woff|ttf)$~i', $path)) {
    $file = realpath(__DIR__ . $path);
    if ($file && strpos($file, __DIR__) === 0 && file_exists($file)) {
        // Set cache headers for static assets
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($file);
        exit;
    }
}

// 404
http_response_code(404);
echo "Not Found";
