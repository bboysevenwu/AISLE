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
    target / target.<機台> / target.<機台>.<參數> 是數字、參數在 params 裡（偏移模式 spec 規則）、
    charts/ 底下沒有頁面使用的 chart（警告）
  - 借機 / PM 看板（有 [page.tool_board] 時）：config/tool_board.ini 與每個機型的
    config/tool_board/<機型>.ini 存在且格式正確、顏色是 Hex、機台沒有重複列在多個機型、
    每個機型至少一個 [wip.*]、[editors] 帳號是否在 [ee_accounts]（警告）、
    可用率公式檔存在、PM JSON 檔可解析（單引號格式只警告）
  - 設備選機表（有 [page.tool_select] 時）：config/tool_select.ini 與每個機型的
    config/tool_select/<機型>.ini 存在且格式正確、顏色是 Hex、項目的分類存在、
    spec（含機台覆蓋）大小關係正確、覆蓋的機台在 [tools] 裡、href 格式、數值 JSON 檔可解析、
    target / target.<機台> 是數字且機台在 [tools] 裡（偏移模式時 spec 必須 >= 0、同側 OOS >= OOC）
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


def check_offset_spec(where, vals, errors):
    """偏移模式（有寫 target）：spec 是距離 target 的偏移量，>= 0；同側 OOS >= OOC；上下同一層不能同時為 0"""
    for key in SPEC_KEYS:
        if vals.get(key) is not None and vals[key] < 0:
            errors.append('%s：有寫 target 時 %s 是距離 target 的偏移量，必須 >= 0' % (where, key))
    for side in ('upper', 'lower'):
        oos, ooc = vals.get(side + '_oos'), vals.get(side + '_ooc')
        if oos is not None and ooc is not None and oos < ooc:
            errors.append('%s：%s_oos（%s）必須大於等於 %s_ooc（%s）' % (where, side, oos, side, ooc))
    for lv in ('ooc', 'oos'):
        if vals.get('upper_' + lv) == 0 and vals.get('lower_' + lv) == 0:
            errors.append('%s：upper_%s 與 lower_%s 不能同時為 0' % (where, lv, lv))


def check_chart_target(label, section, parser, get, errors):
    """target / target.<機台> / target.<機台>.<參數>：數字或留空，參數在 params 裡。回傳是否為偏移模式"""
    params = split_list(get(section, 'params'))
    offset = False
    for opt in parser.options(section):
        if opt != 'target' and not opt.startswith('target.'):
            continue
        offset = True
        v = get(section, opt)
        if v:
            try:
                float(v)
            except ValueError:
                errors.append('%s [%s] %s 必須是數字或留空：%s' % (label, section, opt, v))
        if opt == 'target':
            continue
        rest = opt[len('target.'):].split('.', 1)
        if not rest[0].strip() or (len(rest) > 1 and not rest[1].strip()):
            errors.append('%s [%s] %s 格式錯誤，應為 target.<機台> 或 target.<機台>.<參數>' % (label, section, opt))
        elif len(rest) > 1 and rest[1].strip() not in params:
            errors.append('%s [%s] %s 的參數 %s 不在 params 裡（區分大小寫）' % (label, section, opt, rest[1].strip()))
    return offset


def check_spec_values(where, vals, offset, errors):
    """一套 spec（key → 數字或 None）的大小關係"""
    if offset:
        check_offset_spec(where, vals, errors)
        return
    for i, a in enumerate(SPEC_KEYS):
        for b in SPEC_KEYS[i + 1:]:
            if vals.get(a) is None or vals.get(b) is None:
                continue
            same_side = a[:5] == b[:5]
            if (vals[a] < vals[b]) if same_side else (vals[a] <= vals[b]):
                errors.append('%s %s（%s）必須%s %s（%s）' % (
                    where, a, vals[a], '大於等於' if same_side else '大於', b, vals[b]))


