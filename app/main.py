import asyncio
import os
import secrets
from contextlib import asynccontextmanager
from pathlib import Path
from typing import List, Optional, Union
from datetime import datetime

from fastapi import (
    FastAPI, Request, Response, UploadFile, File,
    HTTPException, status, BackgroundTasks, Query
)
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse, FileResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel, Field

from app.config import (
    APP_URL, BASE_DIR, TMP_DIR, CACHE_DIR,
    MAX_FILES, MAX_FILE_SIZE, MAX_FILE_SIZE_MB,
    EXPIRY_DAYS, SESSION_COOKIE_NAME
)
from app.database import (
    init_db, create_batch, add_file_record,
    get_batch_by_code, get_batch_files, get_file_by_name,
    get_history_by_session
)
from app.telegram import download_file_from_telegram
from app.worker import run_worker_cycle, worker_background_loop, process_single_upload
from app.utils import (
    get_secure_code, format_size, determine_file_type,
    generate_qr, build_zip_archive, range_requests_response
)

# Lifespan for database initialization & background worker
@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_db()
    worker_task = asyncio.create_task(worker_background_loop())
    yield
    worker_task.cancel()
    try:
        await worker_task
    except asyncio.CancelledError:
        pass

app = FastAPI(
    title="Pic-up Public File API",
    description="Бесплатный публичный файлообменник с интеграцией Telegram для безопасного и быстрого хранения любых файлов (фото, видео, музыка, документы, архивы). Без API ключей и регистрации.",
    version="2.0.0",
    docs_url="/docs",
    redoc_url="/redoc",
    lifespan=lifespan
)

# Support HEAD requests transparently across all GET routes
@app.middleware("http")
async def head_to_get_middleware(request: Request, call_next):
    if request.method == "HEAD":
        request.scope["method"] = "GET"
        response = await call_next(request)
        response.body = b""
        return response
    return await call_next(request)

# Templates & Static files
templates = Jinja2Templates(directory=str(BASE_DIR / "app" / "templates"))
app.mount("/assets", StaticFiles(directory=str(BASE_DIR / "app" / "static")), name="assets")

# --- Session Helper ---
def get_or_set_session_id(request: Request, response: Response) -> str:
    session_id = request.cookies.get(SESSION_COOKIE_NAME)
    if not session_id:
        session_id = secrets.token_hex(16)
        response.set_cookie(
            key=SESSION_COOKIE_NAME,
            value=session_id,
            max_age=10 * 365 * 24 * 3600,
            httponly=True,
            samesite="lax"
        )
    return session_id

# --- Schemas ---
class FileItemSchema(BaseModel):
    original_name: str = Field(description="Исходное имя файла")
    filename: str = Field(description="Уникальное имя файла на сервере")
    file_type: str = Field(description="Тип файла: image, video, audio, document, archive, file")
    mime_type: str = Field(description="MIME тип контента")
    size_bytes: int = Field(description="Размер в байтах")
    size_formatted: str = Field(description="Размер в читаемом виде")
    url: str = Field(description="Прямой URL для просмотра/воспроизведения")
    download_url: str = Field(description="URL для прямого скачивания")

class UploadResponseSchema(BaseModel):
    success: bool
    batch_code: str = Field(description="Уникальный 16-значный код загрузки")
    view_url: str = Field(description="Ссылка на страницу просмотра файлов")
    qr_url: str = Field(description="Ссылка на QR-код для мобильных устройств")
    download_all_url: str = Field(description="Ссылка на скачивание всех файлов ZIP архивом")
    expires_at: str = Field(description="Дата и время истечения срока хранения")
    files: List[FileItemSchema]

