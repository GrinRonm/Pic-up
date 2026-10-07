<?php
/**
 * Batch Info API
 * GET /api/?code=XXXX
 */

header('Content-Type: application/json; charset=utf-8');

$batch_code = $_GET['code'] ?? '';

if (!$batch_code || strlen($batch_code) !== 16) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid batch code']);
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
    echo json_encode(['error' => 'Batch not found']);
    exit;
}

// Get photos
$stmt = $db->prepare("SELECT id, filename, status, created_at FROM photos WHERE batch_id = ? AND status != 'unavailable' ORDER BY created_at");
$stmt->execute([$batch['id']]);
$photos = $stmt->fetchAll();

// Calculate stats
$total_photos = count($photos);
$stored_photos = count(array_filter($photos, fn($p) => $p['status'] === 'stored'));
$uploading_photos = $total_photos - $stored_photos;
$total_size = 0;
foreach ($photos as $photo) {
    if ($photo['status'] === 'uploading' && file_exists($config['upload']['tmp_dir'] . $photo['filename'])) {
        $total_size += filesize($config['upload']['tmp_dir'] . $photo['filename']);
    }
}

$response = [
    'success' => true,
    'batch' => [
        'code' => $batch['batch_code'],
        'created_at' => $batch['created_at'],
        'expires_at' => $batch['expires_at'],
        // IP address removed for security
    ],
    'stats' => [
        'total_photos' => $total_photos,
        'stored_photos' => $stored_photos,
        'uploading_photos' => $uploading_photos,
        'total_size_mb' => round($total_size / (1024 * 1024), 2),
    ],
    'photos' => array_map(function($photo) {
        return [
            'filename' => $photo['filename'],
            'status' => $photo['status'],
            'created_at' => $photo['created_at'],
            'url' => '/i/?f=' . $photo['filename'],
        ];
    }, $photos),
    'links' => [
        'view' => '/view/?code=' . $batch['batch_code'],
        'qr' => '/qr/?code=' . $batch['batch_code'],
        'json' => '/api/?code=' . $batch['batch_code'],
    ]
];

http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
