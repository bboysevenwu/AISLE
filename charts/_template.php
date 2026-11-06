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
 * 可用的工具（定義在 modules/charts/ChartSource.php）：
 *   $this->fetchAll($sql, $bind)   查詢；值一律用 ? 綁定，不要串進 SQL 字串
 *                                  連線設定：charts/<名稱>.ini 的 [db]（沒填 ip 時用 AISLE 自己的資料庫）
 *   $this->in($list)               產生 IN (...) 用的 ?,?,?
 *   self::pivot($rows, 機台欄, 時間欄, 參數欄, 值欄)    長表（一列一個參數值）→ 回傳格式
 *   self::wide($rows, 機台欄, 時間欄, 參數 => 欄位)     寬表（每個參數一個欄位）→ 回傳格式
 *   self::merge($a, $b, ...)       多個 table 的結果依「機台 + 時間」合併
 *
 * 注意（PHP 5.4）：不能用 ??、finally、::class 等 PHP 5.5 以後的語法；檔案結尾不要寫 ?>
 * 查詢失敗或資料不對時 throw new RuntimeException('說明')，頁面會顯示訊息。
 */
class __NAME__Chart extends ChartSource
{
    public function tools()
    {
        // ★ 部署者實作：回傳全部機台代號
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

        throw new RuntimeException('__NAME__：尚未實作 series()，見 charts/__NAME__.php');
    }
}
