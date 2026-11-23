<?php
/**
 * 設備選機表（Tool Selection Matrix）
 *
 * 共用設定 config/tool_select.ini；機型專屬 config/tool_select/<機型>.ini（機台、分類、項目、spec、JSON 路徑）
 * 頁面唯讀；匯出 CSV 由 config/config.ini 的 [page.tool_select] action.export 決定哪些身份可用
 *
 *   index    頁面（表格、篩選都由 tool_select.js 處理）
 *   data     GET  該機型：機台、分類、項目（含每台有效 spec）、數值、資料時間
 *   export   GET  CSV：目前篩選的項目 × 機台（items=id,id&tools=T1,T2；不給 = 全部）
 *
 * 分類 <代號>.view / 項目 view 限定身份：modelConfig() 先依目前身份過濾，data 與 export 都拿不到看不到的分類、項目
 */
require_once dirname(__FILE__) . '/SelectConfig.php';
require_once dirname(__FILE__) . '/ValueSource.php';

class ToolSelectController extends Controller
{
    public function indexAction()
    {
        $error = '';
        $models = array();
        try {
            foreach (ToolSelectConfig::models() as $m => $label) {
                $modelError = '';
                try {
                    ToolSelectConfig::forModel($m);
                } catch (ToolSelectConfigException $e) {
                    $modelError = $e->getMessage();
                }
                $models[] = array('key' => $m, 'label' => $label, 'error' => $modelError);
            }
        } catch (ToolSelectConfigException $e) {
            $error = $e->getMessage();
        }

        $this->render('index', array(
            'error'  => $error,
            'models' => $models,
            'config' => array(
                'urls'      => array(
                    'data'   => url($this->module, 'data'),
                    'export' => url($this->module, 'export'),
                    'base'   => base_url() . '/index.php',
                ),
                'models'    => $models,
                'model'     => strtoupper($this->query('model')),
                'canExport' => $this->can('export'),
            ),
        ));
    }

    public function dataAction()
    {
        $cfg = $this->modelConfig();
        if ($cfg === null) {
            return;
        }
        $shared = ToolSelectConfig::shared();
        $vals = ToolSelectValues::forModel($cfg);

        $categories = array();
        foreach ($cfg['categories'] as $code => $label) {
            $categories[] = array(
                'code'  => $code,
                'label' => $label,
                'view'  => isset($cfg['restricted'][$code]) ? $cfg['restricted'][$code] : '',
            );
        }
        $items = array();
        foreach ($cfg['items'] as $it) {
            $items[] = array(
                'id'       => $it['id'],
                'category' => $it['category'],
                'label'    => $it['label'],
                'unit'     => $it['unit'],
                'decimals' => $it['decimals'],
                'spec'     => $it['spec'],
                'toolSpec' => (object) $it['toolSpec'],
                'offset'   => $it['offset'],
                'target'   => $it['target'],
                'targets'  => (object) $it['targets'],
                'abs'      => (object) $it['abs'],
                'colors'   => (object) $it['colors'],
                'href'     => $it['href'],
                'view'     => $it['view'] === null ? '' : ToolSelectConfig::roleTag($it['view']),
            );
        }

        $this->json(array(
            'ok'         => true,
            'model'      => $cfg['model'],
            'label'      => $cfg['label'],
            'tools'      => $cfg['tools'],
            'categories' => $categories,
            'noAccess'   => $cfg['noAccess'],
            'items'      => $items,
            'values'     => (object) $vals['values'],
            'status'     => $vals['status'],
            'message'    => $vals['message'],
            'updated'    => $vals['updated'],
            'colors'     => $shared['colors'],
            'statusColors' => (object) $shared['status'],
            'serverTime' => date('Y-m-d H:i:s'),
        ));
    }

    public function exportAction()
    {
        $cfg = $this->modelConfig();
        if ($cfg === null) {
            return;
        }
        $vals = ToolSelectValues::forModel($cfg);
        $values = $vals['values'];

        $tools = $this->pick($this->query('tools'), $cfg['tools'], true);
        $ids = array();
        foreach ($cfg['items'] as $it) {
            $ids[] = $it['id'];
        }
        $wanted = array_flip($this->pick($this->query('items'), $ids, false));

        $filename = 'tool_select_' . $cfg['model'] . '_' . date('Ymd_Hi') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM，Excel 直接開不會亂碼
        fputcsv($out, array_merge(array('分類', '項目', 'uOOS', 'uOOC', 'lOOC', 'lOOS'), $tools, array('OOC 機台', 'OOS 機台')));
        foreach ($cfg['items'] as $it) {
            if (!isset($wanted[$it['id']])) {
                continue;
            }
            $label = $it['label'] . ($it['unit'] !== '' ? ' (' . $it['unit'] . ')' : '');
            $line = array(self::safe($cfg['categories'][$it['category']]), self::safe($label));
            foreach ($it['spec'] as $k => $s) {
                // 偏移模式：與畫面相同，填帶正負號的偏移量（upper +、lower -）
                $sign = $it['offset'] ? ($k < 2 ? '+' : '-') : '';
                $line[] = $s === null ? '' : $sign . self::num($s, null);
            }
            $ooc = array();
            $oos = array();
            foreach ($tools as $tool) {
                $cell = isset($values[$it['id']][$tool]) ? $values[$it['id']][$tool] : null;
                if ($cell === null) {
                    $line[] = '';
                    continue;
                }
                if ($cell['v'] === null) {
                    $line[] = self::safe($cell['s']);
                    continue;
                }
                $line[] = self::num($cell['v'], $it['decimals']);
                $j = ToolSelectConfig::judge($cell['v'], ToolSelectConfig::specOf($it, $tool));
                if ($j === 'oos') {
                    $oos[] = $tool;
                } elseif ($j === 'ooc') {
                    $ooc[] = $tool;
                }
            }
            $line[] = implode(' ', $ooc);
            $line[] = implode(' ', $oos);
            fputcsv($out, $line);
        }
        fclose($out);
    }

    // ------------------------------------------------------------------

    /** 讀 ?model= 對應的機型設定；錯誤時已輸出 JSON 並回傳 null */
    private function modelConfig()
    {
        $model = strtoupper($this->query('model'));
        try {
            $models = ToolSelectConfig::models();
            if (!isset($models[$model])) {
                $this->json(array('ok' => false, 'error' => '沒有這個機型：' . $model), 404);
                return null;
            }
            // 依身份拿掉看不到的分類、項目：data、export、數值都只剩看得到的
            return ToolSelectConfig::forRole(ToolSelectConfig::forModel($model), $this->user['role']);
        } catch (ToolSelectConfigException $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 500);
            return null;
        }
    }

    /** 逗號分隔的清單 → 只留 $all 裡有的，依 $all 的順序；空白 = 全部 */
    private function pick($param, $all, $upper)
    {
        if ($param === '') {
            return $all;
        }
        $want = array();
        foreach (explode(',', $param) as $v) {
            $v = trim($v);
            $want[$upper ? strtoupper($v) : $v] = true;
        }
        $out = array();
        foreach ($all as $v) {
            if (isset($want[$v])) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function num($v, $decimals)
    {
        if ($decimals !== null) {
            return number_format($v, $decimals, '.', '');
        }
        // 去掉浮點數尾巴（0.1 + 0.2 之類），最多 10 位有效小數
        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
    }

    /** 避免 Excel 把 = + - @ 開頭的文字當公式執行 */
    private static function safe($s)
    {
        $s = (string) $s;
        return ($s !== '' && strpos('=+-@', $s[0]) !== false) ? "'" . $s : $s;
    }
}
