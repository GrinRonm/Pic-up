import aiosqlite
from datetime import datetime, timedelta
from typing import List, Optional, Dict, Any
from app.config import DB_PATH, EXPIRY_DAYS

async def get_db():
    db = await aiosqlite.connect(DB_PATH)
    db.row_factory = aiosqlite.Row
    await db.execute("PRAGMA foreign_keys = ON;")
    return db

async def init_db():
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("PRAGMA foreign_keys = ON;")
        
        # Batches table
        await db.execute("""
            CREATE TABLE IF NOT EXISTS batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_code TEXT UNIQUE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                ip_address TEXT,
                session_id TEXT
            );
        """)
        
        # Files table (named photos for backward compatibility)
        await db.execute("""
            CREATE TABLE IF NOT EXISTS photos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id INTEGER NOT NULL,
                original_name TEXT,
                filename TEXT NOT NULL,
                file_type TEXT DEFAULT 'file',
                mime_type TEXT,
                file_size INTEGER DEFAULT 0,
                local_path TEXT,
                telegram_file_id TEXT,
                telegram_file_path TEXT,
                status TEXT DEFAULT 'uploading',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                ip_address TEXT,
                FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
            );
        """)

        # Add columns if migrating from older db without them
        try:
            await db.execute("ALTER TABLE photos ADD COLUMN original_name TEXT;")
        except Exception:
            pass
        try:
            await db.execute("ALTER TABLE photos ADD COLUMN file_type TEXT DEFAULT 'file';")
        except Exception:
            pass
        try:
            await db.execute("ALTER TABLE photos ADD COLUMN mime_type TEXT;")
        except Exception:
            pass
        try:
            await db.execute("ALTER TABLE photos ADD COLUMN file_size INTEGER DEFAULT 0;")
        except Exception:
            pass
        try:
            await db.execute("ALTER TABLE batches ADD COLUMN session_id TEXT;")
        except Exception:
            pass

        await db.commit()

async def create_batch(batch_code: str, ip_address: str, session_id: str, expiry_days: int = EXPIRY_DAYS) -> int:
    expires_at = datetime.utcnow() + timedelta(days=expiry_days)
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute(
            "INSERT INTO batches (batch_code, expires_at, ip_address, session_id) VALUES (?, ?, ?, ?)",
            (batch_code, expires_at.strftime("%Y-%m-%d %H:%M:%S"), ip_address, session_id)
        )
        await db.commit()
        return cursor.lastrowid

async def add_file_record(
    batch_id: int,
    original_name: str,
    filename: str,
    file_type: str,
    mime_type: str,
    file_size: int,
    local_path: str,
    ip_address: str,
    expiry_days: int = EXPIRY_DAYS
) -> int:
    expires_at = datetime.utcnow() + timedelta(days=expiry_days)
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("""
            INSERT INTO photos (
                batch_id, original_name, filename, file_type, mime_type, 
                file_size, local_path, status, expires_at, ip_address
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'uploading', ?, ?)
        """, (
            batch_id, original_name, filename, file_type, mime_type,
            file_size, local_path, expires_at.strftime("%Y-%m-%d %H:%M:%S"), ip_address
        ))
        await db.commit()
        return cursor.lastrowid

async def get_batch_by_code(batch_code: str) -> Optional[Dict[str, Any]]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT * FROM batches WHERE batch_code = ?", (batch_code,)) as cursor:
            row = await cursor.fetchone()
            return dict(row) if row else None

async def get_batch_files(batch_id: int) -> List[Dict[str, Any]]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute(
            "SELECT * FROM photos WHERE batch_id = ? AND status != 'unavailable' ORDER BY id ASC",
            (batch_id,)
        ) as cursor:
            rows = await cursor.fetchall()
            return [dict(r) for r in rows]

async def get_file_by_name(filename: str) -> Optional[Dict[str, Any]]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT * FROM photos WHERE filename = ?", (filename,)) as cursor:
            row = await cursor.fetchone()
            return dict(row) if row else None

async def update_file_telegram(file_id: int, telegram_file_id: str, telegram_file_path: Optional[str] = None):
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("""
            UPDATE photos 
            SET telegram_file_id = ?, telegram_file_path = ?, status = 'stored' 
            WHERE id = ?
        """, (telegram_file_id, telegram_file_path, file_id))
        await db.commit()

async def get_pending_uploads(limit: int = 20) -> List[Dict[str, Any]]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute(
            "SELECT * FROM photos WHERE status = 'uploading' ORDER BY id ASC LIMIT ?",
            (limit,)
        ) as cursor:
            rows = await cursor.fetchall()
            return [dict(r) for r in rows]

async def mark_file_unavailable(file_id: int):
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("UPDATE photos SET status = 'unavailable' WHERE id = ?", (file_id,))
        await db.commit()

async def get_history_by_session(session_id: str, offset: int = 0, limit: int = 15) -> List[Dict[str, Any]]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        query = """
            SELECT b.batch_code, b.created_at, b.expires_at,
                   (SELECT COUNT(*) FROM photos p WHERE p.batch_id = b.id AND p.status != 'unavailable') as file_count,
                   (SELECT filename FROM photos p WHERE p.batch_id = b.id AND p.status != 'unavailable' LIMIT 1) as first_filename,
                   (SELECT file_type FROM photos p WHERE p.batch_id = b.id AND p.status != 'unavailable' LIMIT 1) as first_file_type
            FROM batches b
            WHERE b.session_id = ?
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        """
        async with db.execute(query, (session_id, limit, offset)) as cursor:
            rows = await cursor.fetchall()
            return [dict(r) for r in rows]

async def cleanup_expired():
    now_str = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute(
            "SELECT id, local_path, filename FROM photos WHERE expires_at < ? AND status != 'unavailable'",
            (now_str,)
        ) as cursor:
            expired = await cursor.fetchall()
        
        await db.execute(
            "UPDATE photos SET status = 'unavailable' WHERE expires_at < ? AND status != 'unavailable'",
            (now_str,)
        )
        await db.commit()
        return [dict(e) for e in expired]
