<?php
/**
 * 每張 chart 的資料來源基底類別
 *
 * 部署者在 charts/<名稱>.php 寫一個類別 <名稱>Chart extends ChartSource，實作兩個方法：
 *
 *   tools()
 *       這張 chart 的全部機台代號：array('APAN01', 'APAN02', ...)
 *
 *   series($tools, $params, $start, $end)
 *       $tools、$params（charts/<名稱>.ini 用到的全部參數）在 [$start, $end]（含兩端，Y-m-d）內的資料：
 *       array(
 *         'APAN01' => array(
 *           'Date' => array('2026-01-01', '2026-01-02', ...),
 *           'Data' => array('M1BF_Z_C1' => array(1.0, 2.0, ...), 'M1BF_Z_C2' => array(1.1, null, ...), ...)
 *         ),
 *         ...
 *       )
 *       Data 裡每個參數的陣列與 Date 一一對應（同樣長度），該時間沒有值的放 null；沒有的參數可以不回傳。
 *
 * 排序、型別轉換、空值處理、只留 ini 用到的參數，都由 ChartsController 統一做，這裡回傳順序不拘。
 *
 * 選用：machines()
 *   有些 table 用 machine_id 而不是 tool_id（例 table M1BF_HP23，HP23 是 APAN01 的 machine_id）。
 *   實作 machines() 回傳 tool_id => machine_id 對照，例 array('APAN01' => 'HP23', ...)，就可以：
 *     - 不寫 tools()：預設用 machines() 的 tool_id
 *     - $this->machineOf('APAN01') → 'HP23'；$this->toolOf('HP23') → 'APAN01'
 *     - $this->machinesOf($tools) → 這些機台的 machine_id（給 IN (...) 用）
 *     - series() 回傳的 key 可以直接是 machine_id，controller 會自動換成 tool_id
 *   網頁上顯示的機台名稱一律是 tool_id。
 * 錯誤時 throw RuntimeException。
 *
 * 可用的工具：
 *   $this->fetchAll($sql, $bind)   prepared statement 查詢（連線見 db()）
 *   $this->in($list)               產生 IN (...) 用的 ?,?,?
 *   self::pivot(...)               長表（一列一個參數值）→ 資料格式
 *   self::wide(...)                寬表（每個參數一個欄位）→ 資料格式
 *   self::merge($a, $b, ...)       多個 table 的結果依機台 + 時間合併
 *   self::table($name)             把組出來的 table 名稱（例 'M1BF_' . $machineId）檢查後加上 `反引號`
 */
abstract class ChartSource
{
    /** 已開啟的連線，key = 連線設定（同一個 request 內相同設定共用一條） */
    private static $pdos = array();

    /** @var ChartConfig 這張 chart 的設定（create() 設定） */
    private $cfg = null;

    /** @var array|null tool_id => machine_id（machines() 的結果，第一次使用時讀取） */
    private $machineMap = null;

    /**
     * 這張 chart 的全部機台代號（tool_id）。
     * 有實作 machines() 時預設回傳它的 tool_id，否則必須自己實作
     */
    public function tools()
    {
        $map = $this->machineMap();
        if (!$map) {
            throw new RuntimeException('charts/<名稱>.php 必須實作 tools() 或 machines()');
        }
        return array_keys($map);
    }

    /**
     * 選用：tool_id => machine_id 對照，例 array('APAN01' => 'HP23', 'APAN02' => 'HP24')
     * 可以直接寫死，也可以用 $this->fetchAll() 從對照表查出來。沒有 machine_id 的 table 不用實作
     */
    public function machines()
    {
        return array();
    }

    abstract public function series($tools, $params, $start, $end);

