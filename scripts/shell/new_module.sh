#!/bin/bash
# 由樣板建立新的功能模組，並登記到 config.ini（[page.xxx] + [access] 清單）
# 用法：scripts/shell/new_module.sh <頁面代號> <身份 例 EE 或 EE,PE> "<選單標題>" [排序]
# 例：  scripts/shell/new_module.sh ee_alarm EE "警報中心 // ALARMS" 35
#
# 只是要加一般 PHP 頁面或既有的獨立頁面時不需要這個腳本，直接編輯 config.ini 即可
set -e
. "$(dirname "$0")/_common.sh"

KEY="$1"
ROLES="$2"
TITLE="$3"
ORDER="${4:-50}"

[ -n "$KEY" ] && [ -n "$ROLES" ] && [ -n "$TITLE" ] || die "用法：$0 <頁面代號> <身份 EE|PE|EE,PE> \"<標題>\" [排序]"
echo "$KEY" | grep -Eq '^[a-z][a-z0-9_]{0,63}$' || die "頁面代號只能用小寫英文、數字、底線，且以英文開頭"

DEST="$AISLE_ROOT/modules/$KEY"
[ ! -e "$DEST" ] || die "modules/$KEY 已存在"
grep -q "^\[page\.$KEY\]" "$CONFIG_INI" && die "config.ini 已有 [page.$KEY]"

# 身份必須都存在於 [roles]
for ROLE in $(echo "$ROLES" | tr ',' ' '); do
    [ -n "$(ini_get "$CONFIG_INI" roles "$ROLE")" ] || die "身份 $ROLE 不在 config.ini [roles] 中"
done

# ee_alarm -> EeAlarmController
CLASS=$(echo "$KEY" | awk -F '_' '{ for (i = 1; i <= NF; i++) printf "%s%s", toupper(substr($i,1,1)), substr($i,2) } END { print "Controller" }')

cp -R "$AISLE_ROOT/modules/_template" "$DEST"
for f in "$DEST/controller.php" "$DEST/views/index.php"; do
    sed -e "s/__CLASS__NAME__/$CLASS/g" -e "s/__MODULE__/$KEY/g" "$f" > "$f.tmp" && mv "$f.tmp" "$f"
done
sed -e '/new_module.sh/d' -e '/會被腳本取代/d' "$DEST/controller.php" > "$DEST/controller.php.tmp" && mv "$DEST/controller.php.tmp" "$DEST/controller.php"

# 把頁面代號加到 [access] 中對應身份的清單尾端
awk -v roles=",$ROLES," -v key="$KEY" '
    /^[ \t]*\[/ { in_access = ($0 ~ /^[ \t]*\[access\][ \t]*$/) }
    in_access && /^[ \t]*[A-Za-z0-9_]+[ \t]*=/ {
        role = $0; sub(/[ \t]*=.*/, "", role); gsub(/[ \t]/, "", role)
        if (index(roles, "," role ",") > 0) {
            if ($0 ~ /""[ \t]*$/) { sub(/""[ \t]*$/, "\"" key "\"") }
            else { sub(/"[ \t]*$/, ", " key "\"") }
        }
    }
    { print }' "$CONFIG_INI" > "$CONFIG_INI.tmp" && mv "$CONFIG_INI.tmp" "$CONFIG_INI"

# 新增 [page.xxx]（INI 區段順序不影響結果，附加在檔案最後）
cat >> "$CONFIG_INI" <<INI

[page.$KEY]
title = "$TITLE"
route = "module:$KEY"
order = $ORDER
menu  = On
icon  = ">"
INI

info "已建立 modules/$KEY ($CLASS)，可使用的身份：$ROLES"
info "網址：index.php?m=$KEY"