# --- Core Upload Processing ---
async def process_uploaded_files(
    uploaded_files: List[UploadFile],
    ip_address: str,
    session_id: str,
    background_tasks: BackgroundTasks
) -> dict:
    if not uploaded_files:
        raise HTTPException(status_code=400, detail="Файлы не переданы")

    if len(uploaded_files) > MAX_FILES:
        raise HTTPException(
            status_code=400,
            detail=f"Превышено максимальное число файлов (максимум {MAX_FILES})"
        )

    batch_code = get_secure_code(16)
    batch_id = await create_batch(batch_code, ip_address, session_id, EXPIRY_DAYS)
    
    file_records = []
    response_items = []

    for uf in uploaded_files:
        if not uf.filename:
            continue
        
        orig_name = Path(uf.filename).name
        ext = Path(orig_name).suffix.lower()
        new_filename = f"{get_secure_code(32)}{ext}"
        local_path = TMP_DIR / new_filename

        # Write file and measure size
        size = 0
        with open(local_path, "wb") as f:
            while chunk := await uf.read(1024 * 1024):
                size += len(chunk)
                if size > MAX_FILE_SIZE:
                    local_path.unlink(missing_ok=True)
                    raise HTTPException(
                        status_code=400,
                        detail=f"Файл {orig_name} превышает лимит {MAX_FILE_SIZE_MB} МБ"
                    )
                f.write(chunk)

        f_type, mime = determine_file_type(uf.content_type, orig_name)

        rec_id = await add_file_record(
            batch_id=batch_id,
            original_name=orig_name,
            filename=new_filename,
            file_type=f_type,
            mime_type=mime,
            file_size=size,
            local_path=str(local_path),
            ip_address=ip_address,
            expiry_days=EXPIRY_DAYS
        )

        file_dict = {
            "id": rec_id,
            "batch_id": batch_id,
            "original_name": orig_name,
            "filename": new_filename,
            "local_path": str(local_path),
            "file_type": f_type,
            "mime_type": mime,
            "file_size": size
        }
        file_records.append(file_dict)

        response_items.append({
            "original_name": orig_name,
            "filename": new_filename,
            "file_type": f_type,
            "mime_type": mime,
            "size_bytes": size,
            "size_formatted": format_size(size),
            "url": f"{APP_URL}/i/{new_filename}",
            "download_url": f"{APP_URL}/download/file/{new_filename}"
        })

    # Trigger background Telegram upload immediately
    for rec in file_records:
        background_tasks.add_task(process_single_upload, rec)

    view_link = f"{APP_URL}/view/?code={batch_code}"
    qr_link = f"{APP_URL}/qr/?code={batch_code}"
    zip_link = f"{APP_URL}/download/?code={batch_code}"
    
    return {
        "success": True,
        "batch_code": batch_code,
        "code": batch_code,
        "link": view_link,
        "view_url": view_link,
        "qr_url": qr_link,
        "download_all_url": zip_link,
        "expires_at": "",
        "files_count": len(response_items),
        "files": response_items
    }

# --- Healthcheck ---
@app.get("/health", tags=["System"])
async def health():
    return {"status": "ok", "timestamp": datetime.utcnow().isoformat()}

# --- Web UI Pages ---
@app.get("/", response_class=HTMLResponse, tags=["Web Interface"])
async def home(request: Request):
    response = templates.TemplateResponse(
        request=request,
        name="index.html",
        context={
            "app_url": APP_URL,
            "max_files": MAX_FILES,
            "max_size_mb": MAX_FILE_SIZE_MB,
            "expiry_days": EXPIRY_DAYS
        }
    )
    get_or_set_session_id(request, response)
    return response

@app.get("/api/docs", response_class=HTMLResponse, tags=["Web Interface"])
async def api_docs_page(request: Request):
    return templates.TemplateResponse(
        request=request,
        name="api_docs.html",
        context={
            "app_url": APP_URL,
            "max_files": MAX_FILES,
            "max_size_mb": MAX_FILE_SIZE_MB,
            "expiry_days": EXPIRY_DAYS
        }
    )

# --- Public API Upload ---
@app.post(
    "/api/upload",
    response_model=UploadResponseSchema,
    summary="Загрузка файлов без API ключей",
    description="Публичный эндпоинт для мгновенной загрузки любых файлов (фото, видео, документы, архивы). Ключи не требуются.",
    tags=["Public API"]
)
async def api_upload(
    request: Request,
    response: Response,
    background_tasks: BackgroundTasks,
    files: Optional[List[UploadFile]] = File(None, description="Список файлов"),
    file: Optional[UploadFile] = File(None, description="Один файл")
):
    upload_list = []
    if files:
        upload_list.extend(files)
    if file:
        upload_list.append(file)
    
    if not upload_list:
        raise HTTPException(status_code=400, detail="Файлы не предоставлены. Используйте поле 'files' или 'file'.")

    session_id = get_or_set_session_id(request, response)
    ip_addr = request.client.host if request.client else "127.0.0.1"
    
    res = await process_uploaded_files(upload_list, ip_addr, session_id, background_tasks)
    return res

