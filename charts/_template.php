<?php
/**
 * chart 資料來源範本：複製成 charts/<名稱>.php，並把類別名稱 __NAME__Chart 改成 <名稱>Chart
 * （用 scripts/shell/new_chart.sh <名稱> 會自動完成）
 *
 * 寫好 SQL 後，把 charts/<名稱>.ini 的 [page] source 改成 php。
 *
 * 只需要實作兩個方法：
 *
 *   tools()
 *       這張 chart 的全部機台代號：array('APAN01', 'APAN02', ...)
 *
 *   series($tools, $params, $start, $end)
 *       $tools   tools() 回傳的機台
 *       $params  charts/<名稱>.ini 用到的全部參數名稱
 *       $start / $end  查詢區間 'Y-m-d'（含兩端）
 *       回傳：
 *         array(
 *           'APAN01' => array(
 *             'Date' => array('2026-01-01 08:00:00', ...),
 *             'Data' => array('XXX_C1' => array(1.0, ...), 'XXX_C2' => array(1.1, ...))
 *           ),
 *           ...
 *         )
 *       每個參數的陣列和 Date 一樣長，沒有值的位置放 null。
 *       排序、轉數字、只留 ini 用到的參數由共用程式處理，這裡不用管。
 *
 *   machines()（選用）
 *       table 用的是 machine_id 而不是 tool_id 時（例 table M1BF_HP23，HP23 是 APAN01 的 machine_id），
 *       回傳 tool_id => machine_id 對照：array('APAN01' => 'HP23', 'APAN02' => 'HP24', ...)
 *       有實作時：
 *         - tools() 可以刪掉，預設就是 machines() 的 tool_id
 *         - series() 回傳的 key 可以直接用 machine_id，會自動換成 tool_id
 *         - 網頁上顯示的機台名稱一律是 tool_id
 *
 * 可用的工具（定義在 modules/charts/ChartSource.php）：
 *   $this->fetchAll($sql, $bind)   查詢；值一律用 ? 綁定，不要串進 SQL 字串
 *                                  連線設定：charts/<名稱>.ini 的 [db]（沒填 ip 時用 AISLE 自己的資料庫）
 *   $this->in($list)               產生 IN (...) 用的 ?,?,?
 *   self::pivot($rows, 機台欄, 時間欄, 參數欄, 值欄)    長表（一列一個參數值）→ 回傳格式
 *   self::wide($rows, 機台欄, 時間欄, 參數 => 欄位)     寬表（每個參數一個欄位）→ 回傳格式
 *   self::merge($a, $b, ...)       多個 table 的結果依「機台 + 時間」合併
 *   $this->machineOf($tool)        tool_id → machine_id（要先實作 machines()）
 *   $this->machinesOf($tools)      多台 tool_id → machine_id 陣列（給 IN (...) 用）
 *   $this->toolOf($machine)        machine_id → tool_id
 *   self::table($name)             組出來的 table 名稱（例 'M1BF_' . $machineId）檢查後加反引號；
 *                                  table 名稱不能用 ? 綁定，一定要經過這個函式
 *
 * 注意（PHP 5.4）：不能用 ??、finally、::class 等 PHP 5.5 以後的語法；檔案結尾不要寫 ?>
 * 查詢失敗或資料不對時 throw new RuntimeException('說明')，頁面會顯示訊息。
 */
class __NAME__Chart extends ChartSource
{
    // ★ 選用：table 用 machine_id 時才需要，把下面整段取消註解，並刪掉 tools()
    //
    // public function machines()
    // {
    //     // 寫法 A：直接寫死
    //     return array(
    //         'APAN01' => 'HP23',
    //         'APAN02' => 'HP24',
    //     );
    //
    //     // 寫法 B：從對照表查
    //     // $map = array();
    //     // foreach ($this->fetchAll('SELECT tool_id, machine_id FROM tool_machine_map') as $r) {
    //     //     $map[$r['tool_id']] = $r['machine_id'];
    //     // }
    //     // return $map;
    // }

