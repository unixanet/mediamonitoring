"""Пул соединений MySQL (aiomysql). Время везде UTC."""
import aiomysql

from . import config


class DB:
    def __init__(self):
        self.pool = None

    async def init(self):
        c = config.DB
        conn = {"unix_socket": c["socket"]} if c.get("socket") else {"host": c["host"]}
        self.pool = await aiomysql.create_pool(
            **conn, user=c["user"], password=c["password"], db=c["name"], charset="utf8mb4",
            autocommit=True, minsize=2, maxsize=24, init_command="SET time_zone = '+00:00'")

    async def close(self):
        if self.pool:
            self.pool.close()
            await self.pool.wait_closed()

    async def all(self, sql, args=None) -> list:
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(sql, args)
                return list(await cur.fetchall())

    async def one(self, sql, args=None):
        rows = await self.all(sql, args)
        return rows[0] if rows else None

    async def val(self, sql, args=None):
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(sql, args)
                r = await cur.fetchone()
                return r[0] if r else None

    async def run(self, sql, args=None) -> tuple:
        """Выполнить запрос; вернуть (затронуто строк, последний id)."""
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(sql, args)
                return cur.rowcount, cur.lastrowid
