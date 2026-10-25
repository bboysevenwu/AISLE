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
-- AISLE Pending Issue Summary（modules/pending_issue）
--   category : hl / tool_select / aging_parts / other
--              (產品HL / 選機表 / aging parts / 其他，顯示名稱定義在 controller)
--   status   : open / pending / close
--   version  : 樂觀鎖。每次修改 +1，存檔時帶回讀取當下的 version，
--              不一致代表別人已先改過，程式會拒絕覆蓋並提示重新載入
--   刪除採軟刪除 (deleted_at)，誤刪時可由 DB 還原；圖片檔案同時保留
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_issues` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tool_id`      VARCHAR(64)   NOT NULL COMMENT '機台 ToolID',
  `category`     VARCHAR(16)   NOT NULL COMMENT 'hl / tool_select / aging_parts / other',
  `title`        VARCHAR(200)  NOT NULL,
  `description`  TEXT          NULL,
  `status`       VARCHAR(16)   NOT NULL DEFAULT 'open' COMMENT 'open / pending / close',
  `sponsor`      VARCHAR(100)  NOT NULL DEFAULT '',
  `owner`        VARCHAR(100)  NOT NULL DEFAULT '' COMMENT '現在處理者',
  `remark`       TEXT          NULL,
  `occurred_on`  DATE          NOT NULL COMMENT '發生日期',
  `closed_at`    DATETIME      NULL DEFAULT NULL,
  `version`      INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT '樂觀鎖版本',
  `created_by`   INT UNSIGNED  NULL DEFAULT NULL,
  `updated_by`   INT UNSIGNED  NULL DEFAULT NULL,
  `deleted_by`   INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL,
  `updated_at`   DATETIME      NOT NULL COMMENT '由程式寫入，輪詢比對用',
  `deleted_at`   DATETIME      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pi_tool` (`tool_id`),
  KEY `idx_pi_filter` (`deleted_at`, `category`, `status`),
  KEY `idx_pi_occurred` (`occurred_on`),
  KEY `idx_pi_updated` (`updated_at`),
  CONSTRAINT `fk_pi_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_updated_by` FOREIGN KEY (`updated_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_deleted_by` FOREIGN KEY (`deleted_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pending Issue';

-- ---------------------------------------------------------------------
-- Issue 圖片：檔案存在 storage/uploads/pending_issue/<年月>/，這裡只記路徑
--   貼上圖片時先上傳（issue_id 為 NULL），存檔時才綁定到 issue；
--   超過一天仍未綁定的暫存圖會在下次上傳時清除
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_issue_images` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `issue_id`      INT UNSIGNED  NULL DEFAULT NULL,
  `file_path`     VARCHAR(191)  NOT NULL COMMENT '相對於 storage/uploads/',
  `original_name` VARCHAR(191)  NOT NULL DEFAULT '',
  `mime`          VARCHAR(32)   NOT NULL,
  `size`          INT UNSIGNED  NOT NULL,
  `width`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `height`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by`   INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pii_issue` (`issue_id`),
  KEY `idx_pii_orphan` (`issue_id`, `created_at`),
  CONSTRAINT `fk_pii_issue` FOREIGN KEY (`issue_id`)
    REFERENCES `pending_issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pii_user` FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pending Issue 圖片';

-- ---------------------------------------------------------------------
-- 誰正在編輯哪一筆（頁面輪詢時更新，超過 30 秒沒更新視為離開）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_issue_presence` (
  `issue_id`     INT UNSIGNED  NOT NULL,
  `user_id`      INT UNSIGNED  NOT NULL,
  `seen_at`      DATETIME      NOT NULL,
  PRIMARY KEY (`issue_id`, `user_id`),
  KEY `idx_pip_seen` (`seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pending Issue 編輯中狀態';

-- ---------------------------------------------------------------------
-- 建立應用程式專用帳號 (依實際環境修改密碼與來源主機後再執行)
-- ---------------------------------------------------------------------
-- CREATE USER 'aisle_app'@'localhost' IDENTIFIED BY '請改成強密碼';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `aisle`.* TO 'aisle_app'@'localhost';
-- FLUSH PRIVILEGES;
