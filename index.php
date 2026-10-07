<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/SessionManager.php';
$sessionManager = SessionManager::getInstance();
$session_id = $sessionManager->getSessionId();

$max_files = $config['upload']['max_files'];
$expiry_days = $config['app']['expiry_days'];
$max_size_mb = round($config['upload']['max_size'] / (1024 * 1024));
$allowed_exts = array_map(function($mime) {
    $parts = explode('/', $mime);
    $ext = end($parts);
    return strtoupper($ext === 'jpeg' ? 'jpg' : $ext);
}, $config['upload']['allowed_types']);
$allowed_exts_str = implode(', ', $allowed_exts);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ImgHost — Бесплатный и быстрый хостинг изображений</title>
    <meta name="description" content="ImgHost — удобный сервис для временного хранения и обмена изображениями. Загружайте до 5 фото за раз, получайте короткие ссылки и делитесь ими. Хранение до 30 дней.">
    <meta name="keywords" content="хостинг изображений, загрузить фото, обмен картинками, временное хранение фото, imghost">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://pic-up.ae0.ru/">
    <meta property="og:title" content="ImgHost — Загружай и делись изображениями мгновенно">
    <meta property="og:description" content="Бесплатный хостинг для ваших фото. Просто перетащите файлы и получите ссылку.">
    <meta property="og:image" content="https://pic-up.ae0.ru/assets/img/og-preview.png">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://pic-up.ae0.ru/">
    <meta property="twitter:title" content="ImgHost — Загружай и делись изображениями мгновенно">
    <meta property="twitter:description" content="Бесплатный хостинг для ваших фото. Просто перетащите файлы и получите ссылку.">
    <meta property="twitter:image" content="https://pic-up.ae0.ru/assets/img/og-preview.png">

    <link rel="stylesheet" href="/assets/css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>
    <button class="sidebar-toggle" id="sidebar-toggle" title="История загрузок">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </button>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2>История</h2>
            <button class="close-sidebar" id="close-sidebar">&times;</button>
        </div>
        <div class="sidebar-content" id="sidebar-content">
            <div class="history-loader">Загрузка...</div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2 id="modal-title">Детали загрузки</h2>
                <button class="close-sidebar" id="close-modal">&times;</button>
            </div>
            <div class="modal-content" id="modal-content">
                <!-- Контент модального окна -->
            </div>
            <div class="modal-footer">
                <button class="btn secondary" id="modal-copy-btn">Копировать ссылку</button>
            </div>
        </div>
    </div>

    <div class="container">
        <header>
            <h1><a href="/" style="text-decoration: none; color: inherit;">ImgHost</a></h1>
            <p>Загрузите до <?php echo $max_files; ?> изображений и получите ссылку. Хранение <?php echo $expiry_days; ?> дней.</p>
        </header>

        <main>
            <div id="drop-zone" class="drop-zone">
                <div class="drop-zone-content">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    <span>Перетащите изображения сюда или кликните для выбора</span>
                    <span class="subtext">Максимум <?php echo $max_files; ?> файлов, до <?php echo $max_size_mb; ?>МБ каждый (<?php echo $allowed_exts_str; ?>)</span>
                    <input type="file" id="file-input" multiple accept="<?php echo implode(',', $config['upload']['allowed_types']); ?>" hidden>
                </div>
            </div>

            <div id="preview-container" class="preview-container"></div>

            <div class="actions">
                <button id="upload-btn" class="btn primary" disabled>Загрузить</button>
            </div>

            <div id="progress-container" class="progress-container" style="display: none;">
                <div class="progress-bar">
                    <div id="progress-fill" class="progress-fill"></div>
                </div>
                <span id="progress-text">0%</span>
            </div>

            <div id="result-container" class="result-container" style="display: none;">
                <h3>Ссылка на изображения:</h3>
                <div class="link-box">
                    <input type="text" id="share-link" readonly>
                    <button id="copy-btn" class="btn secondary">Копировать</button>
                </div>
                <div style="margin-top: 20px; text-align: center;">
                    <a href="/" class="btn secondary">Загрузить ещё</a>
                </div>
            </div>
        </main>

        <footer>
            <p>&copy; 2026 ImgHost. Все права защищены.</p>
        </footer>
    </div>

    <script src="/assets/js/scripts.js"></script>
</body>
</html>
