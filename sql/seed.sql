-- =====================================================================
-- 初始資料：系統管理員
--   帳號：admin
--   密碼：Neo@Zion2026   (首次登入會強制要求改密碼)
-- 一般使用者請用 scripts/shell/create_user.sh 建立，不要手寫雜湊
-- =====================================================================
USE `aisle`;

INSERT INTO `users`
  (`account`, `password_hash`, `display_name`, `role`, `is_active`, `must_change_password`, `created_at`)
VALUES
  ('admin', '$2y$10$PO9Pdij.1sh87oyZ5nAcVuMQDcXSMBS9BWxMmOKF09aBnKXsFs66e', 'The Architect', 'ADMIN', 1, 1, NOW())
ON DUPLICATE KEY UPDATE `account` = `account`;
