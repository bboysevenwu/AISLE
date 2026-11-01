#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
chart_demo 的 Python 資料來源（Python 2.7）

config/app.ini 設定 [chart_demo] source = "script" 時，
modules/chart_demo/ChartDataSource.php 會呼叫本腳本並讀取 stdout 的 JSON。
適合資料要從其他系統、檔案或需要 Python 前處理時使用；直接查 MariaDB 用 source = "db" 即可。

用法：
  python scripts/python/chart_data.py tools
      → {"tools": ["APAN01", "APAP01", ...]}
  python scripts/python/chart_data.py series --start 2026-01-01 --end 2026-01-31 \
      --tools APAN01,APAP01 --params M1BF_Z_C1,M1BF_Z_C2
      → {"APAN01": {"Date": [...], "Data": {"M1BF_Z_C1": [...], "M1BF_Z_C2": [...]}}, ...}
        每個參數陣列與 Date 等長，沒有值的位置放 null

排序與格式整理由 PHP 端統一處理，這裡回傳順序不拘。
錯誤時請寫到 stderr 並以非 0 結束，PHP 端會顯示錯誤。

目前輸出的是範例資料；接真實資料請實作下方「★ 部署者實作區」。
"""
from __future__ import print_function

import argparse
import datetime
import json
import math
import sys
import zlib

SAMPLE_TOOLS = ['APAN%02d' % i for i in range(1, 7)] + ['APAP%02d' % i for i in range(1, 7)]


# =====================================================================
# ★ 部署者實作區：把下面兩個函式換成實際的資料來源
# ---------------------------------------------------------------------
# 例：用 MySQLdb 查 MariaDB（帳密可用 ConfigParser 讀 config/app.ini 的 [database]）
#
#   import MySQLdb
#   conn = MySQLdb.connect(host=..., user=..., passwd=..., db=..., charset='utf8')
#   cur = conn.cursor()
#   cur.execute("SELECT tool_id, DATE_FORMAT(data_time, '%%Y-%%m-%%d %%H:%%i:%%s'), param, value "
#               "FROM tool_data WHERE tool_id IN (" + ','.join(['%s'] * len(tools)) + ") "
#               "AND param IN (" + ','.join(['%s'] * len(params)) + ") "
#               "AND data_time >= %s AND data_time < DATE_ADD(%s, INTERVAL 1 DAY)",
#               tools + params + [start, end])
#   return pivot(cur.fetchall())
#
# 一律使用參數化查詢（%s），不要把參數串進 SQL 字串
# =====================================================================

def fetch_tools():
    # TODO(部署者)：回傳全部機台代號的 list
    return list(SAMPLE_TOOLS)


def fetch_series(tools, params, start, end):
    # TODO(部署者)：回傳 {tool: {"Date": [...], "Data": {param: [...]}}}，含 start 與 end 兩天
    out = {}
    for tool in tools:
        out[tool] = {'Date': [], 'Data': dict((p, []) for p in params)}
    day = start
    while day <= end:
        for tool in tools:
            if sample_down(tool, day):
                continue
            out[tool]['Date'].append(day.strftime('%Y-%m-%d'))
            for p in params:
                out[tool]['Data'][p].append(sample_value(tool, p, day))
        day += datetime.timedelta(days=1)
    return out


def pivot(rows):
    """(tool, date, param, value) 的資料列整理成輸出格式；同一台同一時間的參數合併，缺的補 None"""
    tmp = {}
    names = {}
    for tool, date, param, value in rows:
        tmp.setdefault(tool, {}).setdefault(str(date), {})[param] = None if value is None else float(value)
        names.setdefault(tool, set()).add(param)
    out = {}
    for tool, by_date in tmp.items():
        dates = sorted(by_date)
        out[tool] = {
            'Date': dates,
            'Data': dict((p, [by_date[d].get(p) for d in dates]) for p in names[tool]),
        }
    return out


# =====================================================================
# 範例資料（與 PHP 端 sample 模式相同的固定演算法）
# =====================================================================

def crc(s):
    return zlib.crc32(s.encode('utf-8')) & 0xffffffff


def sample_down(tool, day):
    """約 1/9 的日子整台沒有資料（模擬停機）"""
    return crc('%s|%s' % (tool, day.strftime('%Y-%m-%d'))) % 9 == 0


def sample_value(tool, param, day):
    h = crc('%s|%s|%s' % (tool, param, day.strftime('%Y-%m-%d')))
    seed = crc(tool)
    # 同一台的參數走勢相近，各參數再加一點固定偏移
    base = 1.2 + (seed % 300) / 100.0 + (crc(param) % 60) / 100.0 - 0.3
    n = (day - datetime.date(1970, 1, 1)).days
    wave = 0.6 * math.sin(float(n) / (5 + seed % 7) + seed % 10)
    noise = ((h % 1000) / 1000.0 - 0.5) * 0.5
    return round(max(0.0, base + wave + noise), 3)


# =====================================================================

def parse_date(s):
    try:
        return datetime.datetime.strptime(s, '%Y-%m-%d').date()
    except ValueError:
        raise argparse.ArgumentTypeError('日期格式需為 YYYY-MM-DD：%s' % s)


def parse_tools(s):
    return [t.strip() for t in s.split(',') if t.strip()]


def main():
    parser = argparse.ArgumentParser(description='chart_demo 資料來源')
    sub = parser.add_subparsers(dest='cmd')
    sub.add_parser('tools')
    p = sub.add_parser('series')
    p.add_argument('--start', type=parse_date, required=True)
    p.add_argument('--end', type=parse_date, required=True)
    p.add_argument('--tools', type=parse_tools, required=True)
    p.add_argument('--params', type=parse_tools, required=True)
    args = parser.parse_args()

    if args.cmd == 'tools':
        result = {'tools': fetch_tools()}
    elif args.cmd == 'series':
        result = fetch_series(args.tools, args.params, args.start, args.end)
    else:
        parser.print_help(sys.stderr)
        return 2

    print(json.dumps(result, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    sys.exit(main())
