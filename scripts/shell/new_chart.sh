#!/bin/bash
# 由範本建立一張新的 chart：charts/<名稱>.ini + charts/<名稱>.php
# 用法：scripts/shell/new_chart.sh <名稱>
# 例：  scripts/shell/new_chart.sh CDM
#
# 不會修改 config/config.ini：最後會印出要貼進 config.ini 的 [page.xxx] 區段，
# 由部署者自己貼上，並在 [access] 填哪些身份看得到。
set -e
. "$(dirname "$0")/_common.sh"

NAME="$1"
[ -n "$NAME" ] || die "用法：$0 <名稱>（例 BSLr、CDM）"
echo "$NAME" | grep -Eq '^[A-Za-z][A-Za-z0-9_]{0,31}$' || die "名稱只能用英文、數字、底線，且以英文開頭（最多 32 字）"

KEY=$(echo "$NAME" | tr 'A-Z' 'a-z')
DIR="$AISLE_ROOT/charts"

# 不分大小寫檢查是否已有同名 chart（頁面代號是小寫，BSLr 與 bslr 會衝突）
for f in "$DIR"/*.ini; do
    [ -e "$f" ] || continue
    base=$(basename "$f" .ini)
    [ "$(echo "$base" | tr 'A-Z' 'a-z')" != "$KEY" ] || die "charts/$base.ini 已存在（頁面代號 $KEY）"
done
[ ! -e "$DIR/$NAME.php" ] || die "charts/$NAME.php 已存在"
grep -q "^\[page\.$KEY\]" "$CONFIG_INI" && die "config.ini 已有 [page.$KEY]"

sed -e "s/^; chart 設定檔範本：.*/; $NAME 圖表設定/" "$DIR/_template.ini" > "$DIR/$NAME.ini"
sed -e "s/__NAME__/$NAME/g" "$DIR/_template.php" > "$DIR/$NAME.php"

info "已建立 charts/$NAME.ini、charts/$NAME.php（目前 source = sample，先用範例資料顯示）"
info "接下來："
info "  1. 編輯 charts/$NAME.ini 的 [plot.xxx]"
info "  2. 編輯 charts/$NAME.php 寫 SQL，寫好把 ini 的 source 改成 php"
info "  3. 把下面的區段貼進 config/config.ini，並把 $KEY 加到 [access] 中要看得到的身份"
info "  4. python scripts/python/check_config.py 檢查"
cat <<INI

[page.$KEY]
title = "$NAME"
route = "module:charts"
group = "charts"
order = 50
menu  = On
icon  = "^"

INI
