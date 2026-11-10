<?php
/**
 * PM 射程：讀 config/tool_board.ini [pm] json_path 指定的 JSON 檔
 *
 * 格式：{"APAN01": {"PM": {"window_start_date": "2026-06-01 09:30", "first_due": "...", "due_date": "..."},
 *                   "AMC_PM": {...}}, ...}
 *
 * - 不匯入資料庫；檔案修改時間或大小改變才重新解析，解析結果快取在 storage/cache/
 * - 標準 JSON 讀不到時，嘗試把 Python dict 印法（單引號、u'..'、True/False/None）轉成 JSON 再讀
 * - 壞掉的項目略過並記下原因，看板照常運作
 */
class ToolBoardPm
{
    const CACHE_VERSION = 1;

    private static $parsed = null;

    /**
     * 該機型的 PM 射程
     * @return array array(
     *   'items'   => array(tool_id => array(array('type','start','first','due'), ...)),
     *   'sig'     => 檔案簽章（修改時間-大小），前端用來判斷是否需要重新載入,
     *   'status'  => 'off' | 'ok' | 'missing' | 'error',
     *   'message' => 提示訊息, 'count' => 本機型筆數, 'skipped' => 略過筆數, 'reasons' => 略過原因,
     *   'loose'   => 是否用了單引號容錯)
     */
    public static function forModel($path, $tools)
    {
        $all = self::load($path);
        $items = array();
        $count = 0;
        $index = array_flip($tools);
        foreach ($all['items'] as $tool => $list) {
            if (isset($index[$tool])) {
                $items[$tool] = $list;
                $count += count($list);
            }
        }
        $all['items'] = $items;
        $all['count'] = $count;
        return $all;
    }