def check_spec(label, section, parser, get, errors, offset=False, models=None):
    """spec 線：數字或留空；絕對值模式 lower_oos <= lower_ooc < upper_ooc <= upper_oos
    upper_ooc.<機台或機型> 等覆蓋：合併後（機台 → 機型 → 整組）也要符合"""
    def num(key):
        v = get(section, key)
        if not v:
            return None
        try:
            return float(v)
        except ValueError:
            errors.append('%s [%s] %s 必須是數字或留空：%s' % (label, section, key, v))
            return None
    vals = dict((key, num(key)) for key in SPEC_KEYS)
    where = '%s [%s]' % (label, section)
    check_spec_values(where, vals, offset, errors)

    models = models or {}

    def model_of_name(name):
        for m, needle in models.items():
            if name.lower() in (m.lower(), needle.lower()):
                return m
        return None

    by_model, by_tool = {}, {}
    for opt in parser.options(section):
        base = opt.split('.', 1)[0]
        if '.' not in opt or base not in SPEC_KEYS:
            continue
        name = opt.split('.', 1)[1].strip()
        if not name:
            errors.append('%s %s 格式錯誤，應為 %s.<機台或機型>' % (where, opt, base))
            continue
        m = model_of_name(name)
        if m is not None:
            by_model.setdefault(m, {})[base] = num(opt)
        else:
            by_tool.setdefault(name.lower(), {})[base] = num(opt)
    for m, o in by_model.items():
        merged = dict(vals)
        merged.update(o)
        check_spec_values('%s 機型 %s' % (where, m), merged, offset, errors)
    for tool, o in by_tool.items():
        merged = dict(vals)
        for m, needle in models.items():
            if needle.lower() in tool:
                merged.update(by_model.get(m, {}))
                break
        merged.update(o)
        check_spec_values('%s 機台 %s' % (where, tool.upper()), merged, offset, errors)


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

    models = {}
    if parser.has_section('page'):
        for opt in parser.options('page'):
            if opt.startswith('model.') and opt[6:] and get('page', opt):
                models[opt[6:]] = get('page', opt)

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
        check_spec(label, section, parser, get, errors,
                   check_chart_target(label, section, parser, get, errors), models)
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
RE_FORMULA = re.compile(r'^[a-z][a-z0-9_]{0,63}$')


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

    def check_formula(formula, where):
        """可用率公式：default 或 modules/tool_board/formulas/<Camel>.php 的 <Camel>Availability"""
        if formula == 'default':
            return
        if not RE_FORMULA.match(formula):
            errors.append('%s [availability] formula "%s" 只能用小寫英數字與底線，且以英文字母開頭' % (where, formula))
            return
        camel = ''.join(w.capitalize() for w in formula.split('_'))
        php = os.path.join(MODULES, 'tool_board', 'formulas', camel + '.php')
        if not os.path.isfile(php):
            errors.append('%s [availability] formula "%s" 找不到 modules/tool_board/formulas/%s.php' % (where, formula, camel))
            return
        with open(php) as fp:
            if not re.search(r'class\s+%sAvailability\b' % camel, fp.read()):
                errors.append('modules/tool_board/formulas/%s.php 裡沒有 class %sAvailability（formula "%s"）' % (camel, camel, formula))

    def check_alert(parser, where):
        """回傳該檔的 alert_below（沒寫 = None）"""
        if parser.has_option('availability', 'warn_below'):
            warnings.append('%s [availability] warn_below 已不使用（只剩 alert_below 紅色門檻），可刪除' % where)
        v = sget(parser, 'availability', 'alert_below')
        if v == '':
            return None
        try:
            f = float(v)
        except ValueError:
            errors.append('%s [availability] alert_below 必須是數字：%s' % (where, v))
            return None
        if f < 0 or f > 100:
            warnings.append('%s [availability] alert_below = %s 不在 0 ～ 100 之間' % (where, v))
        return f

    shared_formula = (sget(shared, 'availability', 'formula') or 'default').lower()
    check_formula(shared_formula, label)
    shared_alert = check_alert(shared, label)
    if shared_alert is None:
        shared_alert = 80.0

    pm_path = sget(shared, 'pm', 'json_path')
    if pm_path:
        if not os.path.isfile(pm_path):
            warnings.append('%s [pm] json_path 檔案不存在：%s（看板會顯示警告，其他功能正常）' % (label, pm_path))
        else:
            import json
            import ast
            with open(pm_path) as fp:
                text = fp.read()
            data = None
            try:
                data = json.loads(text)
            except ValueError:
                try:
                    data = ast.literal_eval(text)
                    warnings.append('PM 檔 %s 不是標準 JSON（單引號），看板會自動轉換；請產生端改用 json.dump' % pm_path)
                except Exception:  # noqa
                    errors.append('PM 檔 %s 無法解析' % pm_path)
            # period（PM 週期，選填）：文字，最多 32 字，超過會被截斷
            if isinstance(data, dict):
                for tool, pms in data.items():
                    if not isinstance(pms, dict):
                        continue
                    for typ, p in pms.items():
                        if not isinstance(p, dict) or p.get('period') is None:
                            continue
                        per = p.get('period')
                        if isinstance(per, (dict, list, bool)):
                            warnings.append('PM 檔 %s / %s 的 period 不是文字，不會顯示' % (tool, typ))
                            continue
                        if isinstance(per, str):
                            per = per.decode('utf-8', 'replace')
                        if len(unicode(per).strip()) > 32:  # noqa: F821（Python 2.7）
                            warnings.append('PM 檔 %s / %s 的 period 超過 32 字，會被截斷' % (tool, typ))

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
        # 可用率：機型檔沒寫的項目沿用共用檔
        formula = (sget(parser, 'availability', 'formula') or shared_formula).lower()
        if parser.has_option('availability', 'formula'):
            check_formula(formula, rel)
        alert = check_alert(parser, rel)
        if alert is None:
            alert = shared_alert
        print('== 借機看板 %s：機台 %d 台、可編輯 %s、WIP %s、可用率公式 %s（低於 %g 紅）==' % (
            m, len(tools), ', '.join(editors) or '-', ', '.join(wips) or '-', formula, alert))


RE_TS_CODE = re.compile(r'^[a-z0-9_]{1,32}$')
RE_TS_ITEM = re.compile(r'^[a-z0-9_]{1,64}$')
TS_ITEM_KEYS = ('category', 'label', 'key', 'unit', 'decimals', 'href',
                'ooc_bg', 'oos_bg', 'ooc_bg_light', 'oos_bg_light') + SPEC_KEYS


def check_tool_select(errors, warnings):
    """設備選機表設定"""
    shared_file = os.path.join(ROOT, 'config', 'tool_select.ini')
    label = 'config/tool_select.ini'
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
    for sec in ('color', 'status'):
        if shared.has_section(sec):
            for opt in shared.options(sec):
                v = sget(shared, sec, opt)
                if v and not RE_HEX.match(v):
                    errors.append('%s [%s] %s "%s" 不是 Hex 色號（例 "#7A0000"）' % (label, sec, opt, v))

    def number(v, where):
        if v == '':
            return None
        try:
            return float(v)
        except ValueError:
            errors.append('%s 必須是數字或留空：%s' % (where, v))
            return None

    def check_order(vals, where):
        uoos, uooc, looc, loos = vals
        pairs = ((loos, looc, False), (looc, uooc, True), (uooc, uoos, False),
                 (loos, uoos, True), (loos, uooc, True), (looc, uoos, True))
        for lo, hi, strict in pairs:
            if lo is None or hi is None:
                continue
            if (lo >= hi) if strict else (lo > hi):
                errors.append('%s：spec 必須符合 lower_oos <= lower_ooc < upper_ooc <= upper_oos' % where)
                return

    for m in models:
        rel = 'config/tool_select/%s.ini' % m
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
        tools = []
        for t in read_array('tools', 'tool', path):
            if not RE_TOOL.match(t):
                errors.append('%s 機台代號格式錯誤：%s' % (rel, t))
            elif t.upper() in tools:
                warnings.append('%s 機台重複：%s' % (rel, t))
            else:
                tools.append(t.upper())
        if not tools:
            warnings.append('%s 的 [tools] 沒有任何機台' % rel)
        cats = parser.options('category') if parser.has_section('category') else []
        for c in cats:
            if not RE_TS_CODE.match(c):
                errors.append('%s [category] 代號只能用小寫英文、數字、底線：%s' % (rel, c))
        if not cats:
            errors.append('%s 沒有 [category]（分類）' % rel)

        items = 0
        keys = {}
        for section in parser.sections():
            if not section.startswith('item.'):
                continue
            items += 1
            iid = section[len('item.'):]
            where = '%s [%s]' % (rel, section)
            if not RE_TS_ITEM.match(iid):
                errors.append('%s 項目代號只能用小寫英文、數字、底線' % where)
            cat = sget(parser, section, 'category')
            if cat not in cats:
                errors.append('%s category "%s" 不在 [category] 裡' % (where, cat))
            key = (sget(parser, section, 'key') or iid).lower()
            if key in keys:
                warnings.append('%s 的 JSON key "%s" 和 [%s] 重複，兩個項目會顯示相同的值' % (where, key, keys[key]))
            keys[key] = section
            spec = [number(sget(parser, section, k), '%s %s' % (where, k)) for k in SPEC_KEYS]
            offset = False
            for opt in parser.options(section):
                if opt == 'target' or opt.startswith('target.'):
                    offset = True
                    number(sget(parser, section, opt), '%s %s' % (where, opt))
                    tool = opt[len('target.'):].strip().upper()
                    if opt != 'target' and tool not in tools:
                        errors.append('%s %s 的機台 %s 不在 [tools] 裡' % (where, opt, tool))

            def check_vals(vals, w):
                if offset:
                    check_offset_spec(w, dict(zip(SPEC_KEYS, vals)), errors)
                else:
                    check_order(vals, w)

            check_vals(spec, where)
            overrides = {}
            for opt in parser.options(section):
                if opt == 'target' or opt.startswith('target.'):
                    continue
                if '.' in opt:
                    base, tool = opt.split('.', 1)
                    tool = tool.upper()
                    if base not in SPEC_KEYS:
                        errors.append('%s 不認得的欄位 %s（機台覆蓋只能用 %s）' % (where, opt, ' / '.join(SPEC_KEYS)))
                        continue
                    if tool not in tools:
                        errors.append('%s %s 的機台 %s 不在 [tools] 裡' % (where, opt, tool))
                        continue
                    overrides.setdefault(tool, list(spec))[SPEC_KEYS.index(base)] = \
                        number(sget(parser, section, opt), '%s %s' % (where, opt))
                elif opt not in TS_ITEM_KEYS:
                    warnings.append('%s 不認得的欄位：%s（會被忽略）' % (where, opt))
            for tool, vals in overrides.items():
                check_vals(vals, '%s 機台 %s' % (where, tool))
            for k in ('ooc_bg', 'oos_bg', 'ooc_bg_light', 'oos_bg_light'):
                v = sget(parser, section, k)
                if v and not RE_HEX.match(v):
                    errors.append('%s %s "%s" 不是 Hex 色號' % (where, k, v))
            href = sget(parser, section, 'href')
            if href and (re.search(r'[\s"\'<>\\]', href) or not re.match(r'^(\?|/(?!/)|https?://)', href, re.I)):
                errors.append('%s href 只能是 "?m=..."、"/..." 或 http(s):// 開頭：%s' % (where, href))
            dec = sget(parser, section, 'decimals')
            if dec and not dec.isdigit():
                errors.append('%s decimals 必須是 0 以上的整數' % where)
        if not items:
            warnings.append('%s 沒有任何 [item.<代號>]（項目）' % rel)

        sample = sget(parser, 'page', 'sample').lower() in ('1', 'on', 'yes', 'true')
        json_path = sget(parser, 'page', 'json_path')
        status = '範例值' if sample else (json_path or '未設定 json_path')
        if not sample and json_path:
            if not os.path.isfile(json_path):
                warnings.append('%s [page] json_path 檔案不存在：%s（頁面會顯示警告，數值顯示 "-"）' % (rel, json_path))
            else:
                import json
                import ast
                with open(json_path) as fp:
                    text = fp.read()
                try:
                    json.loads(text)
                except ValueError:
                    try:
                        ast.literal_eval(text)
                        warnings.append('數值檔 %s 不是標準 JSON（單引號），頁面會自動轉換；請產生端改用 json.dump' % json_path)
                    except Exception:  # noqa
                        errors.append('數值檔 %s 無法解析' % json_path)
        print('== 選機表 %s：機台 %d 台、分類 %d、項目 %d、數值 %s ==' % (m, len(tools), len(cats), items, status))


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
    if 'tool_select' in pages:
        check_tool_select(errors, warnings)

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
