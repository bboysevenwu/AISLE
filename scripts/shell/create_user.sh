#!/bin/bash
# 建立使用者（密碼以互動方式輸入，不會留在 shell history）
# 用法：scripts/shell/create_user.sh <帳號> <身份 EE|PE|ADMIN> [顯示名稱]
set -e
. "$(dirname "$0")/_common.sh"

ACCOUNT="$1"
ROLE="$2"
NAME="${3:-$1}"

[ -n "$ACCOUNT" ] && [ -n "$ROLE" ] || die "用法：$0 <帳號> <身份> [顯示名稱]"
echo "$ACCOUNT" | grep -Eq '^[A-Za-z0-9_.-]{3,64}$' || die "帳號只能是 3~64 字元英數字、底線、點、減號"

# 身份必須存在於 config.ini 的 [roles]
[ -n "$(ini_get "$CONFIG_INI" roles "$ROLE")" ] || die "身份 $ROLE 不在 config.ini [roles] 中"

read -r -s -p "密碼: " PW1; echo
read -r -s -p "再輸入一次: " PW2; echo
[ "$PW1" = "$PW2" ] || die "兩次密碼不一致"
[ ${#PW1} -ge 8 ] || die "密碼至少 8 碼"

# 用專案的 Password 類別雜湊（與網頁端完全一致），密碼經由 stdin 傳入
HASH=$(printf '%s' "$PW1" | php -r '
    define("AISLE_ROOT", $argv[1]);
    require AISLE_ROOT . "/core/helpers.php";
    require AISLE_ROOT . "/core/Config.php";
    require AISLE_ROOT . "/core/Security.php";
    require AISLE_ROOT . "/core/password_compat.php";
    require AISLE_ROOT . "/core/Password.php";
    echo Password::hash(stream_get_contents(STDIN), (int) $argv[2]);
' "$AISLE_ROOT" "$(ini_get "$APP_INI" security bcrypt_cost)")
echo "$HASH" | grep -Eq '^\$2y\$[0-9]{2}\$.{53}$' || die "雜湊失敗"

# SQL 字串跳脫（帳號已驗證格式；顯示名稱需處理 \ 與 '）
NAME_SQL=$(printf '%s' "$NAME" | sed -e "s/\\\\/\\\\\\\\/g" -e "s/'/''/g")

MYCNF=$(make_mycnf)
trap 'rm -f "$MYCNF"' EXIT

mysql --defaults-extra-file="$MYCNF" "$(db_name)" <<SQL
INSERT INTO users (account, password_hash, display_name, role, must_change_password, created_at)
VALUES ('$ACCOUNT', '$HASH', '$NAME_SQL', '$ROLE', 1, NOW());
SQL

info "已建立 $ACCOUNT ($ROLE)，首次登入需修改密碼"
