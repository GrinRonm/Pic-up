import io
import mimetypes
import secrets
import zipfile
from pathlib import Path
from typing import Tuple, Optional
import qrcode
from fastapi import Request
from fastapi.responses import StreamingResponse

def get_secure_code(length: int = 16) -> str:
    """Generates a random hex code."""
    return secrets.token_hex(length // 2)

def format_size(size_bytes: int) -> str:
    """Formats bytes into human readable format."""
    if size_bytes < 1024:
        return f"{size_bytes} B"
    elif size_bytes < 1024 * 1024:
        return f"{size_bytes / 1024:.1f} KB"
    elif size_bytes < 1024 * 1024 * 1024:
        return f"{size_bytes / (1024 * 1024):.1f} MB"
    else:
        return f"{size_bytes / (1024 * 1024 * 1024):.2f} GB"

def determine_file_type(mime_type: Optional[str], filename: str) -> Tuple[str, str]:
    """Determines general file_type and specific mime_type."""
    if not mime_type or mime_type == "application/octet-stream":
        guessed, _ = mimetypes.guess_type(filename)
        mime_type = guessed or "application/octet-stream"

    mime_lower = mime_type.lower()
    ext = Path(filename).suffix.lower()

    if mime_lower.startswith("image/") or ext in {".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".svg", ".ico"}:
        return "image", mime_type
    elif mime_lower.startswith("video/") or ext in {".mp4", ".webm", ".mkv", ".mov", ".avi", ".wmv", ".flv"}:
        return "video", mime_type
    elif mime_lower.startswith("audio/") or ext in {".mp3", ".wav", ".ogg", ".flac", ".m4a", ".aac"}:
        return "audio", mime_type
    elif ext in {".zip", ".tar", ".gz", ".7z", ".rar", ".bz2", ".xz"}:
        return "archive", mime_type
    elif ext in {".pdf", ".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx", ".txt", ".md", ".csv"}:
        return "document", mime_type
    else:
        return "file", mime_type

def generate_qr(text: str) -> bytes:
    """Generates PNG bytes for QR code."""
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=10,
        border=2,
    )
    qr.add_data(text)
    qr.make(fit=True)
    img = qr.make_image(fill_color="#10b981", back_color="#0f172a")
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()

def build_zip_archive(files: list, zip_path: Path):
    """Builds a ZIP archive containing all files."""
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        for f in files:
            p = Path(f["path"])
            if p.exists():
                arcname = f.get("original_name") or f["filename"]
                zf.write(p, arcname=arcname)

def range_requests_response(request: Request, file_path: Path, content_type: str):
    """Handles HTTP 206 Partial Content range requests for video/audio seeking."""
    file_size = file_path.stat().st_size
    range_header = request.headers.get("range")

    headers = {
        "Accept-Ranges": "bytes",
        "Content-Type": content_type,
        "Cache-Control": "public, max-age=86400",
    }

    if range_header:
        # Format: bytes=START-END
        try:
            h = range_header.replace("bytes=", "").strip()
            parts = h.split("-")
            start = int(parts[0]) if parts[0] else 0
            end = int(parts[1]) if parts[1] else file_size - 1
            if end >= file_size:
                end = file_size - 1
            length = end - start + 1

            headers["Content-Range"] = f"bytes {start}-{end}/{file_size}"
            headers["Content-Length"] = str(length)

            def iterfile():
                with open(file_path, "rb") as f:
                    f.seek(start)
                    bytes_left = length
                    chunk_size = 64 * 1024
                    while bytes_left > 0:
                        read_bytes = min(bytes_left, chunk_size)
                        data = f.read(read_bytes)
                        if not data:
                            break
                        bytes_left -= len(data)
                        yield data

            return StreamingResponse(iterfile(), status_code=206, headers=headers)
        except Exception:
            pass

    # Full file response
    headers["Content-Length"] = str(file_size)
    def iterfile_full():
        with open(file_path, "rb") as f:
            while chunk := f.read(64 * 1024):
                yield chunk

    return StreamingResponse(iterfile_full(), status_code=200, headers=headers)
