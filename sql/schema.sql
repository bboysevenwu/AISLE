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

-- =====================================================================
-- Pending Issue Control Table（與 sql/migrations/20261110_pending_issue.sql 相同）
-- =====================================================================
-- ---------------------------------------------------------------------
-- Issue 主表
--   model     : 機型代碼（config/pending_issue.ini 的 [models]，例 PAN / PAP / POV）
--   category  : 分類代碼（[categories]，例 hl / tool_select / aging_parts / others）
--   status    : open / monitor / close（[status]）
--   sponsor_id: AISLE 使用者
--   version   : 樂觀鎖。每次修改 +1，存檔時帶回讀取當下的 version，不一致回 409
--   刪除採軟刪除（deleted_at），只有 ADMIN 能看到與還原
--   代碼刻意不用 ENUM：新增機型 / 分類只改 ini，不必 ALTER TABLE
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_issues` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tool_id`           VARCHAR(64)   NOT NULL COMMENT '機台代號',
  `model`             VARCHAR(16)   NOT NULL COMMENT '機型代碼',
  `category`          VARCHAR(16)   NOT NULL COMMENT '分類代碼',
  `issue`             TEXT          NOT NULL COMMENT 'Issue 描述',
  `initial_date`      DATE          NOT NULL,
  `status`            VARCHAR(16)   NOT NULL DEFAULT 'open' COMMENT 'open / monitor / close',
  `status_changed_by` INT UNSIGNED  NULL DEFAULT NULL COMMENT '最後改狀態的使用者',
  `status_changed_at` DATETIME      NULL DEFAULT NULL,
  `sponsor_id`        INT UNSIGNED  NOT NULL COMMENT 'Sponsor（AISLE 使用者）',
  `checkpoint_date`   DATE          NULL DEFAULT NULL,
  `remark`            TEXT          NULL,
  `closed_at`         DATETIME      NULL DEFAULT NULL,
  `version`           INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT '樂觀鎖版本',
  `created_by`        INT UNSIGNED  NULL DEFAULT NULL,
  `updated_by`        INT UNSIGNED  NULL DEFAULT NULL,
  `deleted_by`        INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL,
  -- 由程式寫入（MariaDB 5.5 一張表只能有一個自動更新的 TIMESTAMP），輪詢同步依此判斷變動
  `updated_at`        DATETIME      NOT NULL,
  `deleted_at`        DATETIME      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pi_filter` (`deleted_at`, `model`, `category`, `status`),
  KEY `idx_pi_tool` (`tool_id`),
  KEY `idx_pi_sponsor` (`sponsor_id`),
  KEY `idx_pi_updated` (`updated_at`, `id`),
  KEY `idx_pi_checkpoint` (`checkpoint_date`),
  CONSTRAINT `fk_pi_sponsor` FOREIGN KEY (`sponsor_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_status_by` FOREIGN KEY (`status_changed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_updated_by` FOREIGN KEY (`updated_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_deleted_by` FOREIGN KEY (`deleted_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pending Issue Control Table';

-- ---------------------------------------------------------------------
-- 狀態變更紀錄：新增時記 NULL → open，之後每次狀態改變記一筆（誰、何時）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_issue_status_logs` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `issue_id`     INT UNSIGNED  NOT NULL,
  `from_status`  VARCHAR(16)   NULL DEFAULT NULL,
  `to_status`    VARCHAR(16)   NOT NULL,
  `changed_by`   INT UNSIGNED  NULL DEFAULT NULL,
  `changed_at`   DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pisl_issue` (`issue_id`, `changed_at`),
  CONSTRAINT `fk_pisl_issue` FOREIGN KEY (`issue_id`)
    REFERENCES `pending_issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pisl_user` FOREIGN KEY (`changed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pending Issue 狀態變更紀錄';

-- ---------------------------------------------------------------------
-- Issue 圖片：檔案存在 storage/uploads/pending_issue/<年月>/，這裡只記路徑
--   貼上 / 拖曳 / 選檔時先上傳（issue_id 為 NULL），按儲存才綁定到 issue；
--   超過一天仍未綁定的暫存圖，會在下次有人上傳時清除
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_issue_images` (
  `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `issue_id`      INT UNSIGNED      NULL DEFAULT NULL,
  `file_path`     VARCHAR(191)      NOT NULL COMMENT '相對於 storage/uploads/',
  `original_name` VARCHAR(191)      NOT NULL DEFAULT '',
  `mime`          VARCHAR(32)       NOT NULL,
  `size`          INT UNSIGNED      NOT NULL,
  `width`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `height`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by`   INT UNSIGNED      NULL DEFAULT NULL,
  `created_at`    DATETIME          NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pii_issue` (`issue_id`, `created_at`),
  CONSTRAINT `fk_pii_issue` FOREIGN KEY (`issue_id`)
    REFERENCES `pending_issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pii_user` FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pending Issue 圖片';

-- ---------------------------------------------------------------------
-- 誰正在編輯哪一筆（編輯視窗開著時隨輪詢更新，超過 presence_seconds 沒更新視為離開）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_issue_presence` (
  `issue_id`  INT UNSIGNED  NOT NULL,
  `user_id`   INT UNSIGNED  NOT NULL,
  `seen_at`   DATETIME      NOT NULL,
  PRIMARY KEY (`issue_id`, `user_id`),
  KEY `idx_pip_user` (`user_id`),
  KEY `idx_pip_seen` (`seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pending Issue 編輯中狀態';

-- ---------------------------------------------------------------------
-- 建立應用程式專用帳號 (依實際環境修改密碼與來源主機後再執行)
-- ---------------------------------------------------------------------
-- CREATE USER 'aisle_app'@'localhost' IDENTIFIED BY '請改成強密碼';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `aisle`.* TO 'aisle_app'@'localhost';
-- FLUSH PRIVILEGES;
