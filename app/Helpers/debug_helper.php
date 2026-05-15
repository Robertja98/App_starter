<?php
/**
 * Debug & Logging Helper
 * 
 * Standards from lessons_learned:
 * - Log POST arrivals at the first line to diagnose silent form failures.
 * - If no log is created, POST is not reaching the file (wrong URL, JS hijack, or routing issue).
 * - If POST keys log but button name is missing, browser sent a cached form version.
 */

if (!function_exists('logPostArrival')) {
    /**
     * Log POST arrival for debugging.
     * Call this as the FIRST line in any POST handler.
     * 
     * @param string $handler Handler name for identifying context
     * @param array $extraData Optional additional data to log
     */
    function logPostArrival($handler, $extraData = []) {
        $logFile = __DIR__ . '/../../debug_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['REQUEST_URI'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $logEntry = sprintf(
            "[%s] %s %s | Handler: %s | IP: %s | POST keys: %s\n",
            $timestamp,
            $method,
            $path,
            $handler,
            $ip,
            implode(', ', array_keys($_POST))
        );
        
        if (!empty($extraData)) {
            $logEntry .= "  Extra: " . json_encode($extraData) . "\n";
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('logError')) {
    /**
     * Log application errors.
     * 
     * @param string $message Error message
     * @param array $context Optional context data
     * @param string $level 'error', 'warning', 'info'
     */
    function logError($message, $context = [], $level = 'error') {
        $logFile = __DIR__ . '/../../error_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_PRETTY_PRINT) : '';
        
        $logEntry = sprintf(
            "[%s] %s: %s\n%s\n---\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr
        );
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('debugDump')) {
    /**
     * Dump debug data and exit (development only).
     * Remove from production code.
     * 
     * @param mixed $data Data to dump
     * @param string $label Optional label
     */
    function debugDump($data, $label = 'DEBUG') {
        echo "<pre>";
        echo "=== $label ===\n";
        var_dump($data);
        echo "</pre>";
        exit;
    }
}

if (!function_exists('probeFile')) {
    /**
     * Probe which file is actually being served (diagnostics for wrong file issues).
     * Output __FILE__, __DIR__, $_SERVER['PHP_SELF'].
     * 
     * @param string $marker Optional marker to identify this probe in logs
     */
    function probeFile($marker = 'PROBE') {
        $output = [
            'marker' => $marker,
            'file' => __FILE__,
            'dir' => __DIR__,
            'php_self' => $_SERVER['PHP_SELF'] ?? 'N/A',
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'N/A',
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        header('Content-Type: application/json');
        echo json_encode($output, JSON_PRETTY_PRINT);
        exit;
    }
}
