<?php
/**
 * Download batch as ZIP
 * GET /download/?code=XXXX
 */

$batch_code = $_GET['code'] ?? '';

if (!$batch_code || strlen($batch_code) !== 16) {
    http_response_code(400);
    echo "Invalid batch code";
    exit;
}

$config = require __DIR__ . '/../config.php';
$db = require __DIR__ . '/../database.php';

// Get batch info
$stmt = $db->prepare("SELECT * FROM batches WHERE batch_code = ?");
$stmt->execute([$batch_code]);
$batch = $stmt->fetch();

if (!$batch) {
    http_response_code(404);
    echo "Batch not found";
    exit;
}

// Get photos
$stmt = $db->prepare("SELECT * FROM photos WHERE batch_id = ? ORDER BY created_at");
$stmt->execute([$batch['id']]);
$photos = $stmt->fetchAll();

if (empty($photos)) {
    http_response_code(404);
    echo "No photos in batch";
    exit;
}

// Create ZIP archive
$zip_name = "batch_{$batch_code}_" . date('YmdHis') . '.zip';
$temp_file = sys_get_temp_dir() . '/' . $zip_name;

$zip = new ZipArchive();
if ($zip->open($temp_file, ZipArchive::CREATE) !== true) {
    http_response_code(500);
    echo "Failed to create archive";
    exit;
}

$file_count = 0;
foreach ($photos as $photo) {
    $file_path = '';
    
    if ($photo['status'] === 'uploading' && file_exists($photo['local_path'])) {
        $file_path = $photo['local_path'];
    } elseif ($photo['status'] === 'stored') {
        // Download from Telegram cache
        $cache_path = $config['upload']['cache_dir'] . $photo['filename'];
        if (!file_exists($cache_path) && $photo['telegram_file_id']) {
            // Download from Telegram
            $bot_token = $config['telegram']['bot_token'];
            $get_file_url = "https://api.telegram.org/bot{$bot_token}/getFile?file_id=" . $photo['telegram_file_id'];
            $response = file_get_contents($get_file_url);
            $data = json_decode($response, true);
            
            if ($data && $data['ok']) {
                $tg_file_path = $data['result']['file_path'];
                $download_url = "https://api.telegram.org/file/bot{$bot_token}/{$tg_file_path}";
                
                $ch = curl_init($download_url);
                $fp = fopen($cache_path, 'wb');
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_exec($ch);
                curl_close($ch);
                fclose($fp);
            }
        }
        if (file_exists($cache_path)) {
            $file_path = $cache_path;
        }
    }
    
    if ($file_path && file_exists($file_path)) {
        $zip->addFile($file_path, $photo['filename']);
        $file_count++;
    }
}

$zip->close();

if ($file_count === 0) {
    http_response_code(500);
    echo "No files could be added to archive";
    @unlink($temp_file);
    exit;
}

// Send file to browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_name . '"');
header('Content-Length: ' . filesize($temp_file));
readfile($temp_file);

// Cleanup
@unlink($temp_file);
