#!/bin/bash
# 下載前端第三方套件到 public/assets/vendor/（內網主機可先在外網電腦執行再複製過去）
set -e
. "$(dirname "$0")/_common.sh"

VENDOR="$AISLE_ROOT/public/assets/vendor"
mkdir -p "$VENDOR"

# jQuery 1.12.4：仍支援舊版 IE，與專案的 jQuery 寫法相容
curl -fSL -o "$VENDOR/jquery-1.12.4.min.js" https://code.jquery.com/jquery-1.12.4.min.js
info "已下載 $VENDOR/jquery-1.12.4.min.js"