    /**
     * 依 charts/<名稱>.ini 的 [page] source 建立資料來源
     *   php     載入 charts/<名稱>.php 的 <名稱>Chart
     *   sample  內建範例資料
     */
    public static function create(ChartConfig $cfg)
    {
        if ($cfg->source() === 'sample') {
            return new SampleChartSource();
        }
        $file = ChartConfig::dir() . '/' . $cfg->name . '.php';
        $label = 'charts/' . $cfg->name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('找不到 ' . $label . '（尚未寫好時可先把 ini 的 [page] source 設為 sample）');
        }
        require_once $file;
        $class = $cfg->name . 'Chart';
        if (!class_exists($class, false)) {
            throw new RuntimeException($label . ' 裡沒有類別 ' . $class);
        }
        $obj = new $class();
        if (!($obj instanceof ChartSource)) {
            throw new RuntimeException($label . ' 的 ' . $class . ' 必須 extends ChartSource');
        }
        $obj->cfg = $cfg;
        return $obj;
    }

    // =====================================================================
    // 資料庫
    // ---------------------------------------------------------------------
    // 連線設定在各 chart 自己的 charts/<名稱>.ini 的 [db]：
    //   ip / port / name / user / password / charset
    // [db] 沒有填 ip（或沒有 [db]）時，直接用 AISLE 自己的 [database] 連線。
    // =====================================================================

    /** @return PDO */
    protected function db()
    {
        $c = $this->cfg === null ? null : $this->cfg->db();
        if ($c === null) {
            return Database::pdo();
        }
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['ip'], $c['port'], $c['charset']);
        if ($c['name'] !== '') {
            $dsn .= ';dbname=' . $c['name'];
        }
        $key = $dsn . "\n" . $c['user'];
        if (!isset(self::$pdos[$key])) {
            self::$pdos[$key] = new PDO($dsn, $c['user'], $c['password'], array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        }
        return self::$pdos[$key];
    }

    /** 查詢並回傳所有資料列（關聯陣列）。參數一律用 ? 綁定，不要把值串進 SQL 字串 */
    protected function fetchAll($sql, $bind = array())
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute(array_values($bind));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** IN (...) 用的佔位符：in(array('a', 'b')) → "?,?"；空陣列回傳 "NULL"（查不到任何資料） */
    protected function in($list)
    {
        $n = count($list);
        return $n > 0 ? implode(',', array_fill(0, $n, '?')) : 'NULL';
    }

    // =====================================================================
    // tool_id / machine_id 對照
    // =====================================================================

    /** machines() 的結果（只讀一次）；key、value 都轉成字串並去掉空白 */
    protected function machineMap()
    {
        if ($this->machineMap === null) {
            $map = array();
            $raw = $this->machines();
            foreach (is_array($raw) ? $raw : array() as $tool => $machine) {
                $tool = trim((string) $tool);
                $machine = trim((string) $machine);
                if ($tool !== '' && $machine !== '') {
                    $map[$tool] = $machine;
                }
            }
            $this->machineMap = $map;
        }
        return $this->machineMap;
    }

    /** tool_id → machine_id；沒有對照時拋出錯誤（避免查錯機台） */
    protected function machineOf($tool)
    {
        $map = $this->machineMap();
        if (!isset($map[$tool])) {
            throw new RuntimeException('machines() 裡沒有機台 ' . $tool . ' 的 machine_id');
        }
        return $map[$tool];
    }

    /** machine_id → tool_id；找不到回傳 null */
    protected function toolOf($machine)
    {
        $tool = array_search((string) $machine, $this->machineMap(), true);
        return $tool === false ? null : $tool;
    }

    /** 多台 tool_id → machine_id 陣列（順序相同） */
    protected function machinesOf($tools)
    {
        $out = array();
        foreach ($tools as $t) {
            $out[] = $this->machineOf($t);
        }
        return $out;
    }

    /**
     * series() 結果的 key 是 machine_id 時換成 tool_id（controller 呼叫，部署者不用管）
     * 已經是 tool_id 或對照不到的 key 原樣保留；換完後同一台有兩份時依時間合併
     */
    public function toToolKeys($data)
    {
        if (!is_array($data) || !$this->machineMap()) {
            return $data;
        }
        $map = $this->machineMap();
        $out = array();
        foreach ($data as $key => $series) {
            $key = (string) $key;
            $tool = isset($map[$key]) ? $key : $this->toolOf($key);
            if ($tool === null) {
                $tool = $key;
            }
            if (isset($out[$tool])) {
                $merged = self::merge(array($tool => $out[$tool]), array($tool => $series));
                $out[$tool] = $merged[$tool];
            } else {
                $out[$tool] = $series;
            }
        }
        return $out;
    }

    /**
     * 組出來的 table 名稱（例 'M1BF_' . $this->machineOf($tool)）檢查後加上反引號。
     * table 名稱不能用 ? 綁定，所以一定要經過這裡，只允許英文、數字、底線（可含一個 . 指定資料庫）
     */
    public static function table($name)
    {
        $name = (string) $name;
        if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $name)) {
            throw new RuntimeException('table 名稱格式錯誤：' . $name);
        }
        return '`' . str_replace('.', '`.`', $name) . '`';
    }

    // =====================================================================
    // 轉換工具
    // =====================================================================

    /**
     * 長表（一列一個參數值）→ 資料格式
     *   pivot($rows, 'tool_id', 'd', 'param', 'value')
     * 同一台同一時間的不同參數合併成同一個 Date，缺少的參數補 null
     */
    public static function pivot($rows, $toolCol, $dateCol, $paramCol, $valueCol)
    {
        $tmp = array();     // tool => date => param => value
        $params = array();  // tool => param => true
        foreach ($rows as $r) {
            $tool = (string) $r[$toolCol];
            $tmp[$tool][(string) $r[$dateCol]][(string) $r[$paramCol]] = $r[$valueCol];
            $params[$tool][(string) $r[$paramCol]] = true;
        }
        $out = array();
        foreach ($tmp as $tool => $byDate) {
            ksort($byDate);
            $series = array('Date' => array_keys($byDate), 'Data' => array());
            foreach (array_keys($params[$tool]) as $p) {
                $series['Data'][$p] = array();
                foreach ($byDate as $values) {
                    $series['Data'][$p][] = array_key_exists($p, $values) ? $values[$p] : null;
                }
            }
            $out[$tool] = $series;
        }
        return $out;
    }

    /**
     * 寬表（一列一個時間點、每個參數一個欄位）→ 資料格式
     *   $map 欄位名稱就是參數名稱時：array('M1BF_Z_C1', 'M1BF_Z_C2')
     *   欄位名稱不同時給對照表：     array('M1BF_Z_C1' => 'c1', 'M1BF_Z_C2' => 'c2')（參數 => 欄位）
     */
    public static function wide($rows, $toolCol, $dateCol, $map)
    {
        $cols = array();  // param => column
        foreach ($map as $k => $v) {
            $cols[is_int($k) ? (string) $v : (string) $k] = (string) $v;
        }
        $out = array();
        foreach ($rows as $r) {
            $tool = (string) $r[$toolCol];
            if (!isset($out[$tool])) {
                $out[$tool] = array('Date' => array(), 'Data' => array());
                foreach ($cols as $p => $c) {
                    $out[$tool]['Data'][$p] = array();
                }
            }
            $out[$tool]['Date'][] = (string) $r[$dateCol];
            foreach ($cols as $p => $c) {
                $out[$tool]['Data'][$p][] = array_key_exists($c, $r) ? $r[$c] : null;
            }
        }
        return $out;
    }

    /**
     * 合併多份資料格式（例如同一張 chart 的參數來自不同 table）：
     *   merge($a, $b, $c ...)
     * 依機台 + 時間對齊，對不到的位置補 null；同一機台同一時間同一參數重複時，後面的覆蓋前面的。
     * 時間字串要用相同格式（例如都用 DATE_FORMAT(..., '%Y-%m-%d %H:%i:%s')）才對得起來。
     */
    public static function merge()
    {
        $tmp = array();     // tool => date => param => value
        $params = array();  // tool => param => true
        foreach (func_get_args() as $data) {
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $tool => $s) {
                if (!isset($s['Date'], $s['Data']) || !is_array($s['Date']) || !is_array($s['Data'])) {
                    continue;
                }
                $tool = (string) $tool;
                $dates = array_values($s['Date']);
                foreach ($dates as $date) {
                    if (!isset($tmp[$tool][(string) $date])) {
                        $tmp[$tool][(string) $date] = array();
                    }
                }
                foreach ($s['Data'] as $p => $col) {
                    $params[$tool][(string) $p] = true;
                    $col = is_array($col) ? array_values($col) : array();
                    foreach ($dates as $i => $date) {
                        if (array_key_exists($i, $col) && $col[$i] !== null) {
                            $tmp[$tool][(string) $date][(string) $p] = $col[$i];
                        }
                    }
                }
            }
        }
        $out = array();
        foreach ($tmp as $tool => $byDate) {
            ksort($byDate);
            $series = array('Date' => array_map('strval', array_keys($byDate)), 'Data' => array());
            $list = isset($params[$tool]) ? array_keys($params[$tool]) : array();
            foreach ($list as $p) {
                $series['Data'][$p] = array();
                foreach ($byDate as $values) {
                    $series['Data'][$p][] = array_key_exists($p, $values) ? $values[$p] : null;
                }
            }
            $out[$tool] = $series;
        }
        return $out;
    }
}

