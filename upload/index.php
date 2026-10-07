<?php
/**
 * ImgHost Upload Handler
 * POST /upload/ - Process file uploads
 */

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
$db = require __DIR__ . '/../database.php';
require __DIR__ . '/../ImageOptimizer.php';
require __DIR__ . '/../SessionManager.php';

$optimizer = new ImageOptimizer($config);
$sessionManager = SessionManager::getInstance();
$session_id = $sessionManager->getSessionId();

// Helper function for logging
function log_event($config, $message, $level = 'INFO') {
    if (!$config['log']['enabled']) return;
    
    $log_dir = dirname($config['log']['file']);
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_msg = "[$timestamp] [$level] $message\n";
    @file_put_contents($config['log']['file'], $log_msg, FILE_APPEND);
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate files
if (empty($_FILES['images']['name'][0])) {
    log_event($config, "Upload rejected: No files");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No files provided']);
    exit;
}

$files = $_FILES['images'];
$file_count = count(array_filter($files['name']));

if ($file_count > $config['upload']['max_files']) {
    log_event($config, "Upload rejected: Too many files ($file_count > {$config['upload']['max_files']})");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Maximum {$config['upload']['max_files']} files allowed"]);
    exit;
}

// Generate batch code (16 characters)
$batch_code = bin2hex(random_bytes(8));
$expires_at = date('Y-m-d H:i:s', strtotime('+' . $config['app']['expiry_days'] . ' days'));
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

try {
    $db->beginTransaction();

    // Create batch
    $stmt = $db->prepare("INSERT INTO batches (batch_code, expires_at, ip_address, session_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$batch_code, $expires_at, $ip_address, $session_id]);
    $batch_id = $db->lastInsertId();

    // Process each file
    for ($i = 0; $i < $file_count; $i++) {
        $tmp_name = $files['tmp_name'][$i];
        $original_name = $files['name'][$i];
        $size = $files['size'][$i];
        $error = $files['error'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            throw new Exception("Upload error for $original_name");
        }

        // Validate size
        if ($size > $config['upload']['max_size']) {
            throw new Exception("File $original_name exceeds maximum size");
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($tmp_name);

        if (!in_array($mime_type, $config['upload']['allowed_types'])) {
            throw new Exception("Invalid file type: $mime_type");
        }

        // Generate safe filename
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        if (empty($extension)) {
            $extension = explode('/', $mime_type)[1] ?? 'bin';
        }
        $new_filename = bin2hex(random_bytes(16)) . '.' . strtolower($extension);
        $local_path = $config['upload']['tmp_dir'] . $new_filename;

        // Save file
        if (!move_uploaded_file($tmp_name, $local_path)) {
            throw new Exception("Failed to save file: $original_name");
        }

        // Optimize image
        $original_size = filesize($local_path);
        if ($optimizer->optimize($local_path, $local_path, 85)) {
            $optimized_size = filesize($local_path);
            log_event($config, "Optimized {$new_filename}: {$original_size} → {$optimized_size} bytes");
        }

        // Insert into database
        $stmt = $db->prepare("
            INSERT INTO photos (batch_id, filename, local_path, status, expires_at, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$batch_id, $new_filename, $local_path, 'uploading', $expires_at, $ip_address]);

        log_event($config, "File uploaded: $new_filename (batch: $batch_code)");
    }

    $db->commit();

    // Generate share link
    $share_link = rtrim($config['app']['url'], '/') . '/view/?code=' . $batch_code;
    
    log_event($config, "Batch created: $batch_code (files: $file_count, ip: $ip_address)");

    // Start background worker to upload to Telegram
    if (function_exists('popen')) {
        pclose(popen('start /B php "' . __DIR__ . '/../worker.php"', 'r'));
        log_event($config, "Background worker started for batch: $batch_code");
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'link' => $share_link,
        'code' => $batch_code,
        'files' => $file_count
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    log_event($config, "Upload error: " . $e->getMessage(), 'ERROR');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

