<?php
/**
 * ImgHost Admin Panel
 * GET /admin/ - List management
 * POST /admin/?action=delete&code=XXXX - Delete batch
 */

// Simple admin key check (should be changed in production)
$admin_key = $_GET['key'] ?? $_POST['key'] ?? '';
$expected_key = md5('admin123'); // Change this!

if ($admin_key !== $expected_key && !isset($_SESSION['admin'])) {
    // Show login form
    ?><!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ImgHost Admin Login</title>
        <link rel="stylesheet" href="/assets/css/styles.css">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    </head>
    <body>
        <div class="container">
            <header>
                <h1>ImgHost Admin</h1>
                <p>Management Panel</p>
            </header>
            <main style="max-width: 400px; margin: 50px auto;">
                <form method="POST">
                    <input type="password" name="key" placeholder="Admin Key" required style="width:100%;padding:10px;margin:10px 0;border:1px solid #334155;background:#1e293b;color:#f8fafc;border-radius:5px;">
                    <button type="submit" class="btn primary" style="width:100%;">Login</button>
                </form>
            </main>
        </div>
    </body>
    </html><?php
    exit;
}

$config = require __DIR__ . '/../config.php';
$db = require __DIR__ . '/../database.php';

// Handle settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settings') {
    $expiry = intval($_POST['expiry_days']);
    $types = array_map('trim', explode(',', $_POST['allowed_types']));
    
    // Validate
    if ($expiry > 0 && !empty($types)) {
        $config_content = file_get_contents(__DIR__ . '/../config.php');
        
        // Replace expiry_days
        $config_content = preg_replace("/'expiry_days'\s*=>\s*\d+/", "'expiry_days' => $expiry", $config_content);
        
        // Replace allowed_types
        $types_str = "['" . implode("', '", $types) . "']";
        $config_content = preg_replace("/'allowed_types'\s*=>\s*\[.*?\]/", "'allowed_types' => $types_str", $config_content);
        
        file_put_contents(__DIR__ . '/../config.php', $config_content);
        
        // Reload config
        $config = require __DIR__ . '/../config.php';
        $_GET['settings_saved'] = 1;
    }
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete') {
    $batch_code = $_POST['code'] ?? '';
    
    if ($batch_code && strlen($batch_code) === 16) {
        // Get batch
        $stmt = $db->prepare("SELECT * FROM batches WHERE batch_code = ?");
        $stmt->execute([$batch_code]);
        $batch = $stmt->fetch();
        
        if ($batch) {
            // Get all photos in batch
            $stmt = $db->prepare("SELECT * FROM photos WHERE batch_id = ?");
            $stmt->execute([$batch['id']]);
            $photos = $stmt->fetchAll();
            
            // Delete local files
            foreach ($photos as $photo) {
                if (file_exists($photo['local_path'])) {
                    @unlink($photo['local_path']);
                }
                $cache_file = $config['upload']['cache_dir'] . $photo['filename'];
                if (file_exists($cache_file)) {
                    @unlink($cache_file);
                }
            }
            
            // Delete from database
            $db->prepare("DELETE FROM photos WHERE batch_id = ?")->execute([$batch['id']]);
            $db->prepare("DELETE FROM batches WHERE id = ?")->execute([$batch['id']]);
            
            $_GET['deleted'] = $batch_code;
        }
    }
}

// Get all batches
$batches = $db->query("
    SELECT b.*, COUNT(p.id) as photo_count 
    FROM batches b 
    LEFT JOIN photos p ON b.id = p.batch_id 
    GROUP BY b.id 
    ORDER BY b.created_at DESC 
    LIMIT 100
")->fetchAll();

// Calculate stats
$total_batches = count($batches);
$total_photos = $db->query("SELECT COUNT(*) as cnt FROM photos")->fetch()['cnt'];
$total_size = 0;
foreach (glob($config['upload']['tmp_dir'] . '*') as $file) {
    if (is_file($file)) $total_size += filesize($file);
}
foreach (glob($config['upload']['cache_dir'] . '*') as $file) {
    if (is_file($file)) $total_size += filesize($file);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ImgHost Admin Panel</title>
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #1e293b; font-weight: bold; }
        tr:hover { background: rgba(59, 130, 246, 0.05); }
        .delete-btn { background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
        .delete-btn:hover { background: #dc2626; }
        .stat-card { background: #1e293b; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .success-msg { background: #10b981; color: white; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>ImgHost Admin Panel</h1>
            <p>Manage batches and files</p>
        </header>

        <main>
            <?php if (isset($_GET['deleted'])): ?>
                <div class="success-msg">✅ Batch deleted: <?php echo htmlspecialchars($_GET['deleted']); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['settings_saved'])): ?>
                <div class="success-msg">✅ Settings saved successfully.</div>
            <?php endif; ?>

            <h2>📊 Statistics</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                <div class="stat-card">
                    <strong>Total Batches:</strong><br><?php echo $total_batches; ?>
                </div>
                <div class="stat-card">
                    <strong>Total Photos:</strong><br><?php echo $total_photos; ?>
                </div>
                <div class="stat-card">
                    <strong>Total Storage:</strong><br><?php echo round($total_size / (1024 * 1024), 2); ?> MB
                </div>
            </div>

            <h2>📋 Recent Batches</h2>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Photos</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th>IP</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($batch['batch_code']); ?></code></td>
                            <td><?php echo $batch['photo_count']; ?></td>
                            <td><?php echo substr($batch['created_at'], 0, 10); ?></td>
                            <td><?php echo substr($batch['expires_at'], 0, 10); ?></td>
                            <td><?php echo htmlspecialchars($batch['ip_address']); ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this batch?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="key" value="<?php echo htmlspecialchars($admin_key); ?>">
                                    <input type="hidden" name="code" value="<?php echo htmlspecialchars($batch['batch_code']); ?>">
                                    <button type="submit" class="delete-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>⚙️ Settings</h2>
            <form method="POST" style="background: #1e293b; padding: 15px; border-radius: 5px; margin: 10px 0;">
                <input type="hidden" name="action" value="settings">
                <input type="hidden" name="key" value="<?php echo htmlspecialchars($admin_key); ?>">
                
                <div style="margin-bottom: 10px;">
                    <label style="display:block;margin-bottom:5px;">Сколько дней будет храниться файлы (Storage Days):</label>
                    <input type="number" name="expiry_days" value="<?php echo htmlspecialchars($config['app']['expiry_days']); ?>" style="width:100%;padding:8px;border:1px solid #334155;background:#0f172a;color:#f8fafc;border-radius:3px;">
                </div>
                
                <div style="margin-bottom: 10px;">
                    <label style="display:block;margin-bottom:5px;">Какие файлы можно загружать (Allowed Types, comma separated):</label>
                    <input type="text" name="allowed_types" value="<?php echo htmlspecialchars(implode(', ', $config['upload']['allowed_types'])); ?>" style="width:100%;padding:8px;border:1px solid #334155;background:#0f172a;color:#f8fafc;border-radius:3px;">
                </div>
                
                <button type="submit" class="btn primary">Save Settings</button>
            </form>

            <h2>⚙️ Utilities</h2>
            <form method="POST" onsubmit="return confirm('Delete all expired batches?');">
                <input type="hidden" name="action" value="cleanup">
                <input type="hidden" name="key" value="<?php echo htmlspecialchars($admin_key); ?>">
                <button type="submit" class="btn secondary">Clean up expired batches</button>
            </form>
        </main>
    </div>
</body>
</html>
