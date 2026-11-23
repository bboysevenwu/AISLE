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
 *   [db]            這張 chart 查資料用的資料庫連線：ip、port、name、user、password、charset
 *                   ip 沒填（或沒有 [db]）時沿用 AISLE 自己的資料庫（config/app.ini 的 [database]）
 *   [plot.<代號>]   一組圖：title、params、unit、single / newest / tbt、connect、ymin / ymax、<區塊>_ymin / <區塊>_ymax、
 *                   spec 線 upper_oos / upper_ooc / lower_ooc / lower_oos（可留空）、
 *                   target / target.<機台或機型> / target.<機台>.<參數>（有寫任何一行 → 偏移模式）、
 *                   spec 覆蓋 upper_ooc.<機台或機型> 等（機型 = [page] model.<機型> 的名稱或比對字）
 *
 * $cfg->plots() 回傳（依檔案順序）：
 *   array(
 *     array('id' => 'm1bf_z', 'title' => 'M1BF_Z', 'params' => array('M1BF_Z_C1', 'M1BF_Z_C2'),
 *           'unit' => 'Data', 'single' => true, 'newest' => true, 'tbt' => true, 'connect' => false,
 *           'limits' => array('single' => array('min' => 0, 'max' => 5), 'newest' => array(...), 'tbt' => array(...)),
 *           'spec'   => array('upper_oos' => 6, 'upper_ooc' => 5, 'lower_ooc' => null, 'lower_oos' => null),
 *           'offset' => true, 'target' => 0,
 *           'targets' => array('apan01' => array('*' => 27, 'M1BF_Z_C2' => 26.5)),
 *           'targetModel' => array('2000' => 26),
 *           'specTool'  => array('apap01' => array('upper_ooc' => 4.6, 'lower_oos' => null)),
 *           'specModel' => array('2000' => array('upper_ooc' => 4.8))),
 *     ...
 *   )
 *   min / max 為 null 表示自動；spec 為 null 表示不畫線
 *   offset = false：spec 是絕對值（沒寫任何 target 的組，維持原本行為）
 *   offset = true ：spec 是距離 target 的偏移量（一律 >= 0），線畫在 target ± 偏移；
 *                   targets 的機台一律小寫（比對不分大小寫），'*' = 該台所有參數
 *   specTool / specModel：只列有覆蓋的條；null = 這台 / 這個機型不畫這一條。每一條依 機台 → 機型 → 整組 取值
 *
 * [db] 只給伺服器端的 ChartSource 使用，不會出現在 plots()，不會送到瀏覽器。
 */
