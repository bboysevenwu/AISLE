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
  - 借機 / PM 看板（有 [page.tool_board] 時）：config/tool_board.ini 與每個機型的
    config/tool_board/<機型>.ini 存在且格式正確、顏色是 Hex、機台沒有重複列在多個機型、
    每個機型至少一個 [wip.*]、[editors] 帳號是否在 [ee_accounts]（警告）、
    可用率公式檔存在、PM JSON 檔可解析（單引號格式只警告）
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


def read_array(section_name, key, path=None):
    """account[] 是 PHP 陣列語法，ConfigParser 遇到重複 key 只留最後一個，所以逐行讀"""
    values = []
    section = None
    pattern = re.compile(r'^' + re.escape(key) + r'\[\]\s*=')
    with open(path or INI) as fp:
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


DB_KEYS = ('ip', 'port', 'name', 'user', 'password', 'charset')
SPEC_KEYS = ('upper_oos', 'upper_ooc', 'lower_ooc', 'lower_oos')


def check_chart_db(label, parser, get, errors):
    """[db]：只能有 DB_KEYS；port 為整數；ip 留空時 name / user / password 也要留空"""
    for opt in parser.options('db'):
        if opt not in DB_KEYS:
            errors.append('%s [db] 不認得的欄位：%s（可用：%s）' % (label, opt, '、'.join(DB_KEYS)))
    ip = get('db', 'ip')
    if ip and not re.match(r'^[A-Za-z0-9._:-]+$', ip):
        errors.append('%s [db] ip 格式錯誤（只能是 IP 或主機名稱）' % label)
    port = get('db', 'port')
    if port and (not port.isdigit() or not 1 <= int(port) <= 65535):
        errors.append('%s [db] port 必須是 1 ~ 65535 的整數' % label)
    for key in ('name', 'charset'):
        v = get('db', key)
        if v and not re.match(r'^[A-Za-z0-9_$-]+$', v):
            errors.append('%s [db] %s 只能有英文、數字、底線、$、-' % (label, key))
    if not ip:
        for key in ('name', 'user', 'password'):
            if get('db', key):
                errors.append('%s [db] 有填 %s 但沒有填 ip（ip 留空 = 使用 AISLE 自己的資料庫）' % (label, key))


def check_spec(label, section, get, errors):
    """spec 線：數字或留空；lower_oos <= lower_ooc < upper_ooc <= upper_oos"""
    vals = {}
    for key in SPEC_KEYS:
        v = get(section, key)
        if not v:
            continue
        try:
            vals[key] = float(v)
        except ValueError:
            errors.append('%s [%s] %s 必須是數字或留空：%s' % (label, section, key, v))
    for i, a in enumerate(SPEC_KEYS):
        for b in SPEC_KEYS[i + 1:]:
            if a not in vals or b not in vals:
                continue
            same_side = a[:5] == b[:5]
            if (vals[a] < vals[b]) if same_side else (vals[a] <= vals[b]):
                errors.append('%s [%s] %s（%s）必須%s %s（%s）' % (
                    label, section, a, vals[a], '大於等於' if same_side else '大於', b, vals[b]))


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

    if parser.has_section('db'):
        check_chart_db(label, parser, get, errors)

    plots = 0
    for section in parser.sections():
        if section in ('page', 'db'):
            continue
        if not RE_PLOT.match(section):
            errors.append('%s 區段名稱錯誤：[%s]，應為 [page]、[db] 或 [plot.<小寫代號>]' % (label, section))
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
        check_spec(label, section, get, errors)
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


RE_HEX = re.compile(r'^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$')
RE_TOOL = re.compile(r'^[A-Za-z0-9][A-Za-z0-9_.\-]{0,63}$')
RE_CODE = re.compile(r'^[a-z][a-z0-9_]{0,31}$')


