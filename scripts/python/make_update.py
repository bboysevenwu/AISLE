#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
產生 AISLE 單檔更新（在有 git 的電腦上執行，Python 2.7 / 3 都可以）

把兩個版本之間的差異（新增、修改、刪除的檔案）打包成一個純文字檔，
主機上用 scripts/python/apply_update.py 套用。

用法：
  python scripts/python/make_update.py <舊版本> [<新版本>] [選項]
      <舊版本>  主機目前的版本（commit、branch 或 tag），例 7d8729a
      <新版本>  要更新到的版本，預設 HEAD

  python scripts/python/make_update.py --all [<版本>]
      打包該版本的所有檔案（主機版本不明、或第一次安裝時使用），不檢查主機上的舊版本

選項：
  -o <檔案>          輸出位置，預設 updates/update_<日期>_<舊>_<新>.txt
  --note "<說明>"    寫進更新檔的說明，套用時會顯示
  --exclude <路徑>   不打包的路徑前綴，可重複；預設排除 updates/ 與 docs/screenshots/
  --no-base          不記錄舊版本的 sha1（主機不檢查衝突）

範例：
  python scripts/python/make_update.py 7d8729a HEAD --note "新增單檔更新工具"
"""
from __future__ import print_function

import base64
import hashlib
import os
import subprocess
import sys
import time

FORMAT_HEADER = b'#AISLE-UPDATE v1'
MARK = b'#@@@ '
DEFAULT_EXCLUDES = ['updates/', 'docs/screenshots/']


PY2 = sys.version_info[0] == 2


def native(b):
    """git 輸出的 bytes 轉成本版本 Python 的 str（Python 2 本來就是 bytes）"""
    return b if PY2 else b.decode('utf-8')


def to_bytes(s):
    return s if isinstance(s, bytes) else s.encode('utf-8')


def die(msg):
    print('[ERROR] %s' % msg)
    sys.exit(1)


def git(*args):
    p = subprocess.Popen(('git',) + args, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    out, err = p.communicate()
    if p.returncode != 0:
        die('git %s 失敗：%s' % (' '.join(args), (err if PY2 else err.decode('utf-8', 'replace')).strip()))
    return out


def rev(ref):
    return native(git('rev-parse', '--verify', ref + '^{commit}')).strip()


def blob(commit, path):
    return git('show', '%s:%s' % (commit, path))


def file_mode(commit, path):
    out = native(git('ls-tree', commit, '--', path))
    return '755' if out.startswith('100755') else '644'


def encode_entry(path, data, base, mode):
    """回傳一個 FILE 區塊（bytes）"""
    sha1 = hashlib.sha1(data).hexdigest()
    attrs = ['sha1=' + sha1]
    if base is not None:
        attrs.append('base=' + base)
    attrs.append('mode=' + mode)
    is_text = b'\0' not in data
    if is_text:
        try:
            data.decode('utf-8')
        except UnicodeDecodeError:
            is_text = False
    eol = 'lf'
    body = data
    if is_text and b'\r' in data:
        # 整個檔案都是 CRLF 時轉成 LF 存放、套用時還原；混用或有單獨的 \r 就改用 base64
        if b'\r' not in data.replace(b'\r\n', b'') and data.count(b'\n') == data.count(b'\r\n'):
            eol = 'crlf'
            body = data.replace(b'\r\n', b'\n')
        else:
            is_text = False
    if is_text and any(line.startswith(MARK) for line in body.split(b'\n')):
        is_text = False          # 內容剛好有標記行，改用 base64 避免誤判

    if is_text:
        nl = '1'
        if body and not body.endswith(b'\n'):
            nl = '0'
            body += b'\n'
        attrs += ['enc=text', 'nl=' + nl]
        if eol == 'crlf':
            attrs.append('eol=crlf')
    else:
        b64 = base64.b64encode(data)
        body = b''.join(b64[i:i + 76] + b'\n' for i in range(0, len(b64), 76))
        attrs.append('enc=base64')
    head = MARK + b'FILE ' + to_bytes(path) + b' ' + ' '.join(attrs).encode('ascii') + b'\n'
    return head + body


def excluded(path, excludes):
    return any(path == e.rstrip('/') or path.startswith(e) for e in excludes)


def main(argv):
    args = list(argv)
    refs = []
    out_path = None
    note = ''
    excludes = []
    use_base = True
    full = False
    while args:
        a = args.pop(0)
        if a in ('-h', '--help'):
            print(__doc__.strip())
            return 0
        elif a in ('-o', '--note', '--exclude'):
            if not args:
                die('%s 後面要接參數' % a)
            v = args.pop(0)
            if a == '-o':
                out_path = v
            elif a == '--note':
                note = v
            else:
                excludes.append(v)
        elif a == '--no-base':
            use_base = False
        elif a == '--all':
            full = True
        elif a.startswith('-'):
            die('不認得的選項 %s（-h 看用法）' % a)
        else:
            refs.append(a)
    excludes = excludes or DEFAULT_EXCLUDES

    top = native(git('rev-parse', '--show-toplevel')).strip()
    os.chdir(top)

    if full:
        if len(refs) > 1:
            die('--all 只接一個版本')
        old = None
        new = rev(refs[0] if refs else 'HEAD')
        names = git('ls-tree', '-r', '-z', '--name-only', new).split(b'\0')
        changes = [('A', native(n)) for n in names if n]
        use_base = False
    else:
        if not refs or len(refs) > 2:
            die('要指定主機目前的版本，例：make_update.py 7d8729a HEAD（-h 看用法）')
        old = rev(refs[0])
        new = rev(refs[1] if len(refs) > 1 else 'HEAD')
        raw = git('diff', '--name-status', '--no-renames', '-z', old, new).split(b'\0')
        raw = [r for r in raw if r]
        changes = []
        for i in range(0, len(raw), 2):
            changes.append((native(raw[i])[0], native(raw[i + 1])))

    if git('status', '--porcelain', '--untracked-files=no').strip():
        print('[注意] 工作目錄有尚未 commit 的修改，更新檔只會包含已 commit 的內容')

    entries = []
    skipped = []
    for status, path in sorted(changes, key=lambda c: c[1]):
        if excluded(path, excludes):
            skipped.append(path)
            continue
        if status in ('A', 'M', 'T'):
            base = None
            if use_base:
                base = '-' if status == 'A' else hashlib.sha1(blob(old, path)).hexdigest()
            block = encode_entry(path, blob(new, path), base, file_mode(new, path))
            entries.append((status, path, block))
        elif status == 'D':
            line = MARK + b'DELETE ' + to_bytes(path)
            if use_base:
                line += b' base=' + hashlib.sha1(blob(old, path)).hexdigest().encode('ascii')
            entries.append((status, path, line + b'\n'))
        else:
            die('不支援的變更類型 %s：%s' % (status, path))

    if not entries:
        die('兩個版本之間沒有需要更新的檔案')

    short_old = old[:7] if old else 'all'
    short_new = new[:7]
    if not out_path:
        out_path = os.path.join('updates', 'update_%s_%s_%s.txt' % (time.strftime('%Y%m%d'), short_old, short_new))
    d = os.path.dirname(out_path)
    if d and not os.path.isdir(d):
        os.makedirs(d)

    lines = [FORMAT_HEADER + b'\n']
    lines.append(b'# AISLE single-file update. Apply on the server:\n')
    lines.append(b'#   python scripts/python/apply_update.py <this file>          (preview)\n')
    lines.append(b'#   python scripts/python/apply_update.py <this file> --apply  (apply)\n')
    lines.append(('#@ from=%s\n' % (old or '')).encode('ascii'))
    lines.append(('#@ to=%s\n' % new).encode('ascii'))
    lines.append(('#@ created=%s\n' % time.strftime('%Y-%m-%d %H:%M:%S')).encode('ascii'))
    if note:
        lines.append(b'#@ note=' + to_bytes(note) + b'\n')
    for _, _, block in entries:
        lines.append(block)
    lines.append(MARK + ('END count=%d\n' % len(entries)).encode('ascii'))

    fp = open(out_path, 'wb')
    try:
        fp.write(b''.join(lines))
    finally:
        fp.close()

    label = {'A': '新增', 'M': '修改', 'T': '修改', 'D': '刪除'}
    for status, path, _ in entries:
        print('  [%s] %s' % (label[status], path))
    for path in skipped:
        print('  [略過] %s' % path)
    print()
    print('[OK] %s → %s，%d 個項目，寫入 %s（%d KB）' % (
        short_old, short_new, len(entries), out_path, (os.path.getsize(out_path) + 1023) // 1024))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