# Web UI Upload endpoint (compatible with legacy AJAX scripts)
@app.post("/upload/", tags=["Web Interface"])
@app.post("/upload", tags=["Web Interface"])
async def web_upload(
    request: Request,
    response: Response,
    background_tasks: BackgroundTasks
):
    form = await request.form()
    uploaded = []
    for key in ["images[]", "files[]", "files", "images", "file"]:
        val = form.getlist(key)
        if val:
            for item in val:
                if isinstance(item, UploadFile):
                    uploaded.append(item)

    if not uploaded:
        raise HTTPException(status_code=400, detail="Файлы не выбраны")

    session_id = get_or_set_session_id(request, response)
    ip_addr = request.client.host if request.client else "127.0.0.1"

    res = await process_uploaded_files(uploaded, ip_addr, session_id, background_tasks)
    return {
        "success": True,
        "link": res["view_url"],
        "code": res["batch_code"],
        "files": len(res["files"])
    }

# --- Batch View Page ---
@app.get("/view/{code}", response_class=HTMLResponse, tags=["Web Interface"])
@app.get("/view/", response_class=HTMLResponse, tags=["Web Interface"])
async def view_batch(request: Request, code: Optional[str] = None):
    batch_code = code or request.query_params.get("code", "")
    if not batch_code:
        raise HTTPException(status_code=400, detail="Код подборки не указан")

    batch = await get_batch_by_code(batch_code)
    if not batch:
        raise HTTPException(status_code=404, detail="Подборка не найдена или срок хранения истек")

    files = await get_batch_files(batch["id"])
    if not files:
        raise HTTPException(status_code=404, detail="В этой подборке нет активных файлов")

    formatted_files = []
    total_bytes = 0
    for f in files:
        sz = f.get("file_size") or 0
        total_bytes += sz
        formatted_files.append({
            "id": f["id"],
            "filename": f["filename"],
            "original_name": f.get("original_name") or f["filename"],
            "file_type": f.get("file_type") or "file",
            "mime_type": f.get("mime_type") or "application/octet-stream",
            "size_formatted": format_size(sz),
            "url": f"/i/{f['filename']}",
            "download_url": f"/download/file/{f['filename']}",
            "status": f.get("status")
        })

    return templates.TemplateResponse(
        request=request,
        name="view.html",
        context={
            "app_url": APP_URL,
            "batch_code": batch_code,
            "batch": batch,
            "files": formatted_files,
            "total_files": len(formatted_files),
            "total_size": format_size(total_bytes)
        }
    )

# --- Direct File Streaming / Viewing ---
@app.get("/i/{filename}", tags=["Files"])
@app.get("/i/", tags=["Files"])
async def serve_file(request: Request, filename: Optional[str] = None, f: Optional[str] = Query(None)):
    target_name = filename or f
    if not target_name:
        raise HTTPException(status_code=400, detail="Имя файла не указано")

    photo = await get_file_by_name(target_name)
    if not photo or photo.get("status") == "unavailable":
        raise HTTPException(status_code=404, detail="Файл не найден")

    # Check local tmp path first
    if photo.get("local_path") and Path(photo["local_path"]).exists():
        file_path = Path(photo["local_path"])
    else:
        # Check cache
        cache_path = CACHE_DIR / target_name
        if not cache_path.exists():
            # Download from Telegram
            tg_file_id = photo.get("telegram_file_id")
            if not tg_file_id:
                raise HTTPException(status_code=404, detail="Файл еще загружается в Telegram")
            success = await download_file_from_telegram(tg_file_id, cache_path)
            if not success or not cache_path.exists():
                raise HTTPException(status_code=502, detail="Не удалось получить файл из Telegram")
        file_path = cache_path

    _, mime_type = determine_file_type(photo.get("mime_type"), target_name)
    return range_requests_response(request, file_path, mime_type)

# --- Direct Attachment Download ---
@app.get("/download/file/{filename}", tags=["Files"])
async def download_single_file(filename: str):
    photo = await get_file_by_name(filename)
    if not photo or photo.get("status") == "unavailable":
        raise HTTPException(status_code=404, detail="Файл не найден")

    cache_path = CACHE_DIR / filename
    if not cache_path.exists():
        if photo.get("local_path") and Path(photo["local_path"]).exists():
            cache_path = Path(photo["local_path"])
        else:
            tg_id = photo.get("telegram_file_id")
            if not tg_id:
                raise HTTPException(status_code=404, detail="Файл еще не доступен")
            await download_file_from_telegram(tg_id, cache_path)

    orig_name = photo.get("original_name") or filename
    return FileResponse(cache_path, filename=orig_name)

