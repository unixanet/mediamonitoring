-- 005_admin_roles.sql: БД nebuchadnezzar. Роли: admin (всё) и editor (добавить источник, поиск, свой пароль).
ALTER TABLE admin_users MODIFY role ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'editor';
UPDATE admin_users SET role = 'admin' WHERE role IN ('owner','viewer');
ALTER TABLE admin_users MODIFY role ENUM('admin','editor') NOT NULL DEFAULT 'editor';
