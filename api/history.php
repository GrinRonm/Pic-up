<?php
/**
 * History API - Get user's upload history
 * GET /api/history.php?offset=0&limit=10
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../SessionManager.php';
$config = require __DIR__ . '/../config.php';
$db = require __DIR__ . '/../database.php';

$sessionManager = SessionManager::getInstance();
$session_id = $sessionManager->getSessionId();

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    // Get batches for this session
    $stmt = $db->prepare("
        SELECT b.batch_code, b.created_at, b.expires_at,
               (SELECT COUNT(*) FROM photos p WHERE p.batch_id = b.id) as file_count,
               (SELECT filename FROM photos p WHERE p.batch_id = b.id LIMIT 1) as first_filename
        FROM batches b
        WHERE b.session_id = ?
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $session_id, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $history = $stmt->fetchAll();

    // Format response
    $items = [];
    foreach ($history as $row) {
        $items[] = [
            'code' => $row['batch_code'],
            'created_at' => date('d.m.Y H:i', strtotime($row['created_at'])),
            'expires_at' => date('d.m.Y H:i', strtotime($row['expires_at'])),
            'files' => (int)$row['file_count'],
            'preview_url' => $row['first_filename'] ? '/i/' . $row['first_filename'] . '?t=1' : null,
            'view_url' => '/view/?code=' . $row['batch_code']
        ];
    }

    echo json_encode([
        'success' => true,
        'items' => $items,
        'has_more' => count($items) === $limit
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
