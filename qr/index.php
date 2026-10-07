<?php
/**
 * QR Code Generator for batch
 * GET /qr/?code=XXXX
 */

$batch_code = $_GET['code'] ?? '';

if (!$batch_code || strlen($batch_code) !== 16) {
    http_response_code(400);
    echo "Invalid batch code";
    exit;
}

$config = require __DIR__ . '/../config.php';
$db = require __DIR__ . '/../database.php';

// Verify batch exists
$stmt = $db->prepare("SELECT * FROM batches WHERE batch_code = ?");
$stmt->execute([$batch_code]);
$batch = $stmt->fetch();

if (!$batch) {
    http_response_code(404);
    echo "Batch not found";
    exit;
}

// Generate share URL
$share_url = rtrim($config['app']['url'], '/') . '/view/?code=' . $batch_code;

// Use external QR code API
$qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($share_url);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

echo file_get_contents($qr_api);
