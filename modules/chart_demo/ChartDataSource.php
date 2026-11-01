<?php
/**
 * chart_demo 的資料來源
 *
 * Controller 只呼叫這裡的兩個公開方法，回傳格式固定：
 *   tools()                     array('APAN01', 'APAN02', ...)
 *   series($tools, $params, $start, $end)
 *       array(
 *         'APAN01' => array(
 *           'Date' => array('2026-01-01', '2026-01-02', ...),
 *           'Data' => array('M1BF_Z_C1' => array(1.0, 2.0, ...), 'M1BF_Z_C2' => array(1.1, 2.1, ...), ...)
 *         ),
 *         ...
 *       )
 *   Data 裡每個參數的陣列與 Date 一一對應（同樣長度），該時間沒有值的放 null。
 *   $params 是 config/chart_demo.ini 用到的全部參數，沒有的參數可以不回傳。
 * 排序、型別轉換、空值處理都由 Controller 統一做，這裡回傳順序不拘。
 *
 * 資料來源由 config/app.ini 的 [chart_demo] source 決定（未設定時為 sample）：
 *   sample  內建範例資料，不需資料庫，部署後即可看到畫面
 *   db      PHP 直接查 MariaDB      → 實作下方「★ 部署者實作區 (db)」
 *   script  呼叫 Python 2.7 腳本取得 JSON → 實作 scripts/python/chart_data.py 的 SQL 區塊
 */
class ChartDataSource
{
    public static function mode()
    {
        $mode = (string) Config::get('chart_demo', 'source', 'sample');
        return in_array($mode, array('sample', 'db', 'script'), true) ? $mode : 'sample';
    }

    /** 全部機台代號 */
    public static function tools()
    {
        switch (self::mode()) {
            case 'db':
                return self::dbTools();
            case 'script':
                $res = self::runScript(array('tools'));
                return isset($res['tools']) && is_array($res['tools']) ? $res['tools'] : array();
            default:
                return self::sampleTools();
        }
    }

    /** 指定機台、參數在 [$start, $end]（含兩端，Y-m-d）內的資料 */
    public static function series($tools, $params, $start, $end)
    {
        switch (self::mode()) {
            case 'db':
                return self::dbSeries($tools, $params, $start, $end);
            case 'script':
                return self::runScript(array(
                    'series', '--start', $start, '--end', $end,
                    '--tools', implode(',', $tools), '--params', implode(',', $params),
                ));
            default:
                return self::sampleSeries($tools, $params, $start, $end);
        }
    }

    // =====================================================================
    // ★ 部署者實作區 (db)：把下面兩個方法換成實際的 SQL
    // ---------------------------------------------------------------------
    // 範例假設資料表為「一列一個參數值」（依實際名稱、欄位修改）：
    //   tool_data (tool_id VARCHAR(32), data_time DATETIME, param VARCHAR(64), value DOUBLE)
    // 用 self::pivot() 轉成輸出格式。
    // 若資料表是「一列一個時間點、每個參數一個欄位」（寬表），SELECT 那些欄位後用 self::wide() 轉換。
    // 注意：
    //   1. 一律用 Database::fetchAll($sql, $params) 的 prepared statement，
    //      不要把參數串進 SQL 字串
    //   2. 若資料不在 aisle 資料庫，可在 app.ini 加一個 [database] 以外的區段，
    //      自行 new PDO(...) 連線，寫法參考 core/Database.php
    //   3. aisle_app 帳號需要對該資料表有 SELECT 權限
    // =====================================================================

    private static function dbTools()
    {
        // TODO(部署者)：回傳全部機台代號
        // $rows = Database::fetchAll('SELECT DISTINCT tool_id FROM tool_data');
        // $tools = array();
        // foreach ($rows as $r) {
        //     $tools[] = $r['tool_id'];
        // }
        // return $tools;
        throw new RuntimeException('chart_demo：source = db 尚未實作 dbTools()，見 modules/chart_demo/ChartDataSource.php');
    }

    private static function dbSeries($tools, $params, $start, $end)
    {
        // TODO(部署者)：回傳 $tools、$params 在期間內的資料
        // $inT = implode(',', array_fill(0, count($tools), '?'));
        // $inP = implode(',', array_fill(0, count($params), '?'));
        // $rows = Database::fetchAll(
        //     'SELECT tool_id, DATE_FORMAT(data_time, \'%Y-%m-%d %H:%i:%s\') AS d, param, value
        //        FROM tool_data
        //       WHERE tool_id IN (' . $inT . ') AND param IN (' . $inP . ')
        //         AND data_time >= ? AND data_time < DATE_ADD(?, INTERVAL 1 DAY)',
        //     array_merge($tools, $params, array($start, $end))
        // );
        // return self::pivot($rows, 'tool_id', 'd', 'param', 'value');
        //
        // 寬表寫法（參數名稱就是欄位名稱；欄位名稱不能用 ? 綁定，只能用 config 裡的參數並加反引號）：
        // $cols = '`' . implode('`, `', $params) . '`';
        // $rows = Database::fetchAll('SELECT tool_id, DATE_FORMAT(data_time, ...) AS d, ' . $cols . ' FROM ... WHERE ...', ...);
        // return self::wide($rows, 'tool_id', 'd', $params);
        throw new RuntimeException('chart_demo：source = db 尚未實作 dbSeries()，見 modules/chart_demo/ChartDataSource.php');
    }

    /**
     * 「一列一個參數值」的資料列 → 輸出格式
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

    /** 「一列一個時間點、每個參數一個欄位」的資料列 → 輸出格式 */
    public static function wide($rows, $toolCol, $dateCol, $params)
    {
        $out = array();
        foreach ($rows as $r) {
            $tool = (string) $r[$toolCol];
            if (!isset($out[$tool])) {
                $out[$tool] = array('Date' => array(), 'Data' => array());
                foreach ($params as $p) {
                    $out[$tool]['Data'][$p] = array();
                }
            }
            $out[$tool]['Date'][] = (string) $r[$dateCol];
            foreach ($params as $p) {
                $out[$tool]['Data'][$p][] = array_key_exists($p, $r) ? $r[$p] : null;
            }
        }
        return $out;
    }

    // =====================================================================
    // script：呼叫 scripts/python/chart_data.py，stdout 必須是 JSON
    // app.ini [chart_demo] python 可指定直譯器路徑（預設 python）
    // =====================================================================

    private static function runScript($args)
    {
        $python = (string) Config::get('chart_demo', 'python', 'python');
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg(AISLE_ROOT . '/scripts/python/chart_data.py');
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $output = array();
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        if ($code !== 0) {
            throw new RuntimeException('chart_data.py 執行失敗 (exit ' . $code . ')：' . substr($text, 0, 500));
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('chart_data.py 輸出不是 JSON：' . substr($text, 0, 200));
        }
        return $data;
    }

    // =====================================================================
    // sample：範例資料（固定演算法，同一天同一台每次都得到相同數值）
    // =====================================================================

    private static function sampleTools()
    {
        return array(
            'APAN01', 'APAN02', 'APAN03', 'APAN04', 'APAN05', 'APAN06',
            'APAP01', 'APAP02', 'APAP03', 'APAP04', 'APAP05', 'APAP06',
        );
    }

    private static function sampleSeries($tools, $params, $start, $end)
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
                if (self::sampleValue($tool, '', $day) === null) {
                    continue; // 這天停機
                }
                $series['Date'][] = $day;
                foreach ($params as $p) {
                    $series['Data'][$p][] = self::sampleValue($tool, $p, $day);
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
    private static function sampleValue($tool, $param, $day)
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
