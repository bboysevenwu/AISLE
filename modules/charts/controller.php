<?php
/**
 * charts 模組：多張圖表共用的 Controller
 *
 * 每張 chart 是 config/config.ini 裡的一個頁面，route 都指向 module:charts，
 * 頁面代號 = chart 名稱轉小寫，例：
 *   [page.bslr]  route = "module:charts"   →  charts/BSLr.ini + charts/BSLr.php
 *
 *   index.php?m=bslr                                      頁面
 *   index.php?m=bslr&a=tbt&start=&end=                    區間內全部機台的資料
 *
 * 頁面只呼叫 tbt 一次，三個區塊都從這份資料取得：
 *   TBT 直接畫；single 取出單台；Newest_TBT 取每台在區間內的最後一筆
 * 切換機台 / 機型只在瀏覽器端篩選，不再發 request；改日期區間才重新呼叫 tbt
 *
 * 回傳格式：
 *   { "APAN01": { "Date": ["2026-01-01", ...],
 *                 "Data": { "M1BF_Z_C1": [1.0, ...], "M1BF_Z_C2": [1.1, ...], ... } }, ... }
 * 機台依名稱自然排序，每台內部依時間排序；Data 只含 ini 用到的參數，
 * 每個參數陣列與 Date 一樣長，沒有值的位置是 null。錯誤時回 HTTP 400 / 500 + {"ok":false,"error":"..."}
 *
 * 新增 chart 不需要修改這個檔案：見 charts/_template.ini、charts/_template.php
 */
require_once dirname(__FILE__) . '/ChartConfig.php';
require_once dirname(__FILE__) . '/ChartSource.php';

class ChartsController extends Controller
{
    /** @var ChartConfig */
    private $cfg = null;
    /** @var ChartSource */
    private $source = null;

    public function indexAction()
    {
        try {
            $cfg = $this->cfg();
            $tools = $this->sortTools($this->source()->tools());
        } catch (Exception $e) {
            $this->render('error', array('message' => $this->errorMessage($e)));
            return;
        }
        $models = array();
        foreach ($tools as $t) {
            $models[$t] = $cfg->modelOf($t);
        }
        $this->render('index', array(
            'chart'      => $cfg->name,
            'plots'      => $cfg->plots(),
            'tools'      => $tools,
            'models'     => $models,
            'modelRules' => $cfg->models(),
            'quickDays'  => $cfg->quickDays(),
            'start'      => date('Y-m-d', strtotime('-' . ($cfg->defaultDays() - 1) . ' day')),
            'end'        => date('Y-m-d'),
            'source'     => $cfg->source(),
        ));
    }

    /** 所有機台在期間內的資料（三個區塊共用） */
    public function tbtAction()
    {
        try {
            $cfg = $this->cfg();
            $range = $this->range($cfg->maxDays());
            if ($range === null) {
                return;
            }
            $tools = $this->sortTools($this->source()->tools());
            $params = $cfg->params();
            $data = $tools ? $this->source()->series($tools, $params, $range[0], $range[1]) : array();
            $data = $this->source()->toToolKeys($data);   // machine_id → tool_id
            $this->output($this->normalize($data, $tools, $params));
        } catch (Exception $e) {
            $this->json(array('ok' => false, 'error' => $this->errorMessage($e)), 500);
        }
    }

    /** 這個頁面對應的 chart 設定 */
    private function cfg()
    {
        if ($this->cfg === null) {
            $name = ChartConfig::nameForPage($this->module);
            if ($name === null) {
                throw new RuntimeException('charts/ 裡找不到與頁面代號 ' . $this->module . ' 同名（不分大小寫）的 ini');
            }
            $this->cfg = new ChartConfig($name);
        }
        return $this->cfg;
    }

    private function source()
    {
        if ($this->source === null) {
            $this->source = ChartSource::create($this->cfg());
        }
        return $this->source;
    }

    /**
     * 設定錯誤（RuntimeException）直接顯示訊息，方便部署者修正；
     * 其他錯誤（例如資料庫連線、SQL 錯誤）可能含主機資訊，只在 app.ini debug = 1 時顯示，否則寫到 log
     */
    private function errorMessage(Exception $e)
    {
        error_log('[AISLE charts:' . $this->module . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (get_class($e) === 'RuntimeException' || Config::get('app', 'debug', 0)) {
            return $e->getMessage();
        }
        return '資料查詢失敗（' . get_class($e) . '），詳細內容請看 PHP error log';
    }

    // ------------------------------------------------------------------

    /** 讀取並檢查 start / end，失敗時已輸出錯誤並回傳 null */
    private function range($maxDays)
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
        if ($days > $maxDays) {
            $this->fail('查詢區間最多 ' . $maxDays . ' 天');
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
     *   - 每台依 Date 排序；Data 只保留 $params，依 $params 的順序輸出，缺少的參數整列補 null
     *   - 數值轉成數字，不是數字的轉成 null；所有參數都是 null 的時間點丟掉
     */
    private function normalize($data, $tools, $params)
    {
        $out = array();
        foreach ($tools as $tool) {
            if (!isset($data[$tool]['Date'], $data[$tool]['Data'])
                || !is_array($data[$tool]['Date']) || !is_array($data[$tool]['Data'])) {
                continue;
            }
            $dates = array_values($data[$tool]['Date']);
            $cols = array();
            foreach ($params as $p) {
                $cols[$p] = isset($data[$tool]['Data'][$p]) && is_array($data[$tool]['Data'][$p])
                    ? array_values($data[$tool]['Data'][$p]) : array();
            }
            $rows = array();  // array(date, array(param => value))
            foreach ($dates as $i => $date) {
                $values = array();
                $any = false;
                foreach ($params as $p) {
                    $v = null;
                    if (isset($cols[$p][$i]) && is_numeric($cols[$p][$i])) {
                        $v = (float) $cols[$p][$i];
                        $any = true;
                    }
                    $values[$p] = $v;
                }
                if ($any) {
                    $rows[] = array((string) $date, $values);
                }
            }
            if (!$rows) {
                continue;
            }
            usort($rows, array('ChartsController', 'cmpRow'));
            $series = array('Date' => array(), 'Data' => array());
            foreach ($params as $p) {
                $series['Data'][$p] = array();
            }
            foreach ($rows as $r) {
                $series['Date'][] = $r[0];
                foreach ($params as $p) {
                    $series['Data'][$p][] = $r[1][$p];
                }
            }
            $out[$tool] = $series;
        }
        return $out;
    }

    public static function cmpRow($a, $b)
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