class ChartConfig
{
    const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,31}$/';

    /** chart 名稱，例 BSLr */
    public $name;
    /** charts/<名稱>.ini 的完整路徑 */
    public $file;

    private $page = array();
    private $db = array();
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

        $plotSections = array();
        foreach ($ini as $section => $conf) {
            if ($section === 'page') {
                $this->page = $conf;
                continue;
            }
            if ($section === 'db') {
                $this->db = $conf;
                continue;
            }
            if (!preg_match('/^plot\.([a-z][a-z0-9_]{0,63})$/', $section, $m)) {
                throw new RuntimeException($label . ' 區段名稱錯誤：[' . $section . ']，應為 [page]、[db] 或 [plot.<小寫代號>]');
            }
            $plotSections[] = array($m[1], $conf, $section);
        }

        // 機型要先讀，[plot.x] 的機型覆蓋（upper_ooc.PAP、target.2000）需要用到
        foreach ($this->page as $k => $v) {
            if (strpos($k, 'model.') === 0 && substr($k, 6) !== '' && trim((string) $v) !== '') {
                $this->models[substr($k, 6)] = trim((string) $v);
            }
        }

        foreach ($plotSections as $ps) {
            $this->plots[] = $this->plot($ps[0], $ps[1], $label, $ps[2]);
        }
        if (!$this->plots) {
            throw new RuntimeException($label . ' 沒有任何 [plot.xxx] 圖');
        }

        $this->checkPage($label);
        $this->checkDb($label);
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
    // [db]

    /**
     * 這張 chart 的資料庫連線設定；ip 沒填時回傳 null（沿用 AISLE 自己的資料庫）
     * 回傳 array('ip', 'port', 'name', 'user', 'password', 'charset')
     */
    public function db()
    {
        $ip = trim((string) array_get($this->db, 'ip', ''));
        if ($ip === '') {
            return null;
        }
        $port = trim((string) array_get($this->db, 'port', ''));
        $charset = trim((string) array_get($this->db, 'charset', ''));
        return array(
            'ip'       => $ip,
            'port'     => $port === '' ? 3306 : (int) $port,
            'name'     => trim((string) array_get($this->db, 'name', '')),
            'user'     => (string) array_get($this->db, 'user', ''),
            'password' => (string) array_get($this->db, 'password', ''),
            'charset'  => $charset === '' ? 'utf8mb4' : $charset,
        );
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

    private function checkDb($label)
    {
        $allowed = array('ip', 'port', 'name', 'user', 'password', 'charset');
        foreach ($this->db as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                throw new RuntimeException($label . ' [db] 不認得的欄位：' . $k . '（可用：' . implode('、', $allowed) . '）');
            }
        }
        $ip = trim((string) array_get($this->db, 'ip', ''));
        if ($ip !== '' && !preg_match('/^[A-Za-z0-9._:-]+$/', $ip)) {
            throw new RuntimeException($label . ' [db] ip 格式錯誤（只能是 IP 或主機名稱）');
        }
        $port = trim((string) array_get($this->db, 'port', ''));
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new RuntimeException($label . ' [db] port 必須是 1 ~ 65535 的整數');
        }
        foreach (array('name', 'charset') as $k) {
            $v = trim((string) array_get($this->db, $k, ''));
            if ($v !== '' && !preg_match('/^[A-Za-z0-9_$-]+$/', $v)) {
                throw new RuntimeException($label . ' [db] ' . $k . ' 只能有英文、數字、底線、$、-');
            }
        }
        if (trim((string) array_get($this->db, 'ip', '')) === '') {
            foreach (array('name', 'user', 'password') as $k) {
                if (trim((string) array_get($this->db, $k, '')) !== '') {
                    throw new RuntimeException($label . ' [db] 有填 ' . $k . ' 但沒有填 ip（ip 留空 = 使用 AISLE 自己的資料庫）');
                }
            }
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
        $out = array(
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
        $t = $this->targets($conf, $params, $label, $section);
        $out['spec'] = self::spec($conf, $label, $section, $t['offset']);
        $out = array_merge($out, $t, $this->specOverrides($conf, $out['spec'], $t['offset'], $label, $section));
        return $out;
    }

    /**
     * 名稱是機型嗎？與 [page] model.<機型> 的機型名稱（例 2000）或比對字（例 PAP）相同（不分大小寫）
     * 是的話回傳機型名稱，否則回傳 null（當成機台）
     */
    private function modelKey($name)
    {
        foreach ($this->models as $model => $needle) {
            if (strcasecmp((string) $model, $name) === 0 || strcasecmp($needle, $name) === 0) {
                return (string) $model;
            }
        }
        return null;
    }

    /**
     * spec 覆蓋：upper_oos.<機台或機型> 等（只寫要不同的那幾條；留空 = 這台 / 這個機型不畫這一條）
     * 每一條各自依 機台 → 機型 → 整組 取值。偏移模式時覆蓋值也是偏移量
     * 回傳 array('specTool' => array(機台小寫 => array(key => 值|null)), 'specModel' => array(機型 => array(key => 值|null)))
     * 整組、每個機型、每台有覆蓋的機台（合併機型後）都檢查大小關係
     */
    private function specOverrides($conf, $spec, $offset, $label, $section)
    {
        $keys = array('upper_oos', 'upper_ooc', 'lower_ooc', 'lower_oos');
        $byTool = array();
        $byModel = array();
        foreach ($conf as $k => $v) {
            $k = (string) $k;
            $dot = strpos($k, '.');
            if ($dot === false || !in_array(substr($k, 0, $dot), $keys, true)) {
                continue;
            }
            $key = substr($k, 0, $dot);
            $name = trim(substr($k, $dot + 1));
            if ($name === '') {
                throw new RuntimeException($label . ' [' . $section . '] ' . $k . ' 格式錯誤，應為 ' . $key . '.<機台或機型>');
            }
            $raw = trim((string) $v);
            if ($raw !== '' && !is_numeric($raw)) {
                throw new RuntimeException($label . ' [' . $section . '] ' . $k . ' 必須是數字或留空：' . $raw);
            }
            $val = $raw === '' ? null : (float) $raw;
            $model = $this->modelKey($name);
            if ($model !== null) {
                $byModel[$model][$key] = $val;
            } else {
                $byTool[strtolower($name)][$key] = $val;
            }
        }
        $where = $label . ' [' . $section . '] ';
        foreach ($byModel as $model => $o) {
            self::checkSpecValues(array_merge($spec, $o), $where . '機型 ' . $model . '：', $offset);
        }
        foreach ($byTool as $tool => $o) {
            $model = $this->modelOf($tool);
            $base = ($model !== '' && isset($byModel[$model])) ? array_merge($spec, $byModel[$model]) : $spec;
            self::checkSpecValues(array_merge($base, $o), $where . '機台 ' . strtoupper($tool) . '：', $offset);
        }
        return array('specTool' => $byTool, 'specModel' => $byModel);
    }

    /**
     * target：寫了任何一行 target / target.<機台或機型> / target.<機台>.<參數>（含留空的 target =）→ 偏移模式
     * 取值順序：target.<機台>.<參數> → target.<機台> → target.<機型> → target → 0
     * 機台不分大小寫（存成小寫）；機型寫機型名稱或比對字都可以；參數必須在這組的 params 裡（區分大小寫）
     * 機台不檢查是否存在（機台清單是執行時從資料庫取得），不存在的機台就用不到
     */
    private function targets($conf, $params, $label, $section)
    {
        $offset = false;
        $default = 0.0;
        $targets = array();
        $modelTargets = array();
        foreach ($conf as $k => $v) {
            $k = (string) $k;
            if ($k !== 'target' && strpos($k, 'target.') !== 0) {
                continue;
            }
            $offset = true;
            $raw = trim((string) $v);
            if ($raw !== '' && !is_numeric($raw)) {
                throw new RuntimeException($label . ' [' . $section . '] ' . $k . ' 必須是數字或留空：' . $raw);
            }
            if ($k === 'target') {
                if ($raw !== '') {
                    $default = (float) $raw;
                }
                continue;
            }
            $rest = explode('.', substr($k, 7), 2);
            $tool = strtolower(trim($rest[0]));
            $param = isset($rest[1]) ? trim($rest[1]) : '*';
            if ($tool === '' || $param === '') {
                throw new RuntimeException($label . ' [' . $section . '] ' . $k . ' 格式錯誤，應為 target.<機台> 或 target.<機台>.<參數>');
            }
            if ($param !== '*' && !in_array($param, $params, true)) {
                throw new RuntimeException($label . ' [' . $section . '] ' . $k . ' 的參數 ' . $param .
                    ' 不在 params 裡（區分大小寫：' . implode(', ', $params) . '）');
            }
            if ($raw === '') {
                continue;   // 留空 = 沒寫，往下一層找
            }
            $model = $param === '*' ? $this->modelKey($rest[0]) : null;
            if ($model !== null) {
                $modelTargets[$model] = (float) $raw;
                continue;
            }
            if (!isset($targets[$tool])) {
                $targets[$tool] = array();
            }
            $targets[$tool][$param] = (float) $raw;
        }
        return array('offset' => $offset, 'target' => $default, 'targets' => $targets, 'targetModel' => $modelTargets);
    }

    /**
     * spec 線：upper_oos / upper_ooc / lower_ooc / lower_oos，沒設定或空字串為 null（不畫線）
     * 絕對值模式：有填的值必須符合 lower_oos <= lower_ooc < upper_ooc <= upper_oos
     * 偏移模式  ：都是距離 target 的偏移量，必須 >= 0；同側 OOS >= OOC；上下同一層不能同時為 0
     */
    private static function spec($conf, $label, $section, $offset = false)
    {
        $keys = array('upper_oos', 'upper_ooc', 'lower_ooc', 'lower_oos');
        $out = array();
        foreach ($keys as $k) {
            $out[$k] = null;
            if (array_key_exists($k, $conf) && trim((string) $conf[$k]) !== '') {
                $raw = trim((string) $conf[$k]);
                if (!is_numeric($raw)) {
                    throw new RuntimeException($label . ' [' . $section . '] ' . $k . ' 必須是數字或留空：' . $raw);
                }
                $out[$k] = (float) $raw;
            }
        }
        self::checkSpecValues($out, $label . ' [' . $section . '] ', $offset);
        return $out;
    }

    /** 檢查一套 spec（array(key => 值|null)）的大小關係；$where 為錯誤訊息前綴 */
    private static function checkSpecValues($s, $where, $offset)
    {
        if ($offset) {
            self::checkOffsetSpec($s, $where);
            return;
        }
        // 由上到下：upper_oos >= upper_ooc > lower_ooc >= lower_oos（同一側的 OOC 與 OOS 可以相等）
        $keys = array('upper_oos', 'upper_ooc', 'lower_ooc', 'lower_oos');
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $a = $s[$keys[$i]];
                $b = $s[$keys[$j]];
                if ($a === null || $b === null) {
                    continue;
                }
                $sameSide = substr($keys[$i], 0, 5) === substr($keys[$j], 0, 5);
                if ($sameSide ? $a < $b : $a <= $b) {
                    throw new RuntimeException($where . $keys[$i] . '（' . $a . '）必須' .
                        ($sameSide ? '大於等於 ' : '大於 ') . $keys[$j] . '（' . $b . '）');
                }
            }
        }
    }

    private static function checkOffsetSpec($s, $where)
    {
        foreach ($s as $k => $v) {
            if ($v !== null && $v < 0) {
                throw new RuntimeException($where . '有寫 target 時 ' . $k . ' 是距離 target 的偏移量，必須 >= 0（例 lower_ooc = 1.5 表示 target - 1.5）');
            }
        }
        foreach (array('upper', 'lower') as $side) {
            $oos = $s[$side . '_oos'];
            $ooc = $s[$side . '_ooc'];
            if ($oos !== null && $ooc !== null && $oos < $ooc) {
                throw new RuntimeException($where . $side . '_oos（' . $oos . '）必須大於等於 ' . $side . '_ooc（' . $ooc . '）');
            }
        }
        foreach (array('ooc', 'oos') as $lv) {
            if ($s['upper_' . $lv] === 0.0 && $s['lower_' . $lv] === 0.0) {
                throw new RuntimeException($where . 'upper_' . $lv . ' 與 lower_' . $lv . ' 不能同時為 0');
            }
        }
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
