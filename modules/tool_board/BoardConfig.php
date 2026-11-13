<?php
/**
 * 讀取借機看板設定：config/tool_board.ini（共用）+ config/tool_board/<機型>.ini（機型專屬）
 *
 *   ToolBoardConfig::models()        機型代碼 => 顯示名稱
 *   ToolBoardConfig::shared()        共用設定（看板、同步、PM）
 *   ToolBoardConfig::forModel($m)    該機型合併後的設定（機台、成員、WIP、類別、可用率）
 *   ToolBoardConfig::modelOfTool($t) 機台屬於哪個機型
 *
 * 每次請求只讀一次檔案。設定錯誤丟 ToolBoardConfigException，頁面會顯示訊息
 */
class ToolBoardConfigException extends RuntimeException
{
}

class ToolBoardConfig
{
    private static $shared = null;
    private static $models = array();

    /** 共用設定 */
    public static function shared()
    {
        if (self::$shared !== null) {
            return self::$shared;
        }
        $ini = self::parse(AISLE_ROOT . '/config/tool_board.ini', 'config/tool_board.ini');

        $models = array();
        foreach (array_get($ini, 'models', array()) as $k => $v) {
            $k = strtoupper(trim($k));
            if (preg_match('/^[A-Z0-9_]{1,16}$/', $k)) {
                $models[$k] = (string) $v;
            }
        }
        if (!$models) {
            throw new ToolBoardConfigException('config/tool_board.ini 的 [models] 沒有任何機型');
        }

        $board = array_get($ini, 'board', array());
        $sync = array_get($ini, 'sync', array());
        $pm = array_get($ini, 'pm', array());

        $sessionIdle = (int) Config::get('security', 'session_idle', 1800);
        $idle = max(30, (int) array_get($sync, 'idle_seconds', 600));
        if ($sessionIdle > 0 && $idle >= $sessionIdle) {
            $idle = max(30, $sessionIdle - 60);
        }
        $startTime = trim((string) array_get($board, 'default_start_time', '08:00'));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
            $startTime = '08:00';
        }

