<?php
/**
 * 選機表數值：讀 config/tool_select/<機型>.ini [page] json_path 指定的 JSON 檔
 *
 * 格式（機台 → 項目 → 值）：
 *   {"updated": "2026-11-15 08:00",
 *    "data": {"APAN01": {"M1BF_Ry": 3.21, "LUSU": "Down", "SAMOS": {"v": -3.4, "t": "2026-11-15 07:30"}}}}
 *   也接受省略 "data" 這一層：{"APAN01": {...}, ...}
 *
 * - 不匯入資料庫；檔案修改時間或大小改變才重新解析，解析結果快取在 storage/cache/
 * - 標準 JSON 讀不到時，嘗試把 Python dict 印法（單引號、True/False/None）轉成 JSON 再讀
 *   （轉換函式沿用 tool_board 的 ToolBoardPm::pythonToJson）
 * - 機台代號一律大寫比對；項目名稱不分大小寫比對
 */
require_once AISLE_MODULES . '/tool_board/PmSource.php';

class ToolSelectValues
{
    const CACHE_VERSION = 1;
    const MAX_TEXT = 32;

    /**
     * @return array array(
     *   'status'  => 'off' | 'ok' | 'missing' | 'error' | 'sample',
     *   'message' => 提示訊息,
     *   'updated' => 資料時間（JSON 的 updated，沒有則為檔案修改時間）,
     *   'values'  => array(項目 id => array(機台 => array('v' => 數字|null, 's' => 狀態|null, 't' => 時間|null))))
     */
    public static function forModel($cfg)
    {
        if ($cfg['sample']) {
            return self::sample($cfg);
        }
        $all = self::load($cfg['json_path']);
        $values = array();
        if ($all['status'] === 'ok') {
            foreach ($cfg['items'] as $it) {
                $key = strtolower($it['key']);
                foreach ($cfg['tools'] as $tool) {
                    if (isset($all['data'][$tool][$key])) {
                        $values[$it['id']][$tool] = $all['data'][$tool][$key];
                    }
                }
            }
        }
        return array(
            'status'  => $all['status'],
            'message' => $all['message'],
            'updated' => $all['updated'],
            'values'  => $values,
        );
    }

    // ------------------------------------------------------------------

    private static function load($path)
    {
        $base = array('status' => 'ok', 'message' => '', 'updated' => '', 'data' => array());
        if ($path === '') {
            $base['status'] = 'off';
            $base['message'] = '尚未設定數值 JSON 檔（機型設定檔 [page] json_path）';
            return $base;
        }
        clearstatcache(true, $path);
        if (!is_file($path)) {
            $base['status'] = 'missing';
            $base['message'] = '找不到數值檔：' . $path;
            return $base;
        }
        if (!is_readable($path)) {
            $base['status'] = 'error';
            $base['message'] = '數值檔無法讀取（權限）：' . $path;
            return $base;
        }
        $sig = filemtime($path) . '-' . filesize($path);

        $cacheFile = AISLE_STORAGE . '/cache/tool_select_' . md5($path) . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && array_get($cached, 'v') === self::CACHE_VERSION && array_get($cached, 'sig') === $sig) {
                return $cached['data'];
            }
        }

        $data = self::parseFile($path, $base);
        if ($data['updated'] === '') {
            $data['updated'] = date('Y-m-d H:i:s', filemtime($path));
        }
        $dir = dirname($cacheFile);
        if (is_dir($dir) && is_writable($dir)) {
            $tmp = $cacheFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(array('v' => self::CACHE_VERSION, 'sig' => $sig, 'data' => $data))) !== false) {
                @rename($tmp, $cacheFile);
            }
        }
        return $data;
    }

    private static function parseFile($path, $out)
    {
        $text = (string) file_get_contents($path);
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            $json = json_decode(ToolBoardPm::pythonToJson($text), true);
            if (!is_array($json)) {
                $out['status'] = 'error';
                $out['message'] = '數值檔格式錯誤，無法解析（請確認產生端使用 json.dump 輸出）';
                return $out;
            }
            $out['message'] = '數值檔不是標準 JSON（單引號），已自動轉換；請產生端改用 json.dump 輸出';
        }

        if (isset($json['updated']) && is_string($json['updated'])) {
            $out['updated'] = self::text($json['updated']);
        }
        $tools = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : $json;
        foreach ($tools as $tool => $items) {
            if (!is_array($items) || $tool === 'updated') {
                continue;
            }
            $toolId = strtoupper(trim((string) $tool));
            foreach ($items as $key => $raw) {
                $cell = self::cell($raw);
                if ($cell !== null) {
                    $out['data'][$toolId][strtolower(trim((string) $key))] = $cell;
                }
            }
        }
        return $out;
    }

    /** 一格的值 → array('v','s','t')；沒有值回傳 null */
    private static function cell($raw)
    {
        $t = null;
        if (is_array($raw)) {
            if (isset($raw['t']) && is_scalar($raw['t'])) {
                $t = self::text($raw['t']);
            }
            $raw = array_key_exists('v', $raw) ? $raw['v'] : null;
        }
        if ($raw === null || is_array($raw) || is_bool($raw)) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return array('v' => (float) $raw, 's' => null, 't' => $t);
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (is_numeric($s)) {
            return array('v' => (float) $s, 's' => null, 't' => $t);
        }
        return array('v' => null, 's' => self::text($s), 't' => $t);
    }

    /** 文字：最多 32 個字（以 UTF-8 字元為單位，不依賴 mbstring） */
    private static function text($v)
    {
        $v = trim((string) $v);
        return preg_match('/^.{0,' . self::MAX_TEXT . '}/us', $v, $m) ? $m[0] : substr($v, 0, self::MAX_TEXT);
    }

    /** 範例值：依機台 + 項目產生固定的數值（每次相同），大部分在規格內，少數超出 OOC / OOS */
    private static function sample($cfg)
    {
        $values = array();
        foreach ($cfg['items'] as $it) {
            foreach ($cfg['tools'] as $tool) {
                $h = abs(crc32($tool . '|' . $it['id']));
                $s = ToolSelectConfig::specOf($it, $tool);
                $hi = $s[0] !== null ? $s[0] : ($s[1] !== null ? $s[1] * 1.1 : 10);
                $lo = $s[3] !== null ? $s[3] : ($s[2] !== null ? $s[2] * 1.1 : 0);
                if ($s[3] === null && $s[2] === null) {
                    $lo = $hi > 0 ? 0 : $hi * 2;
                }
                $r = ($h % 1000) / 1000;          // 0 ~ 1
                if ($h % 23 === 0) {
                    $values[$it['id']][$tool] = array('v' => null, 's' => 'Down', 't' => null);
                    continue;
                }
                if ($h % 17 === 0) {
                    continue;                      // 沒有資料
                }
                $span = $hi - $lo;
                $v = $lo + $span * (0.05 + $r * 1.0);   // 少數會超出上限
                if ($h % 11 === 0) {
                    $v = $lo - $span * 0.06;            // 少數低於下限
                }
                $values[$it['id']][$tool] = array('v' => round($v, 3), 's' => null, 't' => date('Y-m-d') . ' 08:00');
            }
        }
        return array(
            'status'  => 'sample',
            'message' => '目前使用範例值（機型設定檔 [page] sample = On）。準備好 JSON 檔後填 json_path 並把 sample 改成 Off。',
            'updated' => date('Y-m-d') . ' 08:00',
            'values'  => $values,
        );
    }
}
