<?php
/**
 * ImgHost Background Worker
 * Run: php worker.php
 * Or cron: * * * * * cd /var/www/pic-up.ae0.ru && php worker.php
 */

set_time_limit(0);

$config = require __DIR__ . '/config.php';
$db = require __DIR__ . '/database.php';

function log_worker($config, $message) {
    if (!$config['log']['enabled']) return;
    $timestamp = date('Y-m-d H:i:s');
    $log_msg = "[$timestamp] [WORKER] $message\n";
    file_put_contents($config['log']['file'], $log_msg, FILE_APPEND);
    echo $log_msg;
}

log_worker($config, "🚀 Worker started");

try {
    // Step 1: Upload files to Telegram
    log_worker($config, "📤 Processing uploads to Telegram...");
    
    $stmt = $db->prepare("SELECT * FROM photos WHERE status = 'uploading' LIMIT 10");
    $stmt->execute();
    $photos_to_upload = $stmt->fetchAll();
    
    $uploaded_count = 0;
    foreach ($photos_to_upload as $photo) {
        if (!file_exists($photo['local_path'])) {
            log_worker($config, "⚠️  File not found: {$photo['filename']}, deleting record");
            $db->prepare("DELETE FROM photos WHERE id = ?")->execute([$photo['id']]);
            continue;
        }

        // Send to Telegram
        $bot_token = $config['telegram']['bot_token'];
        $chat_id = $config['telegram']['chat_id'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$bot_token}/sendDocument");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'chat_id' => $chat_id,
            'document' => new CURLFile($photo['local_path']),
            'caption' => "Batch: {$photo['batch_id']}"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            log_worker($config, "❌ CURL error for {$photo['filename']}: $error");
            continue;
        }

        $data = json_decode($response, true);
        if ($data && $data['ok']) {
            $file_id = $data['result']['document']['file_id'];
            
            // Update database
            $stmt = $db->prepare("UPDATE photos SET telegram_file_id = ?, status = 'stored' WHERE id = ?");
            $stmt->execute([$file_id, $photo['id']]);

            // Remove local file
            if (unlink($photo['local_path'])) {
                log_worker($config, "✅ Uploaded: {$photo['filename']}");
                $uploaded_count++;
            } else {
                log_worker($config, "⚠️  Uploaded but couldn't delete: {$photo['filename']}");
            }
        } else {
            $error_msg = $data['description'] ?? 'Unknown error';
            log_worker($config, "❌ Telegram error: $error_msg");
        }
    }
    
    log_worker($config, "📤 Uploaded $uploaded_count files to Telegram");

    // Step 2: Cleanup expired files and cache
    log_worker($config, "🧹 Cleaning up expired files and cache...");
    
    // 2.1 Cleanup by database (expired records)
    $stmt = $db->prepare("SELECT * FROM photos WHERE expires_at < CURRENT_TIMESTAMP");
    $stmt->execute();
    $expired_photos = $stmt->fetchAll();
    
    $deleted_count = 0;
    foreach ($expired_photos as $photo) {
        // Delete local file from tmp
        if ($photo['local_path'] && file_exists($photo['local_path'])) {
            unlink($photo['local_path']);
            $deleted_count++;
        }

        // Delete from cache
        $cache_path = $config['upload']['cache_dir'] . $photo['filename'];
        if (file_exists($cache_path)) {
            unlink($cache_path);
        }
    }

    // 2.2 Global cache cleanup (clear all files on each run)
    $cache_dir = $config['upload']['cache_dir'];
    $cache_files_deleted = 0;

    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                if (unlink($file)) {
                    $cache_files_deleted++;
                }
            }
        }
    }
    log_worker($config, "🧹 Cleared cache folder: deleted $cache_files_deleted files");

    // 2.3 Delete from database
    $db->prepare("UPDATE photos SET status = 'unavailable' WHERE expires_at < CURRENT_TIMESTAMP AND status != 'unavailable'")->execute();
    
    log_worker($config, "🧹 Cleaned up $deleted_count expired files from temporary local storage. Database records marked as unavailable.");

    log_worker($config, "✅ Worker completed successfully");

} catch (Exception $e) {
    log_worker($config, "❌ ERROR: " . $e->getMessage());
    exit(1);
}

exit(0);

