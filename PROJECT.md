# ImgHost - Temporary Image Hosting Service

A lightweight, self-hosted image sharing service with temporary storage and Telegram integration.

## Features

✨ **Core Features:**
- Upload up to 5 images at once (max 15MB each)
- Drag & drop and paste from clipboard support
- Automatic link generation for sharing
- 30-day temporary storage
- Beautiful dark UI with responsive design
- No registration required

🚀 **Backend:**
- Pure PHP (no frameworks)
- SQLite database
- Telegram bot integration for encrypted storage
- Automatic background upload to Telegram after file upload
- Image optimization during upload (85% quality)
- Automatic cleanup with background worker
- Logging system

## Project Structure

```
.
├── index.php              # Main upload page
├── config.php             # Configuration
├── database.php           # SQLite connection
├── worker.php             # Background worker (cron)
│
├── upload/
│   └── index.php         # POST /upload/ - File upload handler
├── view/
│   └── index.php         # GET /view/?code=XXXX - Gallery viewer
├── i/
│   └── index.php         # GET /i/?f=filename - Image proxy
│
├── assets/
│   ├── css/
│   │   └── styles.css    # Modern dark theme
│   └── js/
│       └── scripts.js    # No dependencies, vanilla JS
│
└── uploads/
    ├── tmp/              # Temporary local storage
    └── cache/            # Telegram file cache
```

## Installation

### 1. Requirements
- PHP 7.4+ with PDO and cURL
- Apache/Nginx web server
- Telegram Bot

### 2. Setup

```bash
# Clone or download the files
cd /var/www/pic-up.ae0.ru

# Create required directories
mkdir -p uploads/{tmp,cache}
chmod 755 uploads/{tmp,cache}

# Initialize database (auto-created on first request)
php -r "require 'database.php';"

# Test the installation
php test.php
```

### 3. Configuration

Edit `config.php`:

```php
'app' => [
    'url' => 'https://your-domain.com',  // Change to your domain
    'expiry_days' => 30,
],
'telegram' => [
    'bot_token' => 'YOUR_BOT_TOKEN',
    'chat_id' => 'YOUR_PRIVATE_CHANNEL_ID',
],
```

### 4. Telegram Setup

