<?php
/**
 * 讀取 config/chart_demo.ini：哪些參數畫在同一張圖、各區塊是否顯示
 *
 * ChartConfig::groups() 回傳（依檔案順序）：
 *   array(
 *     array('id' => 'm1bf_z', 'title' => 'M1BF_Z', 'params' => array('M1BF_Z_C1', 'M1BF_Z_C2'),
 *           'unit' => 'Data', 'single' => true, 'newest' => true, 'tbt' => true,
 *           'limits' => array('single' => array('min' => 0, 'max' => 5), 'newest' => array(...), 'tbt' => array(...))),
 *   min / max 為 null 表示自動
 *     ...
 *   )
 */
class ChartConfig
{
    private static $groups = null;

    public static function file()
    {
        return AISLE_ROOT . '/config/chart_demo.ini';
    }

    public static function groups()
    {
        if (self::$groups !== null) {
            return self::$groups;
        }
        $file = self::file();
        if (!is_file($file)) {
            throw new RuntimeException('找不到 config/chart_demo.ini');
        }
        $ini = parse_ini_file($file, true);
        if ($ini === false) {
            throw new RuntimeException('config/chart_demo.ini 解析失敗，請檢查引號與語法');
        }

        $groups = array();
        foreach ($ini as $section => $conf) {
            if (!preg_match('/^chart\.([a-z][a-z0-9_]{0,63})$/', $section, $m)) {
                throw new RuntimeException('config/chart_demo.ini 區段名稱錯誤：[' . $section . ']，應為 [chart.<小寫代號>]');
            }
            $params = array();
            foreach (explode(',', (string) array_get($conf, 'params', '')) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $params, true)) {
                    $params[] = $p;
                }
            }
            if (!$params) {
                throw new RuntimeException('config/chart_demo.ini [' . $section . '] 沒有設定 params');
            }
            $groups[] = array(
                'id'     => $m[1],
                'title'  => (string) array_get($conf, 'title', $m[1]),
                'params' => $params,
                'unit'   => (string) array_get($conf, 'unit', 'Data'),
                'single' => self::flag($conf, 'single'),
                'newest' => self::flag($conf, 'newest'),
                'tbt'    => self::flag($conf, 'tbt'),
                'limits' => array(
                    'single' => self::limits($conf, 'single', $section),
                    'newest' => self::limits($conf, 'newest', $section),
                    'tbt'    => self::limits($conf, 'tbt', $section),
                ),
            );
        }
        if (!$groups) {
            throw new RuntimeException('config/chart_demo.ini 沒有任何 [chart.xxx] 圖表');
        }
        self::$groups = $groups;
        return $groups;
    }

    /** 所有圖表用到的參數（不重複，依出現順序） */
    public static function params()
    {
        $out = array();
        foreach (self::groups() as $g) {
            foreach ($g['params'] as $p) {
                if (!in_array($p, $out, true)) {
                    $out[] = $p;
                }
            }
        }
        return $out;
    }

    /**
     * Y 軸上下限：<區塊>_ymin / <區塊>_ymax 優先，沒有時用整組的 ymin / ymax，都沒有（或空字串）為自動
     */
    private static function limits($conf, $kind, $section)
    {
        $out = array();
        foreach (array('min', 'max') as $k) {
            $v = null;
            foreach (array($kind . '_y' . $k, 'y' . $k) as $key) {
                if (array_key_exists($key, $conf) && trim((string) $conf[$key]) !== '') {
                    $raw = trim((string) $conf[$key]);
                    if (!is_numeric($raw)) {
                        throw new RuntimeException('config/chart_demo.ini [' . $section . '] ' . $key . ' 必須是數字：' . $raw);
                    }
                    $v = (float) $raw;
                    break;
                }
            }
            $out[$k] = $v;
        }
        if ($out['min'] !== null && $out['max'] !== null && $out['min'] >= $out['max']) {
            throw new RuntimeException('config/chart_demo.ini [' . $section . '] ' . $kind . ' 的 Y 軸下限必須小於上限');
        }
        return $out;
    }

    /** On / Off；未設定時為 On。parse_ini_file 會把 On 轉成 "1"、Off 轉成 "" */
    private static function flag($conf, $key)
    {
        if (!array_key_exists($key, $conf)) {
            return true;
        }
        $v = strtolower(trim((string) $conf[$key]));
        return !($v === '' || $v === '0' || $v === 'off' || $v === 'false' || $v === 'no');
    }
}