# --- Download Batch as ZIP ---
@app.get("/download/{code}", tags=["Files"])
@app.get("/download/", tags=["Files"])
async def download_batch_zip(request: Request, code: Optional[str] = None):
    batch_code = code or request.query_params.get("code")
    if not batch_code:
        raise HTTPException(status_code=400, detail="Код не указан")

    batch = await get_batch_by_code(batch_code)
    if not batch:
        raise HTTPException(status_code=404, detail="Подборка не найдена")

    files = await get_batch_files(batch["id"])
    if not files:
        raise HTTPException(status_code=404, detail="Нет доступных файлов")

    zip_items = []
    for f in files:
        c_path = CACHE_DIR / f["filename"]
        if not c_path.exists():
            if f.get("local_path") and Path(f["local_path"]).exists():
                c_path = Path(f["local_path"])
            elif f.get("telegram_file_id"):
                await download_file_from_telegram(f["telegram_file_id"], c_path)
        
        if c_path.exists():
            zip_items.append({
                "path": str(c_path),
                "original_name": f.get("original_name") or f["filename"],
                "filename": f["filename"]
            })

    zip_tmp = TMP_DIR / f"batch_{batch_code}.zip"
    build_zip_archive(zip_items, zip_tmp)
    return FileResponse(zip_tmp, filename=f"pic_up_{batch_code}.zip")

# --- QR Code ---
@app.get("/qr/{code}", tags=["Files"])
@app.get("/qr/", tags=["Files"])
async def qr_code_endpoint(request: Request, code: Optional[str] = None):
    batch_code = code or request.query_params.get("code")
    if not batch_code:
        raise HTTPException(status_code=400, detail="Код не указан")
    
    target_url = f"{APP_URL}/view/?code={batch_code}"
    qr_bytes = generate_qr(target_url)
    return Response(content=qr_bytes, media_type="image/png")

# --- Batch JSON Metadata ---
@app.get("/api/batch/{code}", tags=["Public API"])
@app.get("/api/", tags=["Public API"])
async def api_batch_info(request: Request, code: Optional[str] = None):
    batch_code = code or request.query_params.get("code")
    if not batch_code:
        raise HTTPException(status_code=400, detail="Код не указан")

    batch = await get_batch_by_code(batch_code)
    if not batch:
        raise HTTPException(status_code=404, detail="Подборка не найдена")

    files = await get_batch_files(batch["id"])
    items = []
    total_size = 0
    stored_count = 0
    uploading_count = 0

    for f in files:
        sz = f.get("file_size") or 0
        total_size += sz
        if f.get("status") == "stored":
            stored_count += 1
        elif f.get("status") == "uploading":
            uploading_count += 1

        items.append({
            "original_name": f.get("original_name") or f["filename"],
            "filename": f["filename"],
            "file_type": f.get("file_type") or "file",
            "mime_type": f.get("mime_type") or "application/octet-stream",
            "status": f.get("status"),
            "size_bytes": sz,
            "size_formatted": format_size(sz),
            "created_at": str(f.get("created_at")),
            "url": f"/i/{f['filename']}",
            "download_url": f"/download/file/{f['filename']}"
        })

    return {
        "success": True,
        "batch": {
            "code": batch["batch_code"],
            "created_at": str(batch["created_at"]),
            "expires_at": str(batch["expires_at"])
        },
        "stats": {
            "total_files": len(items),
            "stored_files": stored_count,
            "uploading_files": uploading_count,
            "total_size_mb": round(total_size / (1024 * 1024), 2),
            "total_size_formatted": format_size(total_size)
        },
        "files": items,
        "links": {
            "view": f"/view/?code={batch_code}",
            "qr": f"/qr/?code={batch_code}",
            "download_zip": f"/download/?code={batch_code}",
            "json": f"/api/batch/{batch_code}"
        }
    }

# --- History API ---
@app.get("/api/history", tags=["Public API"])
@app.get("/api/history.php", tags=["Public API"])
async def api_history(request: Request, offset: int = 0, limit: int = 15):
    session_id = request.cookies.get(SESSION_COOKIE_NAME)
    if not session_id:
        return {"success": True, "items": [], "has_more": False}

    rows = await get_history_by_session(session_id, offset, limit)
    items = []
    for r in rows:
        preview_url = f"/i/{r['first_filename']}" if r.get("first_filename") else None
        items.append({
            "code": r["batch_code"],
            "created_at": str(r["created_at"]),
            "expires_at": str(r["expires_at"]),
            "files": r["file_count"],
            "first_file_type": r.get("first_file_type") or "file",
            "preview_url": preview_url,
            "view_url": f"/view/?code={r['batch_code']}"
        })

    return {
        "success": True,
        "items": items,
        "has_more": len(items) == limit
    }
