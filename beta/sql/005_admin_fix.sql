-- 005_admin_fix.sql: БД nebuchadnezzar. Роли admin/editor (для БД, созданных по старому 001). Безопасно запускать повторно.
ALTER TABLE admin_users MODIFY role ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'editor';
UPDATE admin_users SET role = 'admin' WHERE role IN ('owner','viewer');
ALTER TABLE admin_users MODIFY role ENUM('admin','editor') NOT NULL DEFAULT 'editor';
