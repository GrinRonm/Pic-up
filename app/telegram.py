import httpx
import logging
from pathlib import Path
from typing import Optional, Any
from app.config import TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID

logger = logging.getLogger("telegram")

def extract_telegram_file_id(res_data: dict) -> Optional[str]:
    """
    Extracts file_id from any Telegram media message response:
    document, audio, video, animation, voice, video_note, photo, etc.
    """
    if not isinstance(res_data, dict):
        return None

    result = res_data.get("result")
    if not isinstance(result, dict):
        return None

    # Check direct media types
    for key in ["document", "audio", "video", "animation", "voice", "video_note"]:
        media = result.get(key)
        if isinstance(media, dict) and "file_id" in media:
            return media["file_id"]

    # Check photo array (last item is highest quality)
    photos = result.get("photo")
    if isinstance(photos, list) and len(photos) > 0:
        if isinstance(photos[-1], dict) and "file_id" in photos[-1]:
            return photos[-1]["file_id"]

    # Deep scan all values for file_id
    for val in result.values():
        if isinstance(val, dict) and "file_id" in val:
            return val["file_id"]
        elif isinstance(val, list) and len(val) > 0 and isinstance(val[-1], dict) and "file_id" in val[-1]:
            return val[-1]["file_id"]

    return None

async def upload_file_to_telegram(local_path: Path, filename: str, caption: str = "") -> Optional[str]:
    """Uploads a file to Telegram channel via Bot API sendDocument and returns file_id."""
    if not local_path.exists():
        logger.error(f"Local file does not exist: {local_path}")
        return None

    url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendDocument"
    timeout = httpx.Timeout(60.0, connect=15.0)

    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            with open(local_path, "rb") as f:
                files = {"document": (filename, f)}
                data = {"chat_id": TELEGRAM_CHAT_ID, "caption": caption}
                response = await client.post(url, data=data, files=files)

            if response.status_code == 200:
                res_data = response.json()
                if res_data.get("ok"):
                    file_id = extract_telegram_file_id(res_data)
                    if file_id:
                        logger.info(f"Successfully uploaded {filename} to Telegram: {file_id}")
                        return file_id
                    else:
                        logger.error(f"Could not extract file_id from Telegram response: {res_data}")
                else:
                    logger.error(f"Telegram API error: {res_data.get('description')}")
            else:
                logger.error(f"Telegram HTTP {response.status_code}: {response.text}")
    except Exception as e:
        logger.exception(f"Exception uploading {filename} to Telegram: {e}")

    return None

async def download_file_from_telegram(file_id: str, dest_path: Path) -> bool:
    """Downloads a file from Telegram using file_id and saves it to dest_path."""
    get_file_url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/getFile?file_id={file_id}"
    timeout = httpx.Timeout(60.0, connect=15.0)

    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            res = await client.get(get_file_url)
            if res.status_code != 200:
                logger.error(f"Failed to get file info from Telegram: {res.text}")
                return False

            data = res.json()
            if not data.get("ok"):
                logger.error(f"Telegram getFile error: {data.get('description')}")
                return False

            file_path = data["result"]["file_path"]
            download_url = f"https://api.telegram.org/file/bot{TELEGRAM_BOT_TOKEN}/{file_path}"

            dest_path.parent.mkdir(parents=True, exist_ok=True)
            async with client.stream("GET", download_url) as stream_resp:
                if stream_resp.status_code != 200:
                    logger.error(f"Failed to stream file from Telegram: {stream_resp.status_code}")
                    return False
                with open(dest_path, "wb") as f:
                    async for chunk in stream_resp.aiter_bytes():
                        f.write(chunk)

            logger.info(f"Downloaded file {file_id} to {dest_path}")
            return True
    except Exception as e:
        logger.exception(f"Exception downloading {file_id} from Telegram: {e}")
        return False
