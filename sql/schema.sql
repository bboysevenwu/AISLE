-- =====================================================================
-- AISLE 資料庫結構 (MariaDB 5.5 / 10.x 皆可)
-- 字元集：utf8mb4；若 MariaDB < 5.5.3 不支援 utf8mb4，請全文取代為 utf8
-- 注意：InnoDB 舊版索引上限 767 bytes，utf8mb4 的 VARCHAR 索引欄位請 <= 191
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `aisle`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `aisle`;

-- ---------------------------------------------------------------------
-- 使用者
--   password_hash : bcrypt 雜湊 ($2y$...，固定 60 字元)，
--                   保留 255 長度以便日後換演算法
--   role          : 對應 config/permissions.ini 的 [roles] (EE / PE / ADMIN)
--                   刻意不用 ENUM，新增角色時只改 INI 不必 ALTER TABLE
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `account`              VARCHAR(64)       NOT NULL COMMENT '登入帳號',
  `password_hash`        VARCHAR(255)      NOT NULL COMMENT 'bcrypt 雜湊',
  `display_name`         VARCHAR(100)      NOT NULL DEFAULT '',
  `email`                VARCHAR(191)      NULL DEFAULT NULL,
  `role`                 VARCHAR(16)       NOT NULL DEFAULT 'EE' COMMENT 'EE / PE / ADMIN',
  `is_active`            TINYINT(1)        NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1)        NOT NULL DEFAULT 0,
  `failed_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '連續登入失敗次數',
  `locked_until`         DATETIME          NULL DEFAULT NULL COMMENT '鎖定到期時間',
  `last_login_at`        DATETIME          NULL DEFAULT NULL,
  `last_login_ip`        VARCHAR(45)       NULL DEFAULT NULL COMMENT '可容納 IPv6',
  `created_at`           DATETIME          NOT NULL,
  -- MariaDB 5.5 一張表只能有一個 TIMESTAMP 使用 CURRENT_TIMESTAMP，因此 created_at 用 DATETIME 由程式寫入
  `updated_at`           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_account` (`account`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系統使用者';

-- ---------------------------------------------------------------------
-- 登入紀錄 (成功與失敗都記錄，供稽核與暴力破解偵測)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NULL DEFAULT NULL COMMENT '帳號不存在時為 NULL',
  `account`     VARCHAR(64)     NOT NULL,
  `success`     TINYINT(1)      NOT NULL,
  `reason`      VARCHAR(32)     NOT NULL DEFAULT '' COMMENT 'ok / bad_password / no_user / locked / inactive',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at`  DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_logs_user` (`user_id`, `created_at`),
  KEY `idx_login_logs_ip` (`ip`, `created_at`),
  CONSTRAINT `fk_login_logs_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登入紀錄';

-- ---------------------------------------------------------------------
-- 操作稽核 (各模組寫入，例如：匯出報表、修改設定)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NULL DEFAULT NULL,
  `module`      VARCHAR(64)     NOT NULL,
  `action`      VARCHAR(64)     NOT NULL,
  `detail`      TEXT            NULL COMMENT 'JSON 字串',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '',
  `created_at`  DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_module` (`module`, `action`, `created_at`),
  KEY `idx_audit_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作稽核';

-- ---------------------------------------------------------------------
-- 建立應用程式專用帳號 (依實際環境修改密碼與來源主機後再執行)
-- ---------------------------------------------------------------------
-- CREATE USER 'aisle_app'@'localhost' IDENTIFIED BY '請改成強密碼';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `aisle`.* TO 'aisle_app'@'localhost';
-- FLUSH PRIVILEGES;