    /** 只回傳簽章（輪詢用，不解析） */
    public static function signature($path)
    {
        if ($path === '') {
            return 'off';
        }
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return 'missing';
        }
        return filemtime($path) . '-' . filesize($path);
    }

    private static function load($path)
    {
        if (self::$parsed !== null) {
            return self::$parsed;
        }
        $base = array('items' => array(), 'sig' => self::signature($path), 'status' => 'ok', 'message' => '',
            'count' => 0, 'skipped' => 0, 'reasons' => array(), 'loose' => false);
        if ($path === '') {
            $base['status'] = 'off';
            return self::$parsed = $base;
        }
        if ($base['sig'] === 'missing') {
            $base['status'] = 'missing';
            $base['message'] = '找不到 PM 檔：' . $path;
            return self::$parsed = $base;
        }
        if (!is_readable($path)) {
            $base['status'] = 'error';
            $base['message'] = 'PM 檔無法讀取（權限）：' . $path;
            return self::$parsed = $base;
        }

        $cacheFile = AISLE_STORAGE . '/cache/tool_board_pm_' . md5($path) . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && array_get($cached, 'v') === self::CACHE_VERSION && array_get($cached, 'sig') === $base['sig']) {
                return self::$parsed = $cached['data'];
            }
        }

        $data = self::parseFile($path, $base);
        $dir = dirname($cacheFile);
        if (is_dir($dir) && is_writable($dir)) {
            $tmp = $cacheFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(array('v' => self::CACHE_VERSION, 'sig' => $base['sig'], 'data' => $data))) !== false) {
                @rename($tmp, $cacheFile);
            }
        }
        return self::$parsed = $data;
    }

    private static function parseFile($path, $out)
    {
        $text = (string) file_get_contents($path);
        // 去掉 UTF-8 BOM
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            $json = json_decode(self::pythonToJson($text), true);
            if (!is_array($json)) {
                $out['status'] = 'error';
                $out['message'] = 'PM 檔格式錯誤，無法解析（請確認產生端使用 json.dump 輸出）';
                return $out;
            }
            $out['loose'] = true;
            $out['message'] = 'PM 檔不是標準 JSON（單引號），已自動轉換；請產生端改用 json.dump 輸出';
        }

        foreach ($json as $tool => $pms) {
            $toolId = strtoupper(trim((string) $tool));
            if (!is_array($pms)) {
                self::skip($out, $toolId . '：內容不是物件');
                continue;
            }
            foreach ($pms as $type => $p) {
                $label = $toolId . ' / ' . $type;
                if (!is_array($p)) {
                    self::skip($out, $label . '：內容不是物件');
                    continue;
                }
                $start = self::dt(array_get($p, 'window_start_date'));
                $first = self::dt(array_get($p, 'first_due'));
                $due = self::dt(array_get($p, 'due_date'));
                if ($start === null || $first === null || $due === null) {
                    self::skip($out, $label . '：window_start_date / first_due / due_date 缺少或日期格式錯誤');
                    continue;
                }
                if (!($start <= $first && $first <= $due)) {
                    self::skip($out, $label . '：日期順序需為 window_start_date ≤ first_due ≤ due_date');
                    continue;
                }
                $out['items'][$toolId][] = array(
                    'type'  => (string) $type,
                    'start' => $start,
                    'first' => $first,
                    'due'   => $due,
                );
            }
        }
        foreach ($out['items'] as $t => $list) {
            usort($list, function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
            $out['items'][$t] = $list;
        }
        return $out;
    }

    private static function skip(&$out, $reason)
    {
        $out['skipped']++;
        if (count($out['reasons']) < 50) {
            $out['reasons'][] = $reason;
        }
    }

    /** 'YYYY-MM-DD'、'YYYY-MM-DD HH:MM'、'YYYY/MM/DD HH:MM:SS' → 'Y-m-d H:i:s'；格式錯誤回傳 null */
    private static function dt($v)
    {
        if (!is_string($v)) {
            return null;
        }
        if (!preg_match('/^\s*(\d{4})[\-\/](\d{1,2})[\-\/](\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?\s*$/', $v, $m)) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        $h = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $i = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
        $s = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        if (!checkdate($mo, $d, $y) || $h > 23 || $i > 59 || $s > 59) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $s);
    }

    /**
     * Python dict 的印法轉成 JSON：
     *   'abc' / u'abc' → "abc"；True / False / None → true / false / null；結尾多餘的逗號移除
     * 雙引號字串原樣保留
     */
    public static function pythonToJson($text)
    {
        $out = '';
        $n = strlen($text);
        $i = 0;
        while ($i < $n) {
            $c = $text[$i];
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf = '';
                $i++;
                while ($i < $n && $text[$i] !== $quote) {
                    if ($text[$i] === '\\' && $i + 1 < $n) {
                        $next = $text[$i + 1];
                        if ($next === "'" || $next === '"' || $next === '\\') {
                            $buf .= $next;
                        } elseif ($next === 'n') {
                            $buf .= "\n";
                        } elseif ($next === 't') {
                            $buf .= "\t";
                        } else {
                            $buf .= '\\' . $next;
                        }
                        $i += 2;
                        continue;
                    }
                    $buf .= $text[$i];
                    $i++;
                }
                $i++; // 結尾引號
                $out .= json_encode($buf);
                continue;
            }
            // u'..' / b'..' 前綴
            if (($c === 'u' || $c === 'U' || $c === 'b') && $i + 1 < $n && ($text[$i + 1] === "'" || $text[$i + 1] === '"')
                && ($i === 0 || strpos("{[,: \t\r\n", $text[$i - 1]) !== false)) {
                $i++;
                continue;
            }
            if (preg_match('/\G(True|False|None)\b/', $text, $m, 0, $i)) {
                $map = array('True' => 'true', 'False' => 'false', 'None' => 'null');
                $out .= $map[$m[1]];
                $i += strlen($m[1]);
                continue;
            }
            $out .= $c;
            $i++;
        }
        // 移除 } 或 ] 前多餘的逗號
        return preg_replace('/,\s*([}\]])/', '$1', $out);
    }
}
