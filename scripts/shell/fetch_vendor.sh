#!/bin/bash
# 下載前端第三方套件（jQuery、ECharts）到 public/assets/vendor/（內網主機可先在外網電腦執行再複製過去）
set -e
. "$(dirname "$0")/_common.sh"

VENDOR="$AISLE_ROOT/public/assets/vendor"
mkdir -p "$VENDOR"

# jQuery 1.12.4：仍支援舊版 IE，與專案的 jQuery 寫法相容
curl -fSL -o "$VENDOR/jquery-1.12.4.min.js" https://code.jquery.com/jquery-1.12.4.min.js
info "已下載 $VENDOR/jquery-1.12.4.min.js"

# ECharts 5.4.3：chart_demo 圖表頁使用；檔案存在時頁面優先用本機的，否則走 CDN
curl -fSL -o "$VENDOR/echarts-5.4.3.min.js" https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js
info "已下載 $VENDOR/echarts-5.4.3.min.js"
