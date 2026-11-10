-- =====================================================================
-- 機台借機與 PM 排程看板（modules/tool_board）
-- 伺服器手動執行一次：mysql -u <帳號> -p aisle < sql/migrations/20261111_tool_board.sql
-- 全部是 CREATE TABLE IF NOT EXISTS，重複執行不會影響既有資料
-- 機台清單、類別、WIP 處理方式都在 config/tool_board.ini 與 config/tool_board/<機型>.ini，不建表
-- =====================================================================

-- ---------------------------------------------------------------------
-- Action 主表
--   model / tool_id : 機型代碼（PAN / PAP / POV）與機台
--   category        : 類別代碼（[category.<代碼>]）
--   wip_mode        : WIP 處理方式代碼（該機型檔的 [wip.<代碼>]）
--   start_at/end_at : Action 本體的起訖
--   occupied_end    : end_at + Monitor 時數 + Pilot 時數（尾巴結束），重疊檢查與查詢用
--   version         : 樂觀鎖。每次修改 +1，存檔時帶回讀取當下的 version，不一致回 409
--   刪除採軟刪除（deleted_at）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tool_board_actions` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `model`         VARCHAR(16)   NOT NULL COMMENT '機型代碼',
  `tool_id`       VARCHAR(64)   NOT NULL COMMENT '機台',
  `category`      VARCHAR(32)   NOT NULL COMMENT 'Action 類別代碼',
  `wip_mode`      VARCHAR(32)   NOT NULL COMMENT 'WIP 處理方式代碼',
  `start_at`      DATETIME      NOT NULL,
  `end_at`        DATETIME      NOT NULL,
  `need_monitor`  TINYINT(1)    NOT NULL DEFAULT 0,
  `monitor_hours` DECIMAL(5,1)  NULL DEFAULT NULL,
  `need_pilot`    TINYINT(1)    NOT NULL DEFAULT 0,
  `pilot_hours`   DECIMAL(5,1)  NULL DEFAULT NULL,
  `occupied_end`  DATETIME      NOT NULL COMMENT '含 Monitor / Pilot 尾巴的結束時間',
  `remark`        VARCHAR(500)  NOT NULL DEFAULT '',
  `pm_type`       VARCHAR(64)   NULL DEFAULT NULL COMMENT '由 PM 射程建立時的 PM 類型',
  `version`       INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT '樂觀鎖版本',
  `created_by`    INT UNSIGNED  NULL DEFAULT NULL,
  `updated_by`    INT UNSIGNED  NULL DEFAULT NULL,
  `deleted_by`    INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL,
  -- 由程式寫入，輪詢同步依此判斷變動
  `updated_at`    DATETIME      NOT NULL,
  `deleted_at`    DATETIME      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tba_tool_time` (`model`, `tool_id`, `start_at`, `occupied_end`),
  KEY `idx_tba_window` (`model`, `deleted_at`, `start_at`),
  KEY `idx_tba_updated` (`model`, `updated_at`),
  CONSTRAINT `fk_tba_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tba_updated_by` FOREIGN KEY (`updated_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tba_deleted_by` FOREIGN KEY (`deleted_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='借機 / PM 排程看板 Action';

-- ---------------------------------------------------------------------
-- 機台鎖定列：存檔時先 SELECT ... FOR UPDATE 這一列再檢查重疊，
-- 兩個人同時把不同 Action 排進同一台的同一段時間時，後到的會等前一個交易完成再檢查
-- 第一次寫入某台機台時自動建立
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tool_board_locks` (
  `model`   VARCHAR(16) NOT NULL,
  `tool_id` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`model`, `tool_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='借機看板機台鎖定列';

-- ---------------------------------------------------------------------
-- 異動紀錄：新增 / 修改 / 拖曳 / 刪除，detail 是改前改後（JSON）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tool_board_action_log` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `action_id`  INT UNSIGNED  NOT NULL,
  `user_id`    INT UNSIGNED  NULL DEFAULT NULL,
  `op`         VARCHAR(16)   NOT NULL COMMENT 'create / update / move / delete',
  `detail`     TEXT          NOT NULL,
  `created_at` DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tbl_action` (`action_id`, `id`),
  CONSTRAINT `fk_tbl_action` FOREIGN KEY (`action_id`)
    REFERENCES `tool_board_actions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tbl_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='借機看板異動紀錄';

-- ---------------------------------------------------------------------
-- 線上 / 編輯中：每次輪詢更新，超過 presence_seconds 沒更新視為離開
--   action_id 為 NULL = 只是在看；mode = view / edit / drag
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tool_board_presence` (
  `user_id`   INT UNSIGNED  NOT NULL,
  `model`     VARCHAR(16)   NOT NULL,
  `action_id` INT UNSIGNED  NULL DEFAULT NULL,
  `mode`      VARCHAR(8)    NOT NULL DEFAULT 'view',
  `seen_at`   DATETIME      NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_tbp_model` (`model`, `seen_at`),
  CONSTRAINT `fk_tbp_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='借機看板線上 / 編輯中';
