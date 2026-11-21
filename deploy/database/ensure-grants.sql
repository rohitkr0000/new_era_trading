-- Re-asserts the website user's rights (does NOT touch its password). Safe to run any time; start-backend.bat runs it on every start
-- so the local database can never come up with a website user that is locked out of its own database.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON `neweratrading`.* TO 'neweratrading_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON `neweratrading`.* TO 'neweratrading_app'@'127.0.0.1';
FLUSH PRIVILEGES;