1. Create a Telegram bot: [@BotFather](https://t.me/botfather)
2. Create a private channel and add the bot as admin
3. Get your channel ID (send message, check @RawDataBot)
4. Update `config.php` with bot token and channel ID

## Usage

### Frontend

1. Open `https://your-domain.com/`
2. Upload images (drag & drop, or click to select)

## API Reference

Complete API documentation for ImgHost.

## Routing

All routes use directory-based routing (no URL rewriting required):

```
GET  /                   → Image upload page
POST /upload/            → Upload handler JSON API
GET  /view/?code=XXXX    → View batch gallery
GET  /i/?f=filename      → Image proxy (redirects to Telegram or local)
GET  /health.php         → Health check JSON
```

## Endpoints

### 1. Upload Images

**Request:**
```http
POST /upload/
Content-Type: multipart/form-data

images[]=<binary_file>
images[]=<binary_file>
```

**Process:**
1. Validate files (size, type, count)
2. Save to temporary local storage
3. Optimize images (JPEG/PNG/WebP compression)
4. Insert records to database
5. Start background worker for Telegram upload
6. Return success response with share link

**Example (curl):**
```bash
curl -X POST https://pic-up.ae0.ru/upload/ \
  -F "images[]=@photo1.jpg" \
  -F "images[]=@photo2.jpg" \
  -F "images[]=@photo3.png"
```

**Response Success (200):**
```json
{
  "success": true,
  "link": "https://pic-up.ae0.ru/view/?code=5d481f681d8a6342",
  "code": "5d481f681d8a6342",
  "files": 3
}
```

**Response Error (400):**
```json
{
  "success": false,
  "message": "File too large: photo.jpg"
}
```

**Validation Rules:**
- Max 5 files per upload
- Max 15MB per file
- Allowed types: JPEG, PNG, WebP, GIF
- Only image files accepted
- Automatic optimization to 85% quality

### 2. View Batch Gallery

**Request:**
```http
GET /view/?code=5d481f681d8a6342
```

**Response:**
- HTML page with gallery of all images in batch
- Shows batch creation date and expiry date
- Images load through proxy (`/i/?f=...`)

**Example:**
```html
https://pic-up.ae0.ru/view/?code=5d481f681d8a6342
```

### 3. Get Image (Proxy)

**Request:**
```http
GET /i/?f=<filename>
```

**Behavior:**
- If file is "uploading" → serves from local storage
- If file is "stored" → serves from Telegram (with local cache)
- If local file not available → automatically downloads from Telegram
- Returns 404 if file not found or expired

**Headers:**
```
Content-Type: image/jpeg (or png, webp, gif)
Content-Length: <size>
Cache-Control: public, max-age=86400
Last-Modified: <timestamp>
```

## Quick Installation Guide

### Step 1: Upload Files
```bash
# Upload all files to your web server
# Structure should be:
/var/www/pic-up.ae0.ru/
├── index.php
├── config.php
├── database.php
├── worker.php
├── assets/
├── upload/
├── view/
└── i/
```

### Step 2: Create Directories
```bash
mkdir -p uploads/{tmp,cache}
chmod 755 uploads uploads/tmp uploads/cache
```

### Step 3: Update Config
Edit `config.php` with your Telegram bot info:

```php
'app' => [
    'url' => 'https://pic-up.ae0.ru',  // Your domain
    'expiry_days' => 30,
],
'telegram' => [
    'bot_token' => 'YOUR_BOT_TOKEN_HERE',
    'chat_id' => 'YOUR_CHANNEL_ID_HERE',
],
```

### Step 4: Get Telegram Bot

1. Message [@BotFather](https://t.me/botfather) on Telegram
2. Send `/newbot` and follow instructions
3. Create a private channel
4. Add bot to channel as admin
5. Send any message in channel
6. Get channel ID from [@RawDataBot](https://t.me/rawdatabot)

## Verify Installation

### Check if database works:
```bash
php -r "require 'database.php'; echo 'Database OK';"
```

### Check system health:
Visit: `https://pic-up.ae0.ru/health.php`

### Test upload:
Visit: `https://pic-up.ae0.ru/`

## Setup Worker (Telegram Sync & Cache Cleanup)

### Option 1: Cron Job (Recommended)
Since the worker now performs a full cache cleanup on every execution, it is recommended to run it once a day.

```bash
# Add to crontab
crontab -e

# Insert this line to run daily at midnight:
0 0 * * * cd /var/www/pic-up.ae0.ru && php worker.php >> logs.txt 2>&1
```

### Option 2: Manual Run
```bash
# Run manually to sync files and completely clear the cache folder
php worker.php
```

## File Permissions

```bash
# Web server user should own files (usually www-data)
sudo chown -R www-data:www-data /var/www/pic-up.ae0.ru
sudo chmod -R 755 /var/www/pic-up.ae0.ru
sudo chmod 777 uploads/{tmp,cache}
```

## PHP Configuration

Make sure your `php.ini` settings allow file uploads:

```ini
# Upload limits
upload_max_filesize = 20M
post_max_size = 100M

# Execution time
max_execution_time = 300
default_socket_timeout = 300
```

## Version History

### v2.1.0 - Cache Optimization Update

**Date:** April 8, 2026  
**Status:** ✅ UPDATED

#### ✨ New Cache Policy:
The background worker now performs a **full cleanup** of the `uploads/cache/` directory every time it runs. This ensures that no cached files remain on the server longer than necessary, especially when the worker is scheduled to run daily.

**Changes:**
- Modified `worker.php` to delete all files in the cache folder on each execution.
- Recommended cron frequency updated to daily (`0 0 * * *`).

---

### v2.0.0 - Complete Feature Expansion

**Date:** April 6, 2026  
**Status:** ✅ FULLY FUNCTIONAL

#### ✨ 5 New Powerful Features:

##### 1. 📱 QR Code for Quick Access
**Route:** `/qr/?code=XXXX`

Generates a QR code for batch sharing, allowing quick access to the gallery through a mobile phone camera.

**Example:**
```
https://pic-up.ae0.ru/qr/?code=5d481f681d8a6342
```

**Usage:**
- Point your phone's camera at the QR code
- Automatically opens the gallery
- Works on any device

---

##### 2. 📡 REST API for Batch Information
**Route:** `/api/?code=XXXX`

JSON API for getting batch information, statistics, and list of all photos.

**Example Request:**
```bash
curl "https://pic-up.ae0.ru/api/?code=5d481f681d8a6342"
```

**Response:**
```json
{
  "success": true,
  "batch": {
    "code": "5d481f681d8a6342",
    "created_at": "2026-04-06 13:48:06",
    "expires_at": "2026-05-06 13:48:06",
    "ip_address": "192.168.0.100"
  },
  "stats": {
    "total_photos": 5,
    "stored_photos": 2,
    "uploading_photos": 3,
    "total_size_mb": 12.5
  },
  "photos": [
    {
      "filename": "abc123def456.jpg",
      "status": "uploading",
      "created_at": "2026-04-06 13:48:06",
      "url": "/i/?f=abc123def456.jpg"
    }
  ]
}
```

**Usage in Code:**
```javascript
fetch('/api/?code=5d481f681d8a6342')
  .then(r => r.json())
  .then(data => {
    console.log(`${data.stats.total_photos} photos uploaded`);
  });
```

---

##### 3. 📥 Download Batch as ZIP Archive
**Route:** `/download/?code=XXXX`

Downloads all photos from the batch in a single ZIP archive.

**Example:**
```
https://pic-up.ae0.ru/download/?code=5d481f681d8a6342
```

**Features:**
- Automatically named archive: `batch_CODE_YYYYMMDDHHmmss.zip`
- Includes both local and uploaded Telegram files
- Works in browser - download starts automatically

---

##### 4. ⚡ Image Optimization
**File:** `ImageOptimizer.php`

PHP class for compressing and optimizing images during upload.

**Usage:**
```php
require 'ImageOptimizer.php';

$optimizer = new ImageOptimizer($config);

// Optimize JPEG with 85% quality
$optimizer->optimize('/path/to/original.jpg', '/path/to/optimized.jpg', 85);

// Get compression savings
$savings = $optimizer->getSavings(1000000, 750000); // 25%
```

---

##### 5. 🔧 Admin Panel
**Route:** `/admin/?key=YOUR_KEY`

Web interface for batch management and statistics.

**Features:**
- View all batches
- Statistics (batches, photos, volume)
- Delete batches
- Clean expired data

---

## New Files and Directories

```diff
✅ /qr/index.php                      ← QR code generator
✅ /api/index.php                     ← REST API for batch info
✅ /download/index.php                ← ZIP archive download
✅ /admin/index.php                   ← Admin panel
✅ ImageOptimizer.php                 ← Image optimization class
```

## Developer Integration Guide

### QR Code Integration

**What it is:** Generates QR code for quick access to batch through mobile phone

**File:** `/qr/index.php`

**Usage in HTML:**
```html
<!-- In gallery -->
<img src="/qr/?code=<?php echo $batch_code; ?>" alt="QR" width="200">

<!-- In JavaScript -->
const qrUrl = `/qr/?code=${batchCode}`;
```

**API:**
- GET `/qr/?code=BATCH_CODE` → PNG image

---

### REST API Integration

**What it is:** JSON API for getting information, statistics, and photo list

**File:** `/api/index.php`

**Usage in JavaScript:**
```javascript
// Get batch information
fetch('/api/?code=5d481f681d8a6342')
  .then(r => r.json())
  .then(data => {
    console.log(`Photos: ${data.stats.total_photos}`);
    console.log(`Size: ${data.stats.total_size_mb} MB`);
    
    // Get photo list
    data.photos.forEach(photo => {
      console.log(`${photo.filename} - ${photo.status}`);
    });
  });
```

**Response includes:**
```json
{
  "batch": { "code", "created_at", "expires_at" },
  "stats": { "total_photos", "stored_photos", "total_size_mb" },
  "photos": [ { "filename", "status", "url" } ],
  "links": { "view", "qr", "json" }
}
```

---

### ZIP Download Integration

**What it is:** Downloads all batch photos in a single archive

**File:** `/download/index.php`

**Usage in HTML:**
```html
<a href="/download/?code=<?php echo $batch_code; ?>" 
   download class="btn">
  📥 Download as ZIP
</a>
```

**API:**
- GET `/download/?code=BATCH_CODE` → ZIP archive for download

**Features:**
- Automatic name: `batch_CODE_TIMESTAMP.zip`
- Includes local and Telegram files
- Browser download

---

### Image Optimizer Integration

**What it is:** PHP class for image compression and optimization

**File:** `/ImageOptimizer.php`

**Usage in Code:**
```php
require __DIR__ . '/ImageOptimizer.php';

$optimizer = new ImageOptimizer($config);

// Optimize JPEG with 85% quality
$success = $optimizer->optimize(
    '/path/to/original.jpg',
    '/path/to/optimized.jpg',
    85
);

// Get compression percentage
$savings = $optimizer->getSavings(1000000, 750000); // 25%
```

---

### Admin Panel Integration

**What it is:** Web interface for batch management and statistics

**File:** `/admin/index.php`

**Access:**
```
GET /admin/?key=YOUR_KEY → Web interface
```

**Features:**
- View all batches
- Statistics display
- Batch deletion
- Expired data cleanup

---

## Project Completion Report

### Status: COMPLETE & OPTIMIZED

All unnecessary files removed. System fully cleaned up and optimized.

### Final Project Structure

```
pic-up.ae0.ru/
│
├── 📄 Core Files
├── index.php              Main upload page
├── config.php             Configuration
├── database.php           SQLite connection
├── worker.php             Background worker
│
├── 📂 /upload/
│   └── index.php         POST handler for file uploads
│
├── 📂 /view/
│   └── index.php         GET handler for batch gallery
│
├── 📂 /i/
│   └── index.php         GET handler for image proxy
│
├── 📂 /assets/
│   ├── /css/
│   │   └── styles.css    Modern dark theme (optimized)
│   └── /js/
│       └── scripts.js    Vanilla JS (no dependencies)
│
├── 📂 /uploads/
│   ├── /tmp/             Temporary files
│   └── /cache/           Telegram cache
│
├── 📚 Documentation
├── PROJECT.md             Full documentation
├── INSTALLATION.md        Quick setup guide
├── API.md                 API reference
└── database.sqlite       SQLite database (auto-created)
```

### Cleanup Completed

Removed unnecessary files for cleaner codebase.

### Optimizations Applied

#### 1. Code Quality
- Better error handling with try-catch
- Input validation and sanitization
- SQL injection prevention (prepared statements)
- Safe file naming (random hex)
- Proper HTTP status codes
- Standard JSON API responses

#### 2. Performance
- File caching for Telegram images (24-hour browser cache)
- Optimized database queries
- Lazy loading for images
- Minified CSS with modern approach
- No external JavaScript dependencies

#### 3. User Experience
- Smooth animations and transitions
- Responsive design (mobile-friendly)
- Real-time upload progress
- Better error messages
- Modern dark UI with gradients
- Accessibility improvements

#### 4. Backend
- Comprehensive logging system
- Better health checks
- Worker process improvements
- Batch transaction support
- Graceful error handling

#### 5. Security
- File type validation
- Size limits enforcement
- Path traversal protection
- XSS prevention
- CSRF protection
- Secure headers

---

## 🎯 Testing Results

All features tested and working:

- ✅ Upload functionality
- ✅ Batch viewing
- ✅ QR code generation
- ✅ API responses
- ✅ ZIP downloads
- ✅ Admin panel
- ✅ Image optimization
- ✅ Telegram integration
- ✅ Background worker
- ✅ Cleanup processes

## 🎉 Project Ready for Production!

All requested features implemented and fully functional. The image hosting service now includes advanced sharing options, API integration, admin management, and optimized performance.