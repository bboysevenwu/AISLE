<?php
/**
 * chart_demo 的資料來源
 *
 * Controller 只呼叫這裡的兩個公開方法，回傳格式固定：
 *   tools()                     array('APAN01', 'APAN02', ...)
 *   series($tools, $start, $end) array('APAN01' => array('Date' => array(...), 'Data' => array(...)), ...)
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

    /** 指定機台在 [$start, $end]（含兩端，Y-m-d）內的資料 */
    public static function series($tools, $start, $end)
    {
        switch (self::mode()) {
            case 'db':
                return self::dbSeries($tools, $start, $end);
            case 'script':
                return self::runScript(array('series', '--start', $start, '--end', $end, '--tools', implode(',', $tools)));
            default:
                return self::sampleSeries($tools, $start, $end);
        }
    }

    // =====================================================================
    // ★ 部署者實作區 (db)：把下面兩個方法換成實際的 SQL
    // ---------------------------------------------------------------------
    // 範例假設資料表（依實際名稱、欄位修改）：
    //   tool_data (tool_id VARCHAR(32), data_time DATETIME, value DOUBLE)
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

    private static function dbSeries($tools, $start, $end)
    {
        // TODO(部署者)：回傳 $tools 在期間內的資料
        // $in = implode(',', array_fill(0, count($tools), '?'));
        // $rows = Database::fetchAll(
        //     'SELECT tool_id, DATE_FORMAT(data_time, \'%Y-%m-%d %H:%i:%s\') AS d, value
        //        FROM tool_data
        //       WHERE tool_id IN (' . $in . ')
        //         AND data_time >= ? AND data_time < DATE_ADD(?, INTERVAL 1 DAY)',
        //     array_merge($tools, array($start, $end))
        // );
        // return self::group($rows, 'tool_id', 'd', 'value');
        throw new RuntimeException('chart_demo：source = db 尚未實作 dbSeries()，見 modules/chart_demo/ChartDataSource.php');
    }

    /** 把 SQL 結果列整理成 array('TOOL' => array('Date' => ..., 'Data' => ...)) */
    public static function group($rows, $toolCol, $dateCol, $valueCol)
    {
        $out = array();
        foreach ($rows as $r) {
            $tool = (string) $r[$toolCol];
            if (!isset($out[$tool])) {
                $out[$tool] = array('Date' => array(), 'Data' => array());
            }
            $out[$tool]['Date'][] = (string) $r[$dateCol];
            $out[$tool]['Data'][] = $r[$valueCol];
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

    private static function sampleSeries($tools, $start, $end)
    {
        $out = array();
        $from = strtotime($start);
        $to = strtotime($end);
        foreach ($tools as $tool) {
            $dates = array();
            $data = array();
            for ($t = $from; $t <= $to; $t += 86400) {
                $day = date('Y-m-d', $t);
                $v = self::sampleValue($tool, $day);
                if ($v !== null) {
                    $dates[] = $day;
                    $data[] = $v;
                }
            }
            $out[$tool] = array('Date' => $dates, 'Data' => $data);
        }
        return $out;
    }

    /** 每台有自己的基準值與週期，約 1/9 的日子沒有資料（模擬停機） */
    private static function sampleValue($tool, $day)
    {
        $h = abs(crc32($tool . '|' . $day));
        if ($h % 9 === 0) {
            return null;
        }
        $seed = abs(crc32($tool));
        $base = 1.2 + ($seed % 300) / 100;                 // 1.2 ~ 4.2
        $n = floor(strtotime($day) / 86400);               // 絕對天數
        $wave = 0.6 * sin($n / (5 + $seed % 7) + $seed % 10);
        $noise = (($h % 1000) / 1000 - 0.5) * 0.5;
        return round(max(0, $base + $wave + $noise), 3);
    }
}
