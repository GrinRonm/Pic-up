import asyncio
import logging
from pathlib import Path
from app.database import get_pending_uploads, update_file_telegram, cleanup_expired
from app.telegram import upload_file_to_telegram
from app.config import CACHE_DIR

logger = logging.getLogger("worker")

async def process_single_upload(photo: dict):
    local_path = Path(photo["local_path"])
    filename = photo["filename"]
    caption = f"Batch #{photo.get('batch_id', '')} - {photo.get('original_name') or filename}"

    if not local_path.exists():
        logger.warning(f"File not found on disk: {local_path}")
        return

    file_id = await upload_file_to_telegram(local_path, filename, caption)
    if file_id:
        await update_file_telegram(photo["id"], file_id)
        # Remove local file from tmp now that it's in Telegram
        try:
            local_path.unlink(missing_ok=True)
            logger.info(f"Deleted local tmp file: {local_path}")
        except Exception as e:
            logger.warning(f"Could not delete local file: {e}")

async def run_worker_cycle():
    """Runs a single pass over pending uploads and cleans up expired files."""
    try:
        pending = await get_pending_uploads(limit=10)
        for photo in pending:
            await process_single_upload(photo)

        # Cleanup expired database records & files
        expired = await cleanup_expired()
        for item in expired:
            if item.get("local_path"):
                p = Path(item["local_path"])
                p.unlink(missing_ok=True)
            # Also clean from cache
            c = CACHE_DIR / item["filename"]
            c.unlink(missing_ok=True)
    except Exception as e:
        logger.exception(f"Error in worker cycle: {e}")

async def worker_background_loop():
    """Continuous loop running every 10 seconds in the background."""
    logger.info("Starting background worker loop...")
    while True:
        try:
            await run_worker_cycle()
        except asyncio.CancelledError:
            logger.info("Worker loop cancelled.")
            break
        except Exception as e:
            logger.exception(f"Unexpected worker exception: {e}")
        await asyncio.sleep(10)
