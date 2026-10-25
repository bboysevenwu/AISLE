#!/bin/bash
# 共用函式，由其他腳本 source 使用

AISLE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_INI="$AISLE_ROOT/config/app.ini"
CONFIG_INI="$AISLE_ROOT/config/config.ini"

die() { echo "[ERROR] $*" >&2; exit 1; }
info() { echo "[AISLE] $*"; }

# ini_get <檔案> <區段> <鍵>：讀取 INI 值並去除前後引號
ini_get() {
    awk -F '=' -v section="[$2]" -v key="$3" '
        /^[ \t]*\[/ { gsub(/[ \t]/, "", $0); in_section = ($0 == section); next }
        in_section {
            k = $1; gsub(/^[ \t]+|[ \t]+$/, "", k)
            if (k == key) {
                v = substr($0, index($0, "=") + 1)
                gsub(/^[ \t]+|[ \t]+$/, "", v)
                gsub(/^"|"$/, "", v)
                print v; exit
            }
        }' "$1"
}

# 產生暫存 mysql 設定檔，避免密碼出現在指令列 (ps 看得到)
# 用法：MYCNF=$(make_mycnf); mysql --defaults-extra-file="$MYCNF" ...
make_mycnf() {
    [ -f "$APP_INI" ] || die "找不到 $APP_INI"
    local f
    f="$(mktemp)"
    chmod 600 "$f"
    {
        echo "[client]"
        echo "host=$(ini_get "$APP_INI" database host)"
        echo "port=$(ini_get "$APP_INI" database port)"
        echo "user=$(ini_get "$APP_INI" database user)"
        echo "password=$(ini_get "$APP_INI" database password)"
        echo "default-character-set=$(ini_get "$APP_INI" database charset)"
    } > "$f"
    echo "$f"
}

db_name() { ini_get "$APP_INI" database name; }
