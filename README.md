# Pic-up

Pic-up is an image hosting platform with Telegram as a storage backend.

## Requirements
- PHP 7.4+
- SQLite
- Composer (for dependencies if any)

## Setup
1. Clone the repository.
2. Ensure the web server points to the project root.
3. Configure `config.php` with your Telegram Bot Token and Chat ID.
4. Set up a cron job to run `worker.php` every minute:
   `* * * * * cd /path/to/pic-up && php worker.php`

## API Documentation

Pic-up provides a simple JSON API to retrieve information about uploaded batches.

### Get Batch Info

**Endpoint:** `GET /api/?code={batch_code}`

**Parameters:**
- `code` (string): The 16-character batch code.

**Response (Success 200):**
```json
{
    "success": true,
    "batch": {
        "code": "8e3c158145262601",
        "created_at": "2026-10-07 10:00:00",
        "expires_at": "2026-11-06 10:00:00"
    },
    "stats": {
        "total_photos": 2,
        "stored_photos": 2,
        "uploading_photos": 0,
        "total_size_mb": 5.42
    },
    "photos": [
        {
            "filename": "abcdef123456.jpg",
            "status": "stored",
            "created_at": "2026-10-07 10:00:05",
            "url": "/i/?f=abcdef123456.jpg"
        },
        {
            "filename": "7890abcdef12.png",
            "status": "stored",
            "created_at": "2026-10-07 10:00:10",
            "url": "/i/?f=7890abcdef12.png"
        }
    ],
    "links": {
        "view": "/view/?code=8e3c158145262601",
        "qr": "/qr/?code=8e3c158145262601",
        "json": "/api/?code=8e3c158145262601"
    }
}
```

**Response (Error 404):**
```json
{
    "error": "Batch not found"
}
```

**Response (Error 400):**
```json
{
    "error": "Invalid batch code"
}
```
