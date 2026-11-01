<?php
/**
 * chart_demo 模組：資料視覺化範本（config.ini：EE、PE 可用）
 *
 *   index.php?m=chart_demo                                      頁面
 *   index.php?m=chart_demo&a=tbt&start=&end=                    區間內全部機台的資料（TBT 與 single 共用）
 *   index.php?m=chart_demo&a=newest[&model=1980|2000|All]       全部機台最新一筆 (Newest_TBT)
 *
 * 前端進頁面時呼叫一次 tbt 與 newest（model=All）取回全部機台的資料，
 * 切換機台 / 機型只在瀏覽器端篩選，不再發 request；改日期區間才重新呼叫 tbt
 *
 * AJAX 動作的回傳格式相同：
 *   { "APAN01": { "Date": ["2026-01-01", ...], "Data": [1.0, ...] }, ... }
 * 機台依名稱自然排序，每台內部依時間排序。錯誤時回 HTTP 400 + {"ok":false,"error":"..."}
 *
 * 取資料的程式在 ChartDataSource.php，部署時只需要改那個檔案
 */
require_once dirname(__FILE__) . '/ChartDataSource.php';

class ChartDemoController extends Controller
{
    /** 一次最多查詢的天數，避免一次撈太多資料 */
    const MAX_DAYS = 366;
    /** 預設顯示最近幾天 */
    const DEFAULT_DAYS = 60;

    public function indexAction()
    {
        $tools = $this->sortTools(ChartDataSource::tools());
        $models = array();
        foreach ($tools as $t) {
            $models[$t] = self::modelOf($t);
        }
        $this->render('index', array(
            'tools'  => $tools,
            'models' => $models,
            'start'  => date('Y-m-d', strtotime('-' . (self::DEFAULT_DAYS - 1) . ' day')),
            'end'    => date('Y-m-d'),
            'source' => ChartDataSource::mode(),
        ));
    }

    /** 所有機台最新一筆，可依機型篩選（頁面固定用 All，篩選在前端做） */
    public function newestAction()
    {
        $model = $this->query('model', 'All');
        if (!in_array($model, array('1980', '2000', 'All'), true)) {
            return $this->fail('機型只能是 1980、2000 或 All');
        }
        $tools = array();
        foreach (ChartDataSource::tools() as $t) {
            if ($model === 'All' || self::modelOf($t) === $model) {
                $tools[] = $t;
            }
        }
        $tools = $this->sortTools($tools);
        $data = $tools ? ChartDataSource::latest($tools) : array();
        $this->output($this->normalize($data, $tools, true));
    }

    /** 所有機台在期間內的資料：TBT 直接畫，single 從中取出單台 */
    public function tbtAction()
    {
        $range = $this->range();
        if ($range === null) {
            return;
        }
        $tools = $this->sortTools(ChartDataSource::tools());
        $data = $tools ? ChartDataSource::series($tools, $range[0], $range[1]) : array();
        $this->output($this->normalize($data, $tools));
    }

    /**
     * 機型判斷：名稱含 PAN → 1980，含 PAP → 2000，其他回傳 ''（只出現在 All）
     * 規則改變時只要改這裡
     */
    public static function modelOf($tool)
    {
        $tool = strtoupper($tool);
        if (strpos($tool, 'PAN') !== false) {
            return '1980';
        }
        if (strpos($tool, 'PAP') !== false) {
            return '2000';
        }
        return '';
    }

    // ------------------------------------------------------------------

    /** 讀取並檢查 start / end，失敗時已輸出錯誤並回傳 null */
    private function range()
    {
        $start = $this->query('start');
        $end = $this->query('end');
        if (!self::isDate($start) || !self::isDate($end)) {
            $this->fail('日期格式需為 YYYY-MM-DD');
            return null;
        }
        if ($start > $end) {
            $this->fail('起始日期不可晚於結束日期');
            return null;
        }
        $days = (strtotime($end) - strtotime($start)) / 86400 + 1;
        if ($days > self::MAX_DAYS) {
            $this->fail('查詢區間最多 ' . self::MAX_DAYS . ' 天');
            return null;
        }
        return array($start, $end);
    }

    private static function isDate($s)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function sortTools($tools)
    {
        $tools = array_values(array_unique(array_map('strval', $tools)));
        sort($tools, SORT_NATURAL | SORT_FLAG_CASE);
        return $tools;
    }

    /**
     * 統一整理資料來源的結果：
     *   - 只保留 $tools 中的機台，並依 $tools 的順序輸出
     *   - 每台依 Date 排序，Data 轉成數字，Date / Data 長度不一致或數值不是數字的筆數丟掉
     *   - $lastOnly = true 時每台只留最新一筆
     */
    private function normalize($data, $tools, $lastOnly = false)
    {
        $out = array();
        foreach ($tools as $tool) {
            if (!isset($data[$tool]['Date'], $data[$tool]['Data']) || !is_array($data[$tool]['Date'])) {
                continue;
            }
            $pairs = array();
            $dates = array_values($data[$tool]['Date']);
            $values = array_values($data[$tool]['Data']);
            $n = min(count($dates), count($values));
            for ($i = 0; $i < $n; $i++) {
                if ($values[$i] === null || !is_numeric($values[$i])) {
                    continue;
                }
                $pairs[] = array((string) $dates[$i], (float) $values[$i]);
            }
            if (!$pairs) {
                continue;
            }
            usort($pairs, array('ChartDemoController', 'cmpPair'));
            if ($lastOnly) {
                $pairs = array($pairs[count($pairs) - 1]);
            }
            $series = array('Date' => array(), 'Data' => array());
            foreach ($pairs as $p) {
                $series['Date'][] = $p[0];
                $series['Data'][] = $p[1];
            }
            $out[$tool] = $series;
        }
        return $out;
    }

    public static function cmpPair($a, $b)
    {
        return strcmp($a[0], $b[0]);
    }

    /** 輸出 JSON；沒有資料時輸出 {} 而不是 [] */
    private function output($data)
    {
        $this->json($data ? $data : new stdClass());
    }

    private function fail($message)
    {
        $this->json(array('ok' => false, 'error' => $message), 400);
    }
}
