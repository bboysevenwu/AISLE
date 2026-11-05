<?php
/**
 * 讀取一張 chart 的設定檔 charts/<名稱>.ini
 *
 * 每張 chart（BSLr、M1BF、CDM…）在 charts/ 底下有兩個同名檔案：
 *   charts/<名稱>.ini   畫什麼、怎麼畫（本類別讀取）
 *   charts/<名稱>.php   部署者寫 SQL、整理成資料格式（見 ChartSource.php）
 *
 * config/config.ini 的頁面代號 = chart 名稱轉小寫：[page.bslr] → charts/BSLr.ini
 * 以 _ 開頭的檔案（_template.ini）是範本，不會被當成 chart。
 *
 * ini 格式：
 *   [page]          整頁設定：source、default_days、max_days、quick_days、model.<機型> = "<機台名稱包含的字>"
 *   [plot.<代號>]   一組圖：title、params、unit、single / newest / tbt、connect、ymin / ymax、<區塊>_ymin / <區塊>_ymax
 *
 * $cfg->plots() 回傳（依檔案順序）：
 *   array(
 *     array('id' => 'm1bf_z', 'title' => 'M1BF_Z', 'params' => array('M1BF_Z_C1', 'M1BF_Z_C2'),
 *           'unit' => 'Data', 'single' => true, 'newest' => true, 'tbt' => true, 'connect' => false,
 *           'limits' => array('single' => array('min' => 0, 'max' => 5), 'newest' => array(...), 'tbt' => array(...))),
 *     ...
 *   )
 *   min / max 為 null 表示自動
 */
