-- =====================================================================
--  New Era Trading — dedicated database + least-privilege user (MariaDB 10.4+ / MySQL 8)
--  Run ONCE as the database administrator after replacing the two passwords:
--      mysql -u root -p < setup-database.sql
--  Replace __APP_PASSWORD__ with a long random password (24+ characters, letters and digits) and put the same value in
--  backend/.env  (DB_USERNAME=neweratrading_app, DB_PASSWORD=...).  Never use the root account from the website.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `neweratrading` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- the website's user: can reach ONLY this database, only from the same machine
CREATE USER IF NOT EXISTS 'neweratrading_app'@'localhost' IDENTIFIED BY '__APP_PASSWORD__';
CREATE USER IF NOT EXISTS 'neweratrading_app'@'127.0.0.1' IDENTIFIED BY '__APP_PASSWORD__';
ALTER USER 'neweratrading_app'@'localhost' IDENTIFIED BY '__APP_PASSWORD__';
ALTER USER 'neweratrading_app'@'127.0.0.1' IDENTIFIED BY '__APP_PASSWORD__';

-- data + the schema changes php artisan migrate needs. NOT granted: GRANT OPTION, FILE, PROCESS, SUPER, any other database.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON `neweratrading`.* TO 'neweratrading_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON `neweratrading`.* TO 'neweratrading_app'@'127.0.0.1';

-- hardening of a fresh server: no anonymous users, no sample database
DROP USER IF EXISTS ''@'localhost';
DROP USER IF EXISTS ''@'127.0.0.1';
DROP DATABASE IF EXISTS `test`;

FLUSH PRIVILEGES;
