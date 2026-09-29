-- A2A demosu: iki sitenin veritabanları.
CREATE DATABASE IF NOT EXISTS dagitici CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS fabrika CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON dagitici.* TO 'demo'@'%';
GRANT ALL PRIVILEGES ON fabrika.* TO 'demo'@'%';
FLUSH PRIVILEGES;
