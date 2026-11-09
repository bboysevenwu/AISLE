-- =====================================================================
-- Pending Issue Control Table（modules/pending_issue）
-- 伺服器手動執行一次：mysql -u <帳號> -p aisle < sql/migrations/20261110_pending_issue.sql
-- 全部是 CREATE TABLE IF NOT EXISTS，重複執行不會影響既有資料
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
