#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
套用 AISLE 單檔更新（Python 2.7）

主機不能 git pull、不能下載壓縮檔時使用：在 GitHub 打開 updates/ 底下的更新檔，
按 Raw → 全選複製 → 貼到主機上的一個檔案，再用這支腳本一次套用。

用法：
  python apply_update.py <更新檔>                 預覽：列出會新增 / 覆蓋 / 刪除哪些檔案，不寫入
  python apply_update.py <更新檔> --apply         實際套用
  python apply_update.py --list                    列出過去的更新紀錄
  python apply_update.py --rollback <時間>         預覽還原某次更新（時間如 20261104_204500，或 last）
  python apply_update.py --rollback <時間> --apply 實際還原

選項：
  --root <目錄>   AISLE 根目錄。預設：腳本在 scripts/python/ 底下時自動找上兩層，
                  否則用目前目錄（目錄內要有 core/bootstrap.php）
  --force         主機上的檔案和更新檔預期的版本不同（衝突）時仍然套用 / 還原

行為：
  - 被覆蓋的舊檔先複製一份在原位置，檔名後面加上 _<時間>，例 Auth.php_20261104_204500；
    被刪除的檔案不會真的刪掉，而是改名成同樣格式的備份
  - 同一次執行的所有備份共用同一個時間，紀錄寫在 storage/logs/update_<時間>.json
  - 每個檔案都用 sha1 驗證；只要有一個檔案內容不對（複製漏行、被編輯器改掉縮排），
    或更新檔被截斷，整批都不寫入
  - 套用到一半出錯時，自動把已經改過的檔案還原

回傳碼：0 = 成功（或預覽沒有問題），1 = 有錯誤或衝突，2 = 用法錯誤
"""
from __future__ import print_function

import base64
import binascii
import hashlib
import json
import os
import re
import shutil
import sys
import time

FORMAT_HEADER = '#AISLE-UPDATE v1'
MARK = '#@@@ '
STAMP_FORMAT = '%Y%m%d_%H%M%S'
RE_STAMP = re.compile(r'^\d{8}_\d{6}$')
RE_SHA1 = re.compile(r'^[0-9a-f]{40}$')
NO_FILE = '-'          # base=- 代表更新前這個檔案不存在
LOG_DIR = os.path.join('storage', 'logs')


class UpdateError(Exception):
    pass


def out(msg=''):
    print(msg)
    sys.stdout.flush()


def sha1_of(data):
    return hashlib.sha1(data).hexdigest()


def file_sha1(path):
    if not os.path.isfile(path):
        return None
    fp = open(path, 'rb')
    try:
        return sha1_of(fp.read())
    finally:
        fp.close()


# --------------------------------------------------------------------------
# 解析更新檔
# --------------------------------------------------------------------------

def parse_attrs(text, lineno):
    attrs = {}
    for part in text.split():
        if '=' not in part:
            raise UpdateError('第 %d 行：無法解析 "%s"' % (lineno, part))
        k, v = part.split('=', 1)
        attrs[k] = v
    return attrs


def check_path(path, lineno):
    bad = (not path or path.startswith('/') or '\\' in path or '\0' in path
           or ':' in path.split('/')[0])
    parts = path.split('/')
    if bad or '..' in parts or '.' in parts or '' in parts or parts[0] == '.git':
        raise UpdateError('第 %d 行：不允許的路徑 "%s"' % (lineno, path))
    return path


def build_content(entry, lines):
    """把更新檔內的區塊還原成要寫入的 bytes，並驗證 sha1"""
    enc = entry['enc']
    if enc == 'base64':
        try:
            data = base64.b64decode(''.join(l.strip() for l in lines))
        except (TypeError, binascii.Error):
            raise UpdateError('%s：base64 內容損毀（第 %d 行起）' % (entry['path'], entry['lineno']))
    elif enc == 'text':
        if lines:
            data = '\n'.join(lines) + '\n'
            if entry['nl'] == '0':
                data = data[:-1]
        else:
            data = ''
        if entry['eol'] == 'crlf':
            data = data.replace('\n', '\r\n')
    else:
        raise UpdateError('%s：不支援的 enc=%s' % (entry['path'], enc))

    actual = sha1_of(data)
    if actual != entry['sha1']:
        raise UpdateError(
            '%s 的內容和 sha1 不符（第 %d 行起，%d 行）。\n'
            '    通常是複製時漏了內容、多了內容，或編輯器自動改了縮排；請重新複製整個更新檔。\n'
            '    預期 %s\n    實際 %s' % (entry['path'], entry['lineno'], len(lines), entry['sha1'], actual))
    return data


def parse_update(path):
    fp = open(path, 'rb')
    try:
        raw = fp.read()
    finally:
        fp.close()
    if raw.startswith('\xef\xbb\xbf'):          # 有些編輯器會加 UTF-8 BOM
        raw = raw[3:]
    raw = raw.replace('\r\n', '\n')             # 從 Windows 瀏覽器複製可能變成 CRLF
    lines = raw.split('\n')

    meta = {}
    entries = []
    current = None
    block = []
    ended = False
    seen_header = False
    seen_paths = set()

    def finish():
        if current is not None and current['op'] == 'file':
            current['data'] = build_content(current, block)

    for idx, line in enumerate(lines):
        lineno = idx + 1
        if not seen_header:
            if line.strip() == '':
                continue
            if line.rstrip() != FORMAT_HEADER:
                raise UpdateError('第 %d 行：不是 AISLE 更新檔（第一行應為 "%s"）' % (lineno, FORMAT_HEADER))
            seen_header = True
            continue
        if ended:
            if line.strip():
                raise UpdateError('第 %d 行：END 之後還有內容，可能貼了兩次' % lineno)
            continue

        if line.startswith(MARK):
            finish()
            block = []
            current = None
            parts = line[len(MARK):].strip().split(None, 2)
            if not parts:
                raise UpdateError('第 %d 行：空的標記行' % lineno)
            kind = parts[0]
            if kind == 'END':
                attrs = parse_attrs(' '.join(parts[1:]), lineno)
                if 'count' in attrs and attrs['count'] != str(len(entries)):
                    raise UpdateError('更新檔應有 %s 個項目，實際讀到 %d 個，可能沒有複製完整'
                                      % (attrs['count'], len(entries)))
                ended = True
                continue
            if kind not in ('FILE', 'DELETE') or len(parts) < 2:
                raise UpdateError('第 %d 行：無法解析的標記 "%s"' % (lineno, line.strip()))
            fpath = check_path(parts[1], lineno)
            if fpath in seen_paths:
                raise UpdateError('第 %d 行：%s 出現兩次' % (lineno, fpath))
            seen_paths.add(fpath)
            attrs = parse_attrs(parts[2] if len(parts) > 2 else '', lineno)
            base = attrs.get('base')
            if base is not None and base != NO_FILE and not RE_SHA1.match(base):
                raise UpdateError('第 %d 行：base 格式錯誤' % lineno)
            current = {'op': kind.lower(), 'path': fpath, 'base': base, 'lineno': lineno + 1}
            if kind == 'FILE':
                if not RE_SHA1.match(attrs.get('sha1', '')):
                    raise UpdateError('第 %d 行：缺少 sha1' % lineno)
                current.update({
                    'sha1': attrs['sha1'],
                    'enc': attrs.get('enc', 'text'),
                    'nl': attrs.get('nl', '1'),
                    'eol': attrs.get('eol', 'lf'),
                    'mode': attrs.get('mode', ''),
                })
            entries.append(current)
            continue

        if current is None:
            if line.startswith('#@ '):           # 更新檔資訊：#@ key=value
                k, _, v = line[3:].partition('=')
                meta[k.strip()] = v.strip()
            elif line.strip() and not line.startswith('#'):
                raise UpdateError('第 %d 行：檔案區塊外出現內容' % lineno)
            continue
        if current['op'] == 'delete':
            if line.strip():
                raise UpdateError('第 %d 行：DELETE 後面不應有內容' % lineno)
            continue
        block.append(line)

    if not seen_header:
        raise UpdateError('更新檔是空的')
    if not ended:
        raise UpdateError('找不到結尾的 "%sEND"，更新檔沒有複製完整' % MARK)
    return meta, entries


# --------------------------------------------------------------------------
# 規劃
# --------------------------------------------------------------------------

def make_plan(root, entries):
    """比對主機上的現況，決定每個檔案的動作。回傳 (plan, conflicts)"""
    plan = []
    conflicts = []
    for e in entries:
        full = os.path.join(root, e['path'])
        if os.path.islink(full) or (os.path.lexists(full) and not os.path.isfile(full)):
            raise UpdateError('%s 在主機上是目錄或連結，無法處理' % e['path'])
        current = file_sha1(full)
        base = e['base']
        item = {'entry': e, 'path': e['path'], 'full': full, 'current': current, 'note': ''}

        if e['op'] == 'file':
            if current == e['sha1']:
                item['action'] = 'same'
            elif current is None:
                item['action'] = 'create'
                if base not in (None, NO_FILE):
                    item['note'] = '主機上沒有這個檔案（預期應該存在）'
            else:
                item['action'] = 'overwrite'
                if base == NO_FILE:
                    item['note'] = '主機上已經有這個檔案（預期是新檔）'
                elif base is not None and current != base:
                    item['note'] = '主機上的檔案和更新前的版本不同（可能被直接改過）'
        else:
            if current is None:
                item['action'] = 'gone'
            else:
                item['action'] = 'delete'
                if base not in (None, NO_FILE) and current != base:
                    item['note'] = '主機上的檔案和更新前的版本不同（可能被直接改過）'
        if item['note']:
            conflicts.append(item)
        plan.append(item)
    return plan, conflicts


LABELS = {
    'create': '[新增]',
    'overwrite': '[覆蓋]',
    'delete': '[刪除]',
    'same': '[相同]',
    'gone': '[已無]',
}


def print_plan(plan, stamp):
    counts = {}
    for item in plan:
        a = item['action']
        counts[a] = counts.get(a, 0) + 1
        line = '  %s %s' % (LABELS[a], item['path'])
        if a in ('overwrite', 'delete'):
            line += '    → 備份 %s_%s' % (item['path'], stamp)
        if a == 'same':
            line += '    (內容相同，略過)'
        if a == 'gone':
            line += '    (主機上已經沒有，略過)'
        out(line)
        if item['note']:
            out('      ! 衝突：%s' % item['note'])
    out()
    out('  新增 %d、覆蓋 %d、刪除 %d、相同 %d' % (
        counts.get('create', 0), counts.get('overwrite', 0),
        counts.get('delete', 0), counts.get('same', 0) + counts.get('gone', 0)))


# --------------------------------------------------------------------------
# 寫入
# --------------------------------------------------------------------------

def is_root_user():
    return hasattr(os, 'geteuid') and os.geteuid() == 0


def copy_owner(path, ref_stat):
    if is_root_user():
        try:
            os.chown(path, ref_stat.st_uid, ref_stat.st_gid)
        except OSError:
            pass


def make_dirs(root, rel_dir, created_dirs):
    """逐層建立目錄，擁有者跟上一層相同；記下新建的目錄以便還原"""
    parent = root
    for part in rel_dir.split('/'):
        if not part:
            continue
        path = os.path.join(parent, part)
        if not os.path.isdir(path):
            os.mkdir(path, 0o755)
            copy_owner(path, os.stat(parent))
            created_dirs.append(os.path.relpath(path, root).replace(os.sep, '/'))
        parent = path


def write_file(full, data, ref_stat, mode):
    tmp = os.path.join(os.path.dirname(full), '.%s.aisle-tmp' % os.path.basename(full))
    try:
        fp = open(tmp, 'wb')
        try:
            fp.write(data)
        finally:
            fp.close()
        if ref_stat is not None:
            perm = ref_stat.st_mode & 0o7777
            if mode == '755':
                perm |= 0o111
            os.chmod(tmp, perm)
            copy_owner(tmp, ref_stat)
        else:
            os.chmod(tmp, 0o755 if mode == '755' else 0o644)
            copy_owner(tmp, os.stat(os.path.dirname(full)))
        os.rename(tmp, full)
    except (OSError, IOError):
        if os.path.exists(tmp):
            os.remove(tmp)
        raise


def log_path(root, stamp):
    return os.path.join(root, LOG_DIR, 'update_%s.json' % stamp)


def save_log(root, record):
    d = os.path.join(root, LOG_DIR)
    if not os.path.isdir(d):
        os.makedirs(d)
    fp = open(log_path(root, record['stamp']), 'wb')
    try:
        fp.write(json.dumps(record, indent=2, sort_keys=True, separators=(',', ': ')))
    finally:
        fp.close()


def undo(root, done):
    """把已完成的動作倒著還原（套用中途失敗時使用）"""
    for act in reversed(done):
        full = os.path.join(root, act['path'])
        try:
            if act['op'] == 'create':
                if os.path.exists(full):
                    os.remove(full)
            elif act['op'] in ('overwrite', 'delete'):
                backup = os.path.join(root, act['backup'])
                if os.path.exists(backup):
                    os.rename(backup, full)
            elif act['op'] == 'mkdir':
                os.rmdir(full)
        except OSError as ex:
            out('  ! 還原 %s 失敗：%s' % (act['path'], ex))


def apply_plan(root, plan, stamp, update_file, meta):
    record = {
        'stamp': stamp,
        'update_file': os.path.abspath(update_file),
        'from': meta.get('from', ''),
        'to': meta.get('to', ''),
        'applied_at': time.strftime('%Y-%m-%d %H:%M:%S'),
        'status': 'applying',
        'actions': [],
    }
    done = record['actions']
    try:
        for item in plan:
            e = item['entry']
            a = item['action']
            full = item['full']
            rel_backup = '%s_%s' % (e['path'], stamp)
            backup = os.path.join(root, rel_backup)
            if a == 'create':
                created_dirs = []
                rel_dir = os.path.dirname(e['path'])
                if rel_dir:
                    make_dirs(root, rel_dir, created_dirs)
                for d in created_dirs:
                    done.append({'op': 'mkdir', 'path': d})
                write_file(full, e['data'], None, e['mode'])
                done.append({'op': 'create', 'path': e['path'], 'sha1': e['sha1']})
            elif a == 'overwrite':
                st = os.stat(full)
                shutil.copy2(full, backup)
                copy_owner(backup, st)
                done.append({'op': 'overwrite', 'path': e['path'], 'backup': rel_backup,
                             'sha1': e['sha1'], 'old_sha1': item['current']})
                write_file(full, e['data'], st, e['mode'])
            elif a == 'delete':
                os.rename(full, backup)
                done.append({'op': 'delete', 'path': e['path'], 'backup': rel_backup,
                             'old_sha1': item['current']})
            else:
                continue
            out('  %s %s' % (LABELS[a], e['path']))
    except (OSError, IOError) as ex:
        out()
        out('[ERROR] 寫入 %s 時失敗：%s' % (item['path'], ex))
        out('        正在還原已經改過的檔案...')
        undo(root, done)
        record['status'] = 'failed'
        try:
            save_log(root, record)
        except (OSError, IOError):
            pass
        out('        已還原。請確認目錄權限（例：用 sudo 執行）後再試一次。')
        return False
    record['status'] = 'applied'
    save_log(root, record)
    return True


# --------------------------------------------------------------------------
# 紀錄與還原
# --------------------------------------------------------------------------

def to_str(value):
    """json.loads 在 Python 2 回傳 unicode，轉回 UTF-8 str，避免和中文訊息字串混用時出錯"""
    if isinstance(value, dict):
        return dict((to_str(k), to_str(v)) for k, v in value.items())
    if isinstance(value, list):
        return [to_str(v) for v in value]
    if sys.version_info[0] == 2 and isinstance(value, unicode):  # noqa: F821
        return value.encode('utf-8')
    return value


def load_logs(root):
    d = os.path.join(root, LOG_DIR)
    logs = []
    if os.path.isdir(d):
        for name in sorted(os.listdir(d)):
            m = re.match(r'^update_(\d{8}_\d{6})\.json$', name)
            if not m:
                continue
            try:
                fp = open(os.path.join(d, name), 'rb')
                try:
                    logs.append(to_str(json.loads(fp.read())))
                finally:
                    fp.close()
            except (IOError, ValueError):
                out('  ! 無法讀取 %s' % name)
    return logs


def cmd_list(root):
    logs = load_logs(root)
    if not logs:
        out('沒有更新紀錄（%s）' % os.path.join(root, LOG_DIR))
        return 0
    status_text = {'applied': '已套用', 'rolled_back': '已還原', 'failed': '失敗已還原', 'applying': '中斷'}
    for r in logs:
        n = len([a for a in r['actions'] if a['op'] != 'mkdir'])
        out('  %s  %s  %3d 個檔案  版本 %s → %s  %s' % (
            r['stamp'], status_text.get(r['status'], r['status']), n,
            (r.get('from') or '?')[:7], (r.get('to') or '?')[:7], os.path.basename(r['update_file'])))
    return 0


def cmd_rollback(root, stamp, do_apply, force):
    logs = load_logs(root)
    if stamp == 'last':
        applied = [r for r in logs if r['status'] == 'applied']
        if not applied:
            out('[ERROR] 沒有可以還原的更新')
            return 1
        record = applied[-1]
    else:
        if not RE_STAMP.match(stamp):
            out('[ERROR] 時間格式應為 YYYYMMDD_HHMMSS，例 20261104_204500')
            return 2
        found = [r for r in logs if r['stamp'] == stamp]
        if not found:
            out('[ERROR] 找不到 %s 的更新紀錄，可用 --list 查看' % stamp)
            return 1
        record = found[0]
    if record['status'] != 'applied':
        out('[ERROR] %s 的狀態是 %s，不能還原' % (record['stamp'], record['status']))
        return 1

    out('還原 %s 的更新（%s）' % (record['stamp'], os.path.basename(record['update_file'])))
    out()
    problems = 0
    for act in reversed(record['actions']):
        full = os.path.join(root, act['path'])
        note = ''
        if act['op'] == 'create':
            line = '  [移除] %s' % act['path']
            if file_sha1(full) not in (act['sha1'], None):
                note = '更新後又被改過，移除會遺失這些修改'
        elif act['op'] == 'overwrite':
            line = '  [還原] %s  ← %s' % (act['path'], os.path.basename(act['backup']))
            if not os.path.isfile(os.path.join(root, act['backup'])):
                out(line)
                out('      ! 找不到備份檔，無法還原')
                problems += 1
                continue
            if file_sha1(full) not in (act['sha1'], None):
                note = '更新後又被改過，還原會遺失這些修改'
        elif act['op'] == 'delete':
            line = '  [放回] %s  ← %s' % (act['path'], os.path.basename(act['backup']))
            if not os.path.isfile(os.path.join(root, act['backup'])):
                out(line)
                out('      ! 找不到備份檔，無法還原')
                problems += 1
                continue
            if os.path.exists(full):
                note = '原位置已經有新的檔案，會被覆蓋'
        else:
            continue
        out(line)
        if note:
            out('      ! 衝突：%s' % note)
            problems += 1
    out()
    if problems and not force:
        out('[ERROR] 有 %d 個問題。確認可以覆蓋後加上 --force' % problems)
        return 1
    if not do_apply:
        out('這是預覽，沒有改動任何檔案。確認無誤後加上 --apply 執行還原。')
        return 0

    for act in reversed(record['actions']):
        full = os.path.join(root, act['path'])
        try:
            if act['op'] == 'create':
                if os.path.exists(full):
                    os.remove(full)
            elif act['op'] in ('overwrite', 'delete'):
                backup = os.path.join(root, act['backup'])
                if os.path.isfile(backup):
                    os.rename(backup, full)
            elif act['op'] == 'mkdir':
                if os.path.isdir(full) and not os.listdir(full):
                    os.rmdir(full)
        except OSError as ex:
            out('[ERROR] %s：%s' % (act['path'], ex))
            return 1
    record['status'] = 'rolled_back'
    record['rolled_back_at'] = time.strftime('%Y-%m-%d %H:%M:%S')
    save_log(root, record)
    out('[OK] 已還原 %s 的更新，備份檔已改回原檔名' % record['stamp'])
    return 0


# --------------------------------------------------------------------------
# 主程式
# --------------------------------------------------------------------------

def find_root(opt_root):
    def ok(d):
        return os.path.isfile(os.path.join(d, 'core', 'bootstrap.php'))
    if opt_root:
        if not ok(opt_root):
            raise UpdateError('%s 不是 AISLE 根目錄（找不到 core/bootstrap.php）' % opt_root)
        return os.path.abspath(opt_root)
    here = os.path.dirname(os.path.abspath(__file__))
    for d in (os.path.join(here, '..', '..'), os.getcwd()):
        if ok(d):
            return os.path.abspath(d)
    raise UpdateError('找不到 AISLE 根目錄，請用 --root 指定，例 --root /var/www/html/aisle')


def usage():
    out(__doc__.strip())
    return 2


def main(argv):
    args = list(argv)
    opts = {'apply': False, 'force': False, 'list': False, 'root': None, 'rollback': None}
    files = []
    while args:
        a = args.pop(0)
        if a in ('-h', '--help'):
            return usage()
        elif a == '--apply':
            opts['apply'] = True
        elif a == '--force':
            opts['force'] = True
        elif a == '--list':
            opts['list'] = True
        elif a in ('--root', '--rollback'):
            if not args:
                out('[ERROR] %s 後面要接參數' % a)
                return 2
            opts[a[2:]] = args.pop(0)
        elif a.startswith('-'):
            out('[ERROR] 不認得的選項 %s（-h 看用法）' % a)
            return 2
        else:
            files.append(a)

    try:
        root = find_root(opts['root'])
        if opts['list']:
            return cmd_list(root)
        if opts['rollback']:
            return cmd_rollback(root, opts['rollback'], opts['apply'], opts['force'])
        if len(files) != 1:
            return usage()
        update_file = files[0]
        if not os.path.isfile(update_file):
            out('[ERROR] 找不到更新檔 %s' % update_file)
            return 1

        meta, entries = parse_update(update_file)
        out('更新檔：%s' % update_file)
        if meta.get('from') or meta.get('to'):
            out('版本　：%s → %s' % (meta.get('from', '?')[:7] or '?', meta.get('to', '?')[:7]))
        if meta.get('note'):
            out('說明　：%s' % meta['note'])
        out('目錄　：%s' % root)
        out('驗證　：%d 個項目的 sha1 都正確' % len(entries))
        out()

        stamp = time.strftime(STAMP_FORMAT)
        plan, conflicts = make_plan(root, entries)
        for item in plan:
            if item['action'] in ('overwrite', 'delete') and os.path.lexists(
                    os.path.join(root, '%s_%s' % (item['path'], stamp))):
                raise UpdateError('備份檔 %s_%s 已經存在，請稍等一秒再執行' % (item['path'], stamp))
        print_plan(plan, stamp)
        out()

        if conflicts and not opts['force']:
            out('[ERROR] 有 %d 個衝突：主機上的檔案不是更新檔預期的版本。' % len(conflicts))
            out('        確認可以覆蓋（會先備份）後加上 --force。')
            return 1
        changes = [p for p in plan if p['action'] in ('create', 'overwrite', 'delete')]
        if not changes:
            out('[OK] 所有檔案都已經是最新的，不需要更新')
            return 0
        if not opts['apply']:
            out('這是預覽，沒有改動任何檔案。確認無誤後加上 --apply 執行。')
            return 0

        out('開始套用（備份時間 %s）' % stamp)
        if not apply_plan(root, plan, stamp, update_file, meta):
            return 1
        out()
        out('[OK] 已更新 %d 個檔案，紀錄：%s' % (len(changes), log_path(root, stamp)))
        out('     要還原這次更新：python %s --rollback %s' % (sys.argv[0], stamp))
        return 0
    except UpdateError as ex:
        out('[ERROR] %s' % ex)
        out('        沒有改動任何檔案。')
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