def check_tool_board(ee_accounts, errors, warnings):
    """借機 / PM 看板設定"""
    shared_file = os.path.join(ROOT, 'config', 'tool_board.ini')
    label = 'config/tool_board.ini'
    if not os.path.isfile(shared_file):
        errors.append('找不到 %s' % label)
        return
    shared = RawConfigParser()
    try:
        with open(shared_file) as fp:
            shared.readfp(fp)
    except Exception as e:  # noqa
        errors.append('%s 解析失敗：%s' % (label, e))
        return

    def sget(parser, section, key):
        return unquote(parser.get(section, key)) if parser.has_option(section, key) else ''

    models = [m.upper() for m in shared.options('models')] if shared.has_section('models') else []
    if not models:
        errors.append('%s 的 [models] 沒有任何機型' % label)

    def check_categories(parser, where):
        codes = []
        for section in parser.sections():
            if not section.startswith('category.'):
                continue
            code = section[len('category.'):]
            codes.append(code)
            if not RE_CODE.match(code):
                errors.append('%s [%s] 類別代碼只能用小寫英數字與底線' % (where, section))
            color = sget(parser, section, 'color')
            if color and not RE_HEX.match(color):
                errors.append('%s [%s] color "%s" 不是 Hex 色號（例 "#E67E22"）' % (where, section, color))
        return codes

    categories = check_categories(shared, label)
    if not categories:
        errors.append('%s 沒有任何 [category.<代碼>]' % label)
    pm_category = sget(shared, 'pm', 'category') or 'pm'
    if pm_category not in categories:
        warnings.append('%s [pm] category "%s" 不是已定義的類別' % (label, pm_category))

    formula = (sget(shared, 'availability', 'formula') or 'default').lower()
    if formula != 'default':
        camel = ''.join(w.capitalize() for w in formula.split('_'))
        if not os.path.isfile(os.path.join(MODULES, 'tool_board', 'formulas', camel + '.php')):
            errors.append('%s [availability] formula "%s" 找不到 modules/tool_board/formulas/%s.php' % (label, formula, camel))

    pm_path = sget(shared, 'pm', 'json_path')
    if pm_path:
        if not os.path.isfile(pm_path):
            warnings.append('%s [pm] json_path 檔案不存在：%s（看板會顯示警告，其他功能正常）' % (label, pm_path))
        else:
            import json
            import ast
            with open(pm_path) as fp:
                text = fp.read()
            try:
                json.loads(text)
            except ValueError:
                try:
                    ast.literal_eval(text)
                    warnings.append('PM 檔 %s 不是標準 JSON（單引號），看板會自動轉換；請產生端改用 json.dump' % pm_path)
                except Exception:  # noqa
                    errors.append('PM 檔 %s 無法解析' % pm_path)

    owner = {}
    defaults_wip = [(c, sget(shared, 'category.' + c, 'default_wip')) for c in categories]
    for m in models:
        rel = 'config/tool_board/%s.ini' % m
        path = os.path.join(ROOT, rel)
        if not os.path.isfile(path):
            errors.append('找不到機型設定檔 %s' % rel)
            continue
        parser = RawConfigParser()
        try:
            with open(path) as fp:
                parser.readfp(fp)
        except Exception as e:  # noqa
            errors.append('%s 解析失敗：%s' % (rel, e))
            continue
        tools = read_array('tools', 'tool', path)
        if not tools:
            warnings.append('%s 的 [tools] 沒有任何機台' % rel)
        for t in tools:
            if not RE_TOOL.match(t):
                errors.append('%s 機台代號格式錯誤：%s' % (rel, t))
                continue
            key = t.upper()
            if key in owner and owner[key] != m:
                errors.append('機台 %s 同時列在 %s 與 %s' % (key, owner[key], m))
            elif key in owner:
                warnings.append('%s 機台重複：%s' % (rel, key))
            owner[key] = m
        editors = read_array('editors', 'account', path)
        if not editors:
            warnings.append('%s 的 [editors] 沒有任何帳號，這個機型只有 ADMIN 能編輯' % rel)
        for a in editors:
            if not RE_ACCOUNT.match(a):
                errors.append('%s [editors] 帳號格式錯誤：%s' % (rel, a))
            elif a.lower() not in ee_accounts:
                warnings.append('%s [editors] %s 不在 [ee_accounts]，若不是 EE 帳號將無法編輯' % (rel, a))
        wips = []
        for section in parser.sections():
            if section.startswith('wip.'):
                code = section[len('wip.'):]
                wips.append(code)
                if not RE_CODE.match(code):
                    errors.append('%s [%s] WIP 代碼只能用小寫英數字與底線' % (rel, section))
                color = sget(parser, section, 'text_color')
                if color and not RE_HEX.match(color):
                    errors.append('%s [%s] text_color "%s" 不是 Hex 色號' % (rel, section, color))
        if not wips:
            errors.append('%s 沒有任何 [wip.<代碼>]（WIP 處理方式）' % rel)
        check_categories(parser, rel)
        for cat, dw in defaults_wip:
            if dw and dw not in wips:
                warnings.append('%s 沒有 [wip.%s]，類別 %s 的 default_wip 在這個機型改選第一個' % (rel, dw, cat))
        print('== 借機看板 %s：機台 %d 台、可編輯 %s、WIP %s ==' % (
            m, len(tools), ', '.join(editors) or '-', ', '.join(wips) or '-'))


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

    if 'tool_board' in pages:
        check_tool_board(seen, errors, warnings)

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