/**
 * 範例資料（[page] source = sample）：不需資料庫，SQL 還沒寫好時先看畫面
 * 固定演算法，同一天同一台每次都得到相同數值
 */
class SampleChartSource extends ChartSource
{
    public function tools()
    {
        return array(
            'APAN01', 'APAN02', 'APAN03', 'APAN04', 'APAN05', 'APAN06',
            'APAP01', 'APAP02', 'APAP03', 'APAP04', 'APAP05', 'APAP06',
        );
    }

    public function series($tools, $params, $start, $end)
    {
        $out = array();
        $from = strtotime($start);
        $to = strtotime($end);
        foreach ($tools as $tool) {
            $series = array('Date' => array(), 'Data' => array());
            foreach ($params as $p) {
                $series['Data'][$p] = array();
            }
            for ($t = $from; $t <= $to; $t += 86400) {
                $day = date('Y-m-d', $t);
                if (self::value($tool, '', $day) === null) {
                    continue; // 這天停機
                }
                $series['Date'][] = $day;
                foreach ($params as $p) {
                    $series['Data'][$p][] = self::value($tool, $p, $day);
                }
            }
            $out[$tool] = $series;
        }
        return $out;
    }

    /**
     * 每台、每個參數有自己的基準值與週期；約 1/9 的日子整台沒有資料（模擬停機）
     * $param = '' 時只用來判斷這天是否停機
     */
    private static function value($tool, $param, $day)
    {
        if ($param === '') {
            return abs(crc32($tool . '|' . $day)) % 9 === 0 ? null : 0;
        }
        $h = abs(crc32($tool . '|' . $param . '|' . $day));
        $seed = abs(crc32($tool));
        // 同一台的參數走勢相近，各參數再加一點固定偏移
        $base = 1.2 + ($seed % 300) / 100 + (abs(crc32($param)) % 60) / 100 - 0.3;
        $n = floor(strtotime($day) / 86400);               // 絕對天數
        $wave = 0.6 * sin($n / (5 + $seed % 7) + $seed % 10);
        $noise = (($h % 1000) / 1000 - 0.5) * 0.5;
        return round(max(0, $base + $wave + $noise), 3);
    }
}
