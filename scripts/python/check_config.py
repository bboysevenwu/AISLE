#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
檢查 config/config.ini 是否正確（Python 2.7）

檢查項目：
  - [access] 的每個身份都定義在 [roles]，列出的頁面都有 [page.xxx]
  - 每個 [page.xxx] 的 route 指向的檔案存在
      module:<資料夾>  → modules/<資料夾>/controller.php
      pages/xxx.php    → 檔案存在
      public/xxx.php   → 檔案存在，且第一段程式有 require auth_check.php
  - action.<動作> 的身份都有效
  - 頁面的 group 有對應的 [group.xxx]，[group.xxx] 有被頁面使用（警告）
  - [register] 的 listed_role / unlisted_role 有效且不是超級身份 ("*")
  - [ee_accounts] 名單帳號格式正確、沒有重複
  - 沒有任何身份能使用的頁面、modules/ 下沒登記的資料夾（警告）
  - 圖表（route = module:charts 的頁面）：charts/<名稱>.ini 存在且格式正確、
    source = php 時 charts/<名稱>.php 存在且有 <名稱>Chart 類別、
    charts/ 底下沒有頁面使用的 chart（警告）
  - 列出每個身份可用的頁面

用法：python scripts/python/check_config.py
回傳碼：0 = 正常，1 = 有錯誤
"""
from __future__ import print_function

import os
import re
import sys
from ConfigParser import RawConfigParser  # Python 2.7；RawConfigParser 不會把 % 當成變數

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
INI = os.path.join(ROOT, 'config', 'config.ini')
MODULES = os.path.join(ROOT, 'modules')
CHARTS = os.path.join(ROOT, 'charts')
RE_CHART = re.compile(r'^[A-Za-z][A-Za-z0-9_]{0,31}$')
RE_PLOT = re.compile(r'^plot\.[a-z][a-z0-9_]{0,63}$')
RE_KEY = re.compile(r'^[a-z][a-z0-9_]{0,63}$')
RE_ACCOUNT = re.compile(r'^[A-Za-z0-9_.-]{3,64}$')

GREEN = '\033[32m'
RED = '\033[31m'
YELLOW = '\033[33m'
RESET = '\033[0m'


def unquote(value):
    value = value.strip()
    if not value.startswith('"'):
        value = value.split(';', 1)[0].strip()
    if len(value) >= 2 and value[0] == '"' and value[-1] == '"':
        value = value[1:-1]
    return value


def split_list(value):
    return [v.strip() for v in unquote(value).split(',') if v.strip()]


def read_array(section_name, key):
    """account[] 是 PHP 陣列語法，ConfigParser 遇到重複 key 只留最後一個，所以逐行讀"""
    values = []
    section = None
    pattern = re.compile(r'^' + re.escape(key) + r'\[\]\s*=')
    with open(INI) as fp:
        for line in fp:
            line = line.strip()
            if line.startswith('['):
                section = line.strip('[]')
            elif section == section_name and pattern.match(line):
                values.append(unquote(line.split('=', 1)[1]))
    return values


def chart_names():
    """charts/ 底下的 chart 名稱（不含 _ 開頭的範本）"""
    if not os.path.isdir(CHARTS):
        return []
    return sorted(f[:-4] for f in os.listdir(CHARTS) if f.endswith('.ini') and RE_CHART.match(f[:-4]))


def check_chart(name, errors, warnings):
    """檢查一張 chart 的 ini 與 php"""
    label = 'charts/%s.ini' % name
    parser = RawConfigParser()
    parser.optionxform = str
    try:
        with open(os.path.join(CHARTS, name + '.ini')) as fp:
            parser.readfp(fp)
    except Exception as e:  # 語法錯誤
        errors.append('%s 解析失敗：%s' % (label, e))
        return

    def get(section, key):
        return unquote(parser.get(section, key)) if parser.has_option(section, key) else ''

    source = (get('page', 'source') or 'php').lower() if parser.has_section('page') else 'php'
    if source not in ('php', 'sample'):
        errors.append('%s [page] source 只能是 php 或 sample' % label)
    for key in ('default_days', 'max_days'):
        v = get('page', key) if parser.has_section('page') else ''
        if v and (not v.isdigit() or int(v) < 1):
            errors.append('%s [page] %s 必須是正整數' % (label, key))

    plots = 0
    for section in parser.sections():
        if section == 'page':
            continue
        if not RE_PLOT.match(section):
            errors.append('%s 區段名稱錯誤：[%s]，應為 [page] 或 [plot.<小寫代號>]' % (label, section))
            continue
        plots += 1
        if not split_list(get(section, 'params')):
            errors.append('%s [%s] 沒有設定 params' % (label, section))
        for opt in parser.options(section):
            if opt.endswith('ymin') or opt.endswith('ymax'):
                v = get(section, opt)
                if v:
                    try:
                        float(v)
                    except ValueError:
                        errors.append('%s [%s] %s 必須是數字：%s' % (label, section, opt, v))
    if plots == 0:
        errors.append('%s 沒有任何 [plot.xxx] 圖' % label)

    php = os.path.join(CHARTS, name + '.php')
    if source == 'php':
        if not os.path.isfile(php):
            errors.append('charts/%s.php 不存在（尚未寫好時可先把 ini 的 source 設為 sample）' % name)
        else:
            with open(php) as fp:
                code = fp.read()
            if not re.search(r'class\s+' + re.escape(name) + r'Chart\s+extends\s+ChartSource\b', code):
                errors.append('charts/%s.php 裡找不到 class %sChart extends ChartSource' % (name, name))
            if '尚未實作' in code:
                warnings.append('charts/%s.php 似乎還沒寫好 SQL（仍有「尚未實作」），但 ini 的 source = php' % name)


def main():
    errors = []
    warnings = []

    parser = RawConfigParser()
    parser.optionxform = str  # 保留 key 大小寫（EE / PE）
    with open(INI) as fp:
        parser.readfp(fp)

    def get(section, key):
        return unquote(parser.get(section, key)) if parser.has_option(section, key) else ''

    roles = parser.options('roles') if parser.has_section('roles') else []

    # 選單分類
    groups = set()
    for section in parser.sections():
        if section.startswith('group.'):
            key = section[len('group.'):]
            if not RE_KEY.match(key):
                errors.append('[%s] 分類代號格式錯誤' % section)
            groups.add(key)
    used_groups = set()

    # 頁面
    pages = {}
    for section in parser.sections():
        if not section.startswith('page.'):
            continue
        key = section[len('page.'):]
        if not RE_KEY.match(key):
            errors.append('[%s] 頁面代號格式錯誤' % section)
            continue
        route = get(section, 'route') or ('module:' + key)
        pages[key] = route
        if route.startswith('module:'):
            target = os.path.join(MODULES, route[len('module:'):].strip(), 'controller.php')
            if not os.path.isfile(target):
                errors.append('[%s] 找不到 %s' % (section, os.path.relpath(target, ROOT)))
        else:
            target = os.path.join(ROOT, route.lstrip('/'))
            if not os.path.isfile(target):
                errors.append('[%s] route 檔案不存在：%s' % (section, route))
            elif route.lstrip('/').startswith('public/'):
                with open(target) as fp:
                    head = fp.read(4000)
                if 'auth_check.php' not in head:
                    errors.append('[%s] 獨立頁面 %s 沒有 require core/auth_check.php，任何人都能直接開啟' % (section, route))
        group = get(section, 'group')
        if group:
            used_groups.add(group)
            if group not in groups:
                warnings.append('[%s] group "%s" 沒有對應的 [group.%s]，將以代號當分類標題' % (section, group, group))
        for opt in parser.options(section):
            if opt.startswith('action.'):
                for r in split_list(parser.get(section, opt)):
                    if r not in roles:
                        errors.append('[%s] %s 的身份 %s 未定義' % (section, opt, r))

    for g in sorted(groups - used_groups):
        warnings.append('[group.%s] 沒有任何頁面使用' % g)

    # 身份 → 頁面
    access = {}
    superusers = []
    if not parser.has_section('access'):
        errors.append('缺少 [access] 區段')
    else:
        for role in parser.options('access'):
            if role not in roles:
                errors.append('[access] 身份 %s 未定義於 [roles]' % role)
            items = split_list(parser.get('access', role))
            if '*' in items:
                superusers.append(role)
                access[role] = sorted(pages.keys())
                continue
            for p in items:
                if p not in pages:
                    errors.append('[access] %s 的頁面 %s 沒有對應的 [page.%s]' % (role, p, p))
            access[role] = [p for p in items if p in pages]
    for role in roles:
        if role not in access:
            warnings.append('身份 %s 沒有寫在 [access]，登入後看不到任何頁面' % role)

    for key in sorted(pages):
        if not any(key in access[r] for r in access if r not in superusers):
            warnings.append('頁面 %s 只有 * 身份能使用（若是刻意的可忽略）' % key)

    module_dirs = set(r[len('module:'):].strip() for r in pages.values() if r.startswith('module:'))
    for name in sorted(os.listdir(MODULES)):
        if os.path.isdir(os.path.join(MODULES, name)) and not name.startswith('_') and name not in module_dirs:
            warnings.append('modules/%s 沒有登記在 config.ini，目前無法存取' % name)

    # 圖表
    names = chart_names()
    lower = {}
    for n in names:
        if n.lower() in lower:
            errors.append('charts/%s.ini 與 charts/%s.ini 只差大小寫，頁面代號會衝突' % (lower[n.lower()], n))
        lower[n.lower()] = n
    chart_pages = [k for k, r in pages.items() if r == 'module:charts']
    for key in sorted(chart_pages):
        if key not in lower:
            errors.append('[page.%s] route = module:charts，但 charts/ 裡沒有同名（不分大小寫）的 ini' % key)
        else:
            check_chart(lower[key], errors, warnings)
    for n in names:
        if n.lower() not in chart_pages:
            warnings.append('charts/%s.ini 沒有對應的 [page.%s]（route = "module:charts"），目前無法存取' % (n, n.lower()))

    # 註冊
    if parser.has_section('register'):
        for key in ('listed_role', 'unlisted_role'):
            role = get('register', key)
            if key == 'unlisted_role' and role == '':
                continue
            if role not in roles:
                errors.append('[register] %s "%s" 未定義於 [roles]' % (key, role))
            elif role in superusers:
                errors.append('[register] %s 不可設為超級身份 %s' % (key, role))
    seen = set()
    for account in read_array('ee_accounts', 'account'):
        if not RE_ACCOUNT.match(account):
            errors.append('[ee_accounts] 帳號格式錯誤：%s' % account)
        if account.lower() in seen:
            warnings.append('[ee_accounts] 帳號重複：%s' % account)
        seen.add(account.lower())

    print('== 身份可用頁面 ==')
    for role in roles:
        note = ' (*)' if role in superusers else ''
        print('  %-6s%s: %s' % (role, note, ', '.join(access.get(role, [])) or '-'))
    print('== EE 名單 (%d) ==' % len(seen))
    print('  ' + (', '.join(sorted(seen)) or '-'))
    print()
    for w in warnings:
        print(YELLOW + '[WARN]  ' + w + RESET)
    for e in errors:
        print(RED + '[ERROR] ' + e + RESET)
    if not errors:
        print(GREEN + '[OK] config.ini 檢查通過' + RESET)
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
