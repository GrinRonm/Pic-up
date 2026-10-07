<?php
/**
 * ImgHost Batch Viewer
 * GET /view/?code=XXXX
 */

$config = require __DIR__ . '/../config.php';
$db = require __DIR__ . '/../database.php';

$batch_code = $_GET['code'] ?? '';

// Validate batch code
if (empty($batch_code) || strlen($batch_code) !== 16 || !ctype_xdigit($batch_code)) {
    http_response_code(400);
    die('Invalid batch code');
}

// Get batch
$stmt = $db->prepare("SELECT * FROM batches WHERE batch_code = ?");
$stmt->execute([$batch_code]);
$batch = $stmt->fetch();

if (!$batch) {
    http_response_code(404);
    die('Batch not found or expired');
}

// Get photos
$stmt = $db->prepare("SELECT * FROM photos WHERE batch_id = ? AND status != 'unavailable' ORDER BY created_at ASC");
$stmt->execute([$batch['id']]);
$photos = $stmt->fetchAll();

if (empty($photos)) {
    http_response_code(404);
    die('No available photos in this batch or batch is expired');
}

// Format dates
$created = date('d.m.Y H:i', strtotime($batch['created_at']));
$expires = date('d.m.Y H:i', strtotime($batch['expires_at']));
$days_left = ceil((strtotime($batch['expires_at']) - time()) / 86400);

// OpenGraph Data
$page_title = "Коллекция изображений #" . substr($batch_code, 0, 8);
$page_description = "Посмотрите " . count($photos) . " фото в этой подборке на ImgHost. Доступно еще " . max(0, $days_left) . " дн.";
// Use cleaner URL for og:image (absolute path)
$first_photo_filename = $photos[0]['filename'];
$first_photo_url = "https://pic-up.ae0.ru/i/" . $first_photo_filename;
$first_photo_thumb_url = $first_photo_url . "?t=1"; // Use thumbnail for previews
$page_url = "https://pic-up.ae0.ru/view/?code=" . urlencode($batch_code);

// Determine image MIME type for OG tags
$img_ext = strtolower(pathinfo($first_photo_filename, PATHINFO_EXTENSION));
$img_mime = 'image/jpeg';
if ($img_ext === 'png') $img_mime = 'image/png';
elseif ($img_ext === 'webp') $img_mime = 'image/webp';
elseif ($img_ext === 'gif') $img_mime = 'image/gif';
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ImgHost — <?php echo $page_title; ?></title>
    <meta name="description" content="<?php echo $page_description; ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo $page_url; ?>">
    <meta property="og:title" content="ImgHost — <?php echo $page_title; ?>">
    <meta property="og:description" content="<?php echo $page_description; ?>">
    <meta property="og:image" content="<?php echo $first_photo_thumb_url; ?>">
    <meta property="og:image:secure_url" content="<?php echo $first_photo_thumb_url; ?>">
    <meta property="og:image:type" content="<?php echo $img_mime; ?>">
    <meta property="og:image:width" content="800">
    <meta property="og:image:height" content="800">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo $page_url; ?>">
    <meta property="twitter:title" content="ImgHost — <?php echo $page_title; ?>">
    <meta property="twitter:description" content="<?php echo $page_description; ?>">
    <meta property="twitter:image" content="<?php echo $first_photo_thumb_url; ?>">

    <link rel="stylesheet" href="/assets/css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <header>
            <h1><a href="/" style="text-decoration: none; color: inherit;">ImgHost</a></h1>
            <p>Batch #<?php echo htmlspecialchars($batch_code); ?></p>
            <p class="subtext">
                Created: <?php echo $created; ?> | 
                Expires: <?php echo $expires; ?> 
                (<?php echo max(0, $days_left); ?> days left)
            </p>
        </header>

        <main>
            <div class="gallery">
                <?php foreach ($photos as $photo): ?>
                    <div class="gallery-item">
                        <div class="loader-overlay">
                            <div class="spinner"></div>
                        </div>
                        <img src="/i/?f=<?php echo urlencode($photo['filename']); ?>" 
                             alt="Image"
                             loading="lazy">
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="actions">
                <a href="/" class="btn secondary">Upload More</a>
                <a href="/download/?code=<?php echo $batch_code; ?>" class="btn secondary">📥 Download ZIP</a>
                <a href="/qr/?code=<?php echo $batch_code; ?>" target="_blank" class="btn secondary">🔗 QR Code</a>
                <a href="/api/?code=<?php echo $batch_code; ?>" target="_blank" class="btn secondary">📡 JSON API</a>
            </div>

            <div class="actions" style="margin-top: 1rem; opacity: 0.7;">
                <small>📊 Stats: <?php echo count($photos); ?> photos | Created: <?php echo $created; ?></small>
            </div>
        </main>

        <footer>
            <p>&copy; 2026 ImgHost. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Hide loader when image loads
        document.querySelectorAll('.gallery-item img').forEach(img => {
            img.addEventListener('load', function() {
                this.classList.add('loaded');
                this.previousElementSibling.style.display = 'none';
            });
            img.addEventListener('error', function() {
                this.previousElementSibling.innerHTML = '<span style="color: #ef4444;">Failed to load</span>';
            });
        });
    </script>
</body>
</html>
