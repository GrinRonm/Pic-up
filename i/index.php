<?php
/**
 * ImgHost Image Proxy
 * GET /i/?f=filename
 * Serves images from local storage or Telegram
 */

$config = require __DIR__ . '/../config.php';
$db = require __DIR__ . '/../database.php';

$filename = $_GET['f'] ?? '';
$is_thumb = isset($_GET['t']);

// Validate filename
if (empty($filename) || strlen($filename) > 100 || !preg_match('/^[a-f0-9]+\.[a-z]+$/', $filename)) {
    http_response_code(400);
    die('Invalid filename');
}

// Get photo from database
$stmt = $db->prepare("SELECT * FROM photos WHERE filename = ?");
$stmt->execute([$filename]);
$photo = $stmt->fetch();

if (!$photo) {
    http_response_code(404);
    die('File not found');
}

$file_to_serve = '';
$status = $photo['status'] ?? 'uploading';

// 1. Try local file if status is "uploading"
if ($status === 'uploading' && file_exists($photo['local_path'])) {
    $file_to_serve = $photo['local_path'];
}

// 2. Try cache if status is "stored"
elseif ($status === 'stored') {
    $cache_path = $config['upload']['cache_dir'] . $filename;
    
    if (file_exists($cache_path) && filesize($cache_path) > 0) {
        $file_to_serve = $cache_path;
    } else {
        // Download from Telegram
        if (empty($photo['telegram_file_id'])) {
            http_response_code(404);
            die('File not stored');
        }

        $file_to_serve = download_from_telegram($config, $photo, $cache_path);
    }
}

// 3. Handle Thumbnail request
if ($is_thumb && $file_to_serve && file_exists($file_to_serve)) {
    $thumb_dir = $config['upload']['cache_dir'] . 'thumbs/';
    if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);
    
    $thumb_path = $thumb_dir . $filename;
    
    // Create thumbnail if doesn't exist
    if (!file_exists($thumb_path)) {
        require_once __DIR__ . '/../ImageOptimizer.php';
        $optimizer = new ImageOptimizer($config);
        $optimizer->resize($file_to_serve, $thumb_path, 800, 800, 80);
    }
    
    if (file_exists($thumb_path)) {
        $file_to_serve = $thumb_path;
    }
}

// Serve the file
if ($file_to_serve && file_exists($file_to_serve) && filesize($file_to_serve) > 0) {
    // Set cache headers
    $mtime = filemtime($file_to_serve);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s T', $mtime));
    header('Cache-Control: public, max-age=86400'); // 24 hours cache
    
    // Set content type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file_to_serve);
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file_to_serve));
    
    // Handle HEAD request
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }
    
    // Send file
    readfile($file_to_serve);
} else {
    http_response_code(404);
    die('File could not be retrieved');
}

/**
 * Download file from Telegram
 */
function download_from_telegram($config, $photo, $cache_path) {
    $file_id = $photo['telegram_file_id'];
    $bot_token = $config['telegram']['bot_token'];
    
    try {
        // Get file info from Telegram
        $get_file_url = "https://api.telegram.org/bot{$bot_token}/getFile?file_id={$file_id}";
        $response = @file_get_contents($get_file_url);
        
        if (!$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !$data['ok']) {
            return null;
        }
        
        // Download file
        $tg_file_path = $data['result']['file_path'];
        $download_url = "https://api.telegram.org/file/bot{$bot_token}/{$tg_file_path}";
        
        $ch = curl_init($download_url);
        if (!is_dir(dirname($cache_path))) {
            mkdir(dirname($cache_path), 0755, true);
        }
        
        $fp = fopen($cache_path, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        
        if (file_exists($cache_path) && filesize($cache_path) > 0) {
            return $cache_path;
        }
    } catch (Exception $e) {
        return null;
    }
    
    return null;
}