class ChartConfig
{
    const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,31}$/';

    /** chart 名稱，例 BSLr */
    public $name;
    /** charts/<名稱>.ini 的完整路徑 */
    public $file;

    private $page = array();
    private $plots = array();
    private $models = array();

    public static function dir()
    {
        return AISLE_ROOT . '/charts';
    }

    /** charts/ 底下所有 chart 名稱（不含 _ 開頭的範本） */
    public static function names()
    {
        $names = array();
        $files = glob(self::dir() . '/*.ini');
        foreach ($files ? $files : array() as $f) {
            $name = basename($f, '.ini');
            if (preg_match(self::NAME_PATTERN, $name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** 頁面代號 → chart 名稱（不分大小寫比對檔名），找不到回傳 null */
    public static function nameForPage($pageKey)
    {
        foreach (self::names() as $name) {
            if (strtolower($name) === strtolower($pageKey)) {
                return $name;
            }
        }
        return null;
    }

    public function __construct($name)
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new RuntimeException('chart 名稱格式錯誤：' . $name);
        }
        $this->name = $name;
        $this->file = self::dir() . '/' . $name . '.ini';
        $label = 'charts/' . $name . '.ini';
        if (!is_file($this->file)) {
            throw new RuntimeException('找不到 ' . $label);
        }
        $ini = parse_ini_file($this->file, true);
        if ($ini === false) {
            throw new RuntimeException($label . ' 解析失敗，請檢查引號與語法');
        }

        foreach ($ini as $section => $conf) {
            if ($section === 'page') {
                $this->page = $conf;
                continue;
            }
            if (!preg_match('/^plot\.([a-z][a-z0-9_]{0,63})$/', $section, $m)) {
                throw new RuntimeException($label . ' 區段名稱錯誤：[' . $section . ']，應為 [page] 或 [plot.<小寫代號>]');
            }
            $this->plots[] = $this->plot($m[1], $conf, $label, $section);
        }
        if (!$this->plots) {
            throw new RuntimeException($label . ' 沒有任何 [plot.xxx] 圖');
        }

        foreach ($this->page as $k => $v) {
            if (strpos($k, 'model.') === 0 && substr($k, 6) !== '' && trim((string) $v) !== '') {
                $this->models[substr($k, 6)] = trim((string) $v);
            }
        }

        $this->checkPage($label);
    }

    // ------------------------------------------------------------------
    // [page]

    /** php = 用 charts/<名稱>.php 查資料庫；sample = 內建範例資料 */
    public function source()
    {
        $v = strtolower(trim((string) array_get($this->page, 'source', 'php')));
        return $v === 'sample' ? 'sample' : 'php';
    }

    public function defaultDays()
    {
        return (int) array_get($this->page, 'default_days', 60);
    }

    public function maxDays()
    {
        return (int) array_get($this->page, 'max_days', 366);
    }

    /** 快速區間按鈕的天數 */
    public function quickDays()
    {
        $out = array();
        foreach (explode(',', (string) array_get($this->page, 'quick_days', '7, 30, 90')) as $d) {
            $d = (int) trim($d);
            if ($d > 0) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** 機型 => 機台名稱包含的字，例 array('1980' => 'PAN', '2000' => 'PAP')；沒設定時為空陣列 */
    public function models()
    {
        return $this->models;
    }

    /** 機台屬於哪個機型：依 [page] 的順序，名稱包含該字（不分大小寫）的第一個機型；都不符合回傳 '' */
    public function modelOf($tool)
    {
        foreach ($this->models as $model => $needle) {
            if (stripos($tool, $needle) !== false) {
                return (string) $model;
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // [plot.xxx]

    public function plots()
    {
        return $this->plots;
    }

    /** 所有圖用到的參數（不重複，依出現順序） */
    public function params()
    {
        $out = array();
        foreach ($this->plots as $g) {
            foreach ($g['params'] as $p) {
                if (!in_array($p, $out, true)) {
                    $out[] = $p;
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------

    private function checkPage($label)
    {
        $source = strtolower(trim((string) array_get($this->page, 'source', 'php')));
        if (!in_array($source, array('php', 'sample'), true)) {
            throw new RuntimeException($label . ' [page] source 只能是 php 或 sample');
        }
        foreach (array('default_days', 'max_days') as $k) {
            if (array_key_exists($k, $this->page) && (!ctype_digit(trim((string) $this->page[$k])) || (int) $this->page[$k] < 1)) {
                throw new RuntimeException($label . ' [page] ' . $k . ' 必須是正整數');
            }
        }
        if ($this->defaultDays() > $this->maxDays()) {
            throw new RuntimeException($label . ' [page] default_days 不可大於 max_days');
        }
    }

    private function plot($id, $conf, $label, $section)
    {
        $params = array();
        foreach (explode(',', (string) array_get($conf, 'params', '')) as $p) {
            $p = trim($p);
            if ($p !== '' && !in_array($p, $params, true)) {
                $params[] = $p;
            }
        }
        if (!$params) {
            throw new RuntimeException($label . ' [' . $section . '] 沒有設定 params');
        }
        return array(
            'id'     => $id,
            'title'  => (string) array_get($conf, 'title', $id),
            'params' => $params,
            'unit'   => (string) array_get($conf, 'unit', 'Data'),
            'single' => self::flag($conf, 'single'),
            'newest' => self::flag($conf, 'newest'),
            'tbt'    => self::flag($conf, 'tbt'),
            'connect' => self::flag($conf, 'connect', false),
            'limits' => array(
                'single' => self::limits($conf, 'single', $label, $section),
                'newest' => self::limits($conf, 'newest', $label, $section),
                'tbt'    => self::limits($conf, 'tbt', $label, $section),
            ),
        );
    }

    /**
     * Y 軸上下限：<區塊>_ymin / <區塊>_ymax 優先，沒有時用整組的 ymin / ymax，都沒有（或空字串）為自動
     */
    private static function limits($conf, $kind, $label, $section)
    {
        $out = array();
        foreach (array('min', 'max') as $k) {
            $v = null;
            foreach (array($kind . '_y' . $k, 'y' . $k) as $key) {
                if (array_key_exists($key, $conf) && trim((string) $conf[$key]) !== '') {
                    $raw = trim((string) $conf[$key]);
                    if (!is_numeric($raw)) {
                        throw new RuntimeException($label . ' [' . $section . '] ' . $key . ' 必須是數字：' . $raw);
                    }
                    $v = (float) $raw;
                    break;
                }
            }
            $out[$k] = $v;
        }
        if ($out['min'] !== null && $out['max'] !== null && $out['min'] >= $out['max']) {
            throw new RuntimeException($label . ' [' . $section . '] ' . $kind . ' 的 Y 軸下限必須小於上限');
        }
        return $out;
    }

    /** On / Off；未設定時為 $default。parse_ini_file 會把 On 轉成 "1"、Off 轉成 "" */
    private static function flag($conf, $key, $default = true)
    {
        if (!array_key_exists($key, $conf)) {
            return $default;
        }
        $v = strtolower(trim((string) $conf[$key]));
        return !($v === '' || $v === '0' || $v === 'off' || $v === 'false' || $v === 'no');
    }
}