    public function tools()
    {
        // ★ 部署者實作：回傳全部機台代號（tool_id）；有實作 machines() 時把整個 tools() 刪掉
        //
        // $rows = $this->fetchAll('SELECT DISTINCT tool_id FROM some_table ORDER BY tool_id');
        // $tools = array();
        // foreach ($rows as $r) {
        //     $tools[] = $r['tool_id'];
        // }
        // return $tools;

        throw new RuntimeException('__NAME__：尚未實作 tools()，見 charts/__NAME__.php');
    }

    public function series($tools, $params, $start, $end)
    {
        // ★ 部署者實作：回傳 $tools、$params 在期間內的資料
        //
        // 共用的 WHERE 條件（機台 + 日期區間）
        // $where = 'tool_id IN (' . $this->in($tools) . ')
        //           AND data_time >= ? AND data_time < DATE_ADD(?, INTERVAL 1 DAY)';
        // $bind  = array_merge($tools, array($start, $end));
        //
        // --- 寫法 1：長表（一列一個參數值：tool_id, data_time, param, value）---
        // $rows = $this->fetchAll(
        //     'SELECT tool_id, DATE_FORMAT(data_time, \'%Y-%m-%d %H:%i:%s\') AS d, param, value
        //        FROM table_a
        //       WHERE ' . $where . ' AND param IN (' . $this->in($params) . ')',
        //     array_merge($bind, $params)
        // );
        // return self::pivot($rows, 'tool_id', 'd', 'param', 'value');
        //
        // --- 寫法 2：寬表（一列一個時間點，每個參數一個欄位）---
        // $rows = $this->fetchAll(
        //     'SELECT tool_id, DATE_FORMAT(data_time, \'%Y-%m-%d %H:%i:%s\') AS d, c1, c2
        //        FROM table_b WHERE ' . $where,
        //     $bind
        // );
        // return self::wide($rows, 'tool_id', 'd', array('XXX_C1' => 'c1', 'XXX_C2' => 'c2'));
        //
        // --- 寫法 3：參數分散在不同 table，各查一次再合併 ---
        // $a = self::pivot($this->fetchAll(...table_a...), 'tool_id', 'd', 'param', 'value');
        // $b = self::wide($this->fetchAll(...table_b...), 'tool_id', 'd', array('XXX_C3' => 'c3'));
        // return self::merge($a, $b);
        // （兩邊的時間要用同樣的 DATE_FORMAT 格式才對得起來）
        //
        // --- 寫法 4：每台機台一個 table，table 名稱用 machine_id（例 M1BF_HP23），要實作 machines() ---
        // $parts = array();
        // foreach ($tools as $tool) {
        //     $rows = $this->fetchAll(
        //         'SELECT DATE_FORMAT(data_time, \'%Y-%m-%d %H:%i:%s\') AS d, c1, c2
        //            FROM ' . self::table('M1BF_' . $this->machineOf($tool)) . '
        //           WHERE data_time >= ? AND data_time < DATE_ADD(?, INTERVAL 1 DAY)',
        //         array($start, $end)
        //     );
        //     foreach ($rows as $i => $r) {
        //         $rows[$i]['tool_id'] = $tool;     // 補上機台欄位給 wide() 用
        //     }
        //     $parts[] = self::wide($rows, 'tool_id', 'd', array('XXX_C1' => 'c1', 'XXX_C2' => 'c2'));
        // }
        // return call_user_func_array(array('ChartSource', 'merge'), $parts);
        //
        // --- 寫法 5：table 裡的機台欄位是 machine_id，要實作 machines() ---
        // $machines = $this->machinesOf($tools);
        // $rows = $this->fetchAll(
        //     'SELECT machine_id, DATE_FORMAT(data_time, \'%Y-%m-%d %H:%i:%s\') AS d, param, value
        //        FROM table_c
        //       WHERE machine_id IN (' . $this->in($machines) . ')
        //         AND data_time >= ? AND data_time < DATE_ADD(?, INTERVAL 1 DAY)',
        //     array_merge($machines, array($start, $end))
        // );
        // return self::pivot($rows, 'machine_id', 'd', 'param', 'value');   // key 是 machine_id，會自動換成 tool_id

        throw new RuntimeException('__NAME__：尚未實作 series()，見 charts/__NAME__.php');
    }
}