        self::$shared = array(
            'models'             => $models,
            'days_before'        => max(0, min(30, (int) array_get($board, 'days_before', 3))),
            'days_after'         => max(7, min(366, (int) array_get($board, 'days_after', 60))),
            'cell_width'         => max(24, min(120, (int) array_get($board, 'cell_width', 44))),
            'default_start_time' => $startTime,
            'categories'         => self::categories($ini, array()),
            'availability'       => self::availability(array_get($ini, 'availability', array()), array()),
            'pm_path'            => trim((string) array_get($pm, 'json_path', '')),
            'pm_category'        => trim((string) array_get($pm, 'category', 'pm')),
            'poll_seconds'       => max(2, (int) array_get($sync, 'poll_seconds', 5)),
            'presence_seconds'   => max(6, (int) array_get($sync, 'presence_seconds', 20)),
            'idle_seconds'       => $idle,
        );
        return self::$shared;
    }

    public static function models()
    {
        $s = self::shared();
        return $s['models'];
    }

    /** 該機型合併後的設定 */
    public static function forModel($model)
    {
        if (isset(self::$models[$model])) {
            return self::$models[$model];
        }
        $shared = self::shared();
        if (!isset($shared['models'][$model])) {
            throw new ToolBoardConfigException('沒有這個機型：' . $model);
        }
        $rel = 'config/tool_board/' . $model . '.ini';
        $file = AISLE_ROOT . '/' . $rel;
        if (!is_file($file)) {
            throw new ToolBoardConfigException('找不到機型設定檔 ' . $rel);
        }
        $ini = self::parse($file, $rel);

        // 機台：大寫、去重複，保留順序
        $tools = array();
        foreach ((array) array_get(array_get($ini, 'tools', array()), 'tool', array()) as $t) {
            $t = strtoupper(trim((string) $t));
            if ($t !== '' && preg_match('/^[A-Z0-9][A-Z0-9_.\-]{0,63}$/', $t) && !in_array($t, $tools, true)) {
                $tools[] = $t;
            }
        }

        $editors = array();
        foreach ((array) array_get(array_get($ini, 'editors', array()), 'account', array()) as $a) {
            $a = strtolower(trim((string) $a));
            if ($a !== '') {
                $editors[$a] = true;
            }
        }

        $wip = array();
        foreach ($ini as $section => $v) {
            if (strpos($section, 'wip.') === 0 && is_array($v)) {
                $code = substr($section, 4);
                if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code)) {
                    continue;
                }
                $wip[$code] = array(
                    'label'      => (string) array_get($v, 'label', $code),
                    'text_color' => self::color(array_get($v, 'text_color', ''), '#FFFFFF'),
                    'bold'       => (bool) array_get($v, 'bold', false),
                );
            }
        }
        if (!$wip) {
            throw new ToolBoardConfigException($rel . ' 沒有任何 [wip.<代碼>]（WIP 處理方式）');
        }

        self::$models[$model] = array(
            'model'        => $model,
            'label'        => $shared['models'][$model],
            'tools'        => $tools,
            'editors'      => $editors,
            'wip'          => $wip,
            'categories'   => self::categories($ini, $shared['categories']),
            'availability' => self::availability(array_get($ini, 'availability', array()), $shared['availability']),
        );
        return self::$models[$model];
    }

    /** 機台屬於哪個機型（找不到回傳 ''） */
    public static function modelOfTool($toolId)
    {
        foreach (self::models() as $m => $label) {
            try {
                $cfg = self::forModel($m);
            } catch (ToolBoardConfigException $e) {
                continue;
            }
            if (in_array($toolId, $cfg['tools'], true)) {
                return $m;
            }
        }
        return '';
    }

    /** 同一台列在多個機型：回傳說明文字陣列 */
    public static function duplicateTools()
    {
        $seen = array();
        $out = array();
        foreach (self::models() as $m => $label) {
            try {
                $cfg = self::forModel($m);
            } catch (ToolBoardConfigException $e) {
                continue;
            }
            foreach ($cfg['tools'] as $t) {
                if (isset($seen[$t])) {
                    $out[] = $t . ' 同時列在 ' . $seen[$t] . ' 與 ' . $m;
                } else {
                    $seen[$t] = $m;
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------

    private static function parse($file, $label)
    {
        if (!is_file($file)) {
            throw new ToolBoardConfigException('找不到設定檔 ' . $label);
        }
        $ini = @parse_ini_file($file, true);
        if ($ini === false) {
            throw new ToolBoardConfigException('INI 解析失敗：' . $label . '（字串請加雙引號）');
        }
        return $ini;
    }

    /** [category.<代碼>]：$base 為共用設定，同代碼逐項覆蓋、新代碼加在後面 */
    private static function categories($ini, $base)
    {
        $out = $base;
        foreach ($ini as $section => $v) {
            if (strpos($section, 'category.') !== 0 || !is_array($v)) {
                continue;
            }
            $code = substr($section, 9);
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code)) {
                continue;
            }
            $old = isset($out[$code]) ? $out[$code] : array(
                'label' => $code, 'color' => '#3A7BD5', 'counts_down' => true, 'default_hours' => 8, 'default_wip' => '',
            );
            $out[$code] = array(
                'label'         => array_key_exists('label', $v) ? (string) $v['label'] : $old['label'],
                'color'         => array_key_exists('color', $v) ? self::color($v['color'], $old['color']) : $old['color'],
                'counts_down'   => array_key_exists('counts_down', $v) ? (bool) $v['counts_down'] : $old['counts_down'],
                'default_hours' => array_key_exists('default_hours', $v) ? max(0.5, min(720, (float) $v['default_hours'])) : $old['default_hours'],
                'default_wip'   => array_key_exists('default_wip', $v) ? trim((string) $v['default_wip']) : $old['default_wip'],
            );
        }
        return $out;
    }

    private static function availability($v, $base)
    {
        $d = $base ? $base : array(
            'formula' => 'default', 'count_monitor' => true, 'count_pilot' => false, 'alert_below' => 80.0,
        );
        if (array_key_exists('formula', $v)) {
            $d['formula'] = strtolower(trim((string) $v['formula']));
        }
        foreach (array('count_monitor', 'count_pilot') as $k) {
            if (array_key_exists($k, $v)) {
                $d[$k] = (bool) $v[$k];
            }
        }
        // 只有一個門檻：低於 alert_below 紅底（舊設定的 warn_below 已不使用，寫了也忽略）
        if (array_key_exists('alert_below', $v) && trim((string) $v['alert_below']) !== '') {
            $d['alert_below'] = (float) $v['alert_below'];
        }
        return $d;
    }

    private static function color($value, $default)
    {
        $value = trim((string) $value);
        return preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value) ? strtoupper($value) : $default;
    }
}
