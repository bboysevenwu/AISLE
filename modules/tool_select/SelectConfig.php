<?php
/**
 * 讀取選機表設定：config/tool_select.ini（共用）+ config/tool_select/<機型>.ini（機型專屬）
 *
 *   ToolSelectConfig::shared()       共用設定（機型、超規底色、狀態底色）
 *   ToolSelectConfig::models()       機型代碼 => 顯示名稱
 *   ToolSelectConfig::forModel($m)   該機型的機台、分類、項目（含每台的有效 spec）
 *   ToolSelectConfig::judge($v, $s)  數值 + spec → '' | 'ooc' | 'oos'
 *
 * 每次請求只讀一次檔案。設定錯誤丟 ToolSelectConfigException，頁面會顯示訊息
 */
class ToolSelectConfigException extends RuntimeException
{
}

class ToolSelectConfig
{
    /** spec 四條的 key，順序 = 表格欄位順序 */
    public static $SPEC_KEYS = array('upper_oos', 'upper_ooc', 'lower_ooc', 'lower_oos');
    public static $COLOR_KEYS = array('ooc_bg', 'oos_bg', 'ooc_bg_light', 'oos_bg_light');

    private static $shared = null;
    private static $models = array();

    public static function shared()
    {
        if (self::$shared !== null) {
            return self::$shared;
        }
        $ini = self::parse(AISLE_ROOT . '/config/tool_select.ini', 'config/tool_select.ini');

        $models = array();
        foreach (array_get($ini, 'models', array()) as $k => $v) {
            $k = strtoupper(trim($k));
            if (preg_match('/^[A-Z0-9_]{1,16}$/', $k)) {
                $models[$k] = (string) $v;
            }
        }
        if (!$models) {
            throw new ToolSelectConfigException('config/tool_select.ini 的 [models] 沒有任何機型');
        }

        $defaults = array(
            'ooc_bg' => '#7A5A00', 'oos_bg' => '#8B0000', 'ooc_bg_light' => '#FFE08A', 'oos_bg_light' => '#FF9C9C',
        );
        $colorIni = array_get($ini, 'color', array());
        $colors = array();
        foreach ($defaults as $k => $d) {
            $colors[$k] = self::color(array_get($colorIni, $k, ''), $d);
        }

        $status = array();
        foreach (array_get($ini, 'status', array()) as $k => $v) {
            $c = self::color($v, '');
            $k = trim((string) $k);
            if ($k !== '' && $c !== '') {
                $status[strtolower($k)] = $c;
            }
        }

        self::$shared = array('models' => $models, 'colors' => $colors, 'status' => $status);
        return self::$shared;
    }

    public static function models()
    {
        $s = self::shared();
        return $s['models'];
    }

    /**
     * 該機型的設定
     * @return array array(
     *   'model', 'label', 'json_path', 'sample',
     *   'tools'      => array('APAN01', ...),
     *   'categories' => array(代號 => 名稱),
     *   'items'      => array(array('id','category','label','key','unit','decimals',
     *                               'spec' => array(4 個值或 null), 'toolSpec' => array(機台 => array(4)),
     *                               'offset' => bool, 'target' => 預設 target, 'targets' => array(機台 => target),
     *                               'abs' => array(機台 => array(4))  偏移模式時每台換算後的絕對 spec,
     *                               'colors' => array(覆蓋的顏色), 'href'), ...))  已依分類排序
     *   偏移模式（項目有寫任何一行 target / target.<機台>）：spec 與機台覆蓋都是距離 target 的偏移量
     */
    public static function forModel($model)
    {
        if (isset(self::$models[$model])) {
            return self::$models[$model];
        }
        $shared = self::shared();
        if (!isset($shared['models'][$model])) {
            throw new ToolSelectConfigException('沒有這個機型：' . $model);
        }
        $rel = 'config/tool_select/' . $model . '.ini';
        $ini = self::parse(AISLE_ROOT . '/' . $rel, $rel);

        $page = array_get($ini, 'page', array());

        // 機台：大寫、去重複，保留順序
        $tools = array();
        foreach ((array) array_get(array_get($ini, 'tools', array()), 'tool', array()) as $t) {
            $t = strtoupper(trim((string) $t));
            if ($t !== '' && preg_match('/^[A-Z0-9][A-Z0-9_.\-]{0,63}$/', $t) && !in_array($t, $tools, true)) {
                $tools[] = $t;
            }
        }

        $categories = array();
        foreach (array_get($ini, 'category', array()) as $code => $label) {
            $code = trim((string) $code);
            if (!preg_match('/^[a-z0-9_]{1,32}$/', $code)) {
                throw new ToolSelectConfigException($rel . ' [category] 的代號「' . $code . '」只能用小寫英文、數字、底線');
            }
            $categories[$code] = (string) $label;
        }

        $items = array();
        $order = 0;
        foreach ($ini as $section => $v) {
            if (strpos($section, 'item.') !== 0 || !is_array($v)) {
                continue;
            }
            $id = substr($section, 5);
            $where = $rel . ' [' . $section . ']';
            if (!preg_match('/^[a-z0-9_]{1,64}$/', $id)) {
                throw new ToolSelectConfigException($where . '：項目代號只能用小寫英文、數字、底線');
            }
            $cat = trim((string) array_get($v, 'category', ''));
            if (!isset($categories[$cat])) {
                throw new ToolSelectConfigException($where . '：category「' . $cat . '」不在 [category] 裡');
            }

            $spec = array();
            foreach (self::$SPEC_KEYS as $k) {
                $spec[] = self::number(array_get($v, $k, ''), $where . ' ' . $k);
            }

            // target：target = 預設、target.APAN01 = 這台；寫了任何一行（含留空）→ 偏移模式
            $offset = false;
            $target = 0.0;
            $targets = array();
            foreach ($v as $k => $val) {
                $k = (string) $k;
                if ($k === 'target') {
                    $offset = true;
                    $n = self::number($val, $where . ' target');
                    $target = $n === null ? 0.0 : $n;
                } elseif (strpos($k, 'target.') === 0) {
                    $offset = true;
                    $tool = strtoupper(trim(substr($k, 7)));
                    if (!in_array($tool, $tools, true)) {
                        throw new ToolSelectConfigException($where . '：' . $k . ' 的機台 ' . $tool . ' 不在 [tools] 裡');
                    }
                    $n = self::number($val, $where . ' ' . $k);
                    if ($n !== null) {
                        $targets[$tool] = $n;
                    }
                }
            }
            self::checkSpec($spec, $where, $offset);

            // 機台覆蓋：upper_oos.APAN03 = 6
            $toolSpec = array();
            foreach ($v as $k => $val) {
                if (strpos($k, '.') === false || strpos($k, 'target.') === 0) {
                    continue;
                }
                list($base, $tool) = explode('.', $k, 2);
                $idx = array_search($base, self::$SPEC_KEYS, true);
                if ($idx === false) {
                    throw new ToolSelectConfigException($where . '：不認得的欄位 ' . $k . '（機台覆蓋只能用 upper_oos / upper_ooc / lower_ooc / lower_oos / target）');
                }
                $tool = strtoupper(trim($tool));
                if (!in_array($tool, $tools, true)) {
                    throw new ToolSelectConfigException($where . '：' . $k . ' 的機台 ' . $tool . ' 不在 [tools] 裡');
                }
                if (!isset($toolSpec[$tool])) {
                    $toolSpec[$tool] = $spec;
                }
                $toolSpec[$tool][$idx] = self::number($val, $where . ' ' . $k);
            }
            foreach ($toolSpec as $tool => $s) {
                self::checkSpec($s, $where . ' 機台 ' . $tool, $offset);
            }

            // 偏移模式：每台換算成絕對 spec（target + upper、target - lower），判斷邏輯不用改
            $abs = array();
            if ($offset) {
                foreach ($tools as $tool) {
                    $s = isset($toolSpec[$tool]) ? $toolSpec[$tool] : $spec;
                    $t = isset($targets[$tool]) ? $targets[$tool] : $target;
                    $abs[$tool] = array(
                        $s[0] === null ? null : round($t + $s[0], 10),
                        $s[1] === null ? null : round($t + $s[1], 10),
                        $s[2] === null ? null : round($t - $s[2], 10),
                        $s[3] === null ? null : round($t - $s[3], 10),
                    );
                }
            }

            $colors = array();
            foreach (self::$COLOR_KEYS as $k) {
                if (array_key_exists($k, $v) && trim((string) $v[$k]) !== '') {
                    $c = self::color($v[$k], '');
                    if ($c === '') {
                        throw new ToolSelectConfigException($where . '：' . $k . ' 必須是 Hex 顏色，例 "#7A0000"');
                    }
                    $colors[$k] = $c;
                }
            }

            $href = trim((string) array_get($v, 'href', ''));
            if ($href !== '' && !self::validHref($href)) {
                throw new ToolSelectConfigException($where . '：href 只能是 "?m=..."、"/..." 或 http(s):// 開頭');
            }

            $dec = trim((string) array_get($v, 'decimals', ''));
            $key = trim((string) array_get($v, 'key', ''));
            $items[] = array(
                'id'       => $id,
                'category' => $cat,
                'label'    => trim((string) array_get($v, 'label', '')) !== '' ? trim((string) $v['label']) : $id,
                'key'      => $key !== '' ? $key : $id,
                'unit'     => trim((string) array_get($v, 'unit', '')),
                'decimals' => $dec === '' ? null : max(0, min(8, (int) $dec)),
                'spec'     => $spec,
                'toolSpec' => $toolSpec,
                'offset'   => $offset,
                'target'   => $target,
                'targets'  => $targets,
                'abs'      => $abs,
                'colors'   => $colors,
                'href'     => $href,
                '_cat'     => array_search($cat, array_keys($categories), true),
                '_order'   => $order++,
            );
        }

        // 依分類順序排，同分類保留 ini 順序（usort 不穩定，所以比兩個欄位）
        usort($items, function ($a, $b) {
            if ($a['_cat'] !== $b['_cat']) {
                return $a['_cat'] < $b['_cat'] ? -1 : 1;
            }
            return $a['_order'] < $b['_order'] ? -1 : 1;
        });
        foreach ($items as $i => $it) {
            unset($items[$i]['_cat'], $items[$i]['_order']);
        }

        self::$models[$model] = array(
            'model'      => $model,
            'label'      => $shared['models'][$model],
            'json_path'  => trim((string) array_get($page, 'json_path', '')),
            'sample'     => (bool) array_get($page, 'sample', false),
            'tools'      => $tools,
            'categories' => $categories,
            'items'      => $items,
        );
        return self::$models[$model];
    }

    /** 該項目在某台機台的有效 spec（絕對值；偏移模式時已換算成 target ± 偏移） */
    public static function specOf($item, $tool)
    {
        if (!empty($item['offset'])) {
            return isset($item['abs'][$tool]) ? $item['abs'][$tool] : array(null, null, null, null);
        }
        return isset($item['toolSpec'][$tool]) ? $item['toolSpec'][$tool] : $item['spec'];
    }

    /**
     * 超規判定（剛好等於界限算正常）
     * @param float|null $v
     * @param array $s array(upper_oos, upper_ooc, lower_ooc, lower_oos)，null = 不判斷
     */
    public static function judge($v, $s)
    {
        if ($v === null) {
            return '';
        }
        if (($s[0] !== null && $v > $s[0]) || ($s[3] !== null && $v < $s[3])) {
            return 'oos';
        }
        if (($s[1] !== null && $v > $s[1]) || ($s[2] !== null && $v < $s[2])) {
            return 'ooc';
        }
        return '';
    }

    /** href：?m=...、/...、http(s)://...（不接受 //host 與 javascript: 等） */
    public static function validHref($href)
    {
        if (preg_match('/[\s"\'<>\\\\]/', $href)) {
            return false;
        }
        return (bool) preg_match('#^(\?|/(?!/)|https?://)#i', $href);
    }

    // ------------------------------------------------------------------

    private static function parse($file, $label)
    {
        if (!is_file($file)) {
            throw new ToolSelectConfigException('找不到設定檔 ' . $label);
        }
        $ini = @parse_ini_file($file, true);
        if ($ini === false) {
            throw new ToolSelectConfigException('INI 解析失敗：' . $label . '（字串請加雙引號）');
        }
        return $ini;
    }

    private static function number($value, $where)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new ToolSelectConfigException($where . ' 必須是數字或留空（目前是「' . $value . '」）');
        }
        return (float) $value;
    }

    /** lower_oos <= lower_ooc < upper_ooc <= upper_oos（只比有填的） */
    private static function checkOrder($s, $where)
    {
        list($uoos, $uooc, $looc, $loos) = $s;
        $bad = ($loos !== null && $looc !== null && $loos > $looc)
            || ($looc !== null && $uooc !== null && $looc >= $uooc)
            || ($uooc !== null && $uoos !== null && $uooc > $uoos)
            || ($loos !== null && $uoos !== null && $loos >= $uoos)
            || ($loos !== null && $uooc !== null && $loos >= $uooc)
            || ($looc !== null && $uoos !== null && $looc >= $uoos);
        if ($bad) {
            throw new ToolSelectConfigException($where . '：spec 必須符合 lower_oos <= lower_ooc < upper_ooc <= upper_oos');
        }
    }

    private static function checkSpec($s, $where, $offset)
    {
        if ($offset) {
            self::checkOffset($s, $where);
        } else {
            self::checkOrder($s, $where);
        }
    }

    /** 偏移模式：都是距離 target 的偏移量，必須 >= 0；同側 OOS >= OOC；上下同一層不能同時為 0 */
    private static function checkOffset($s, $where)
    {
        list($uoos, $uooc, $looc, $loos) = $s;
        foreach ($s as $i => $x) {
            if ($x !== null && $x < 0) {
                throw new ToolSelectConfigException($where . '：有寫 target 時 ' . self::$SPEC_KEYS[$i] .
                    ' 是距離 target 的偏移量，必須 >= 0（例 lower_ooc = 1.5 表示 target - 1.5）');
            }
        }
        if (($uoos !== null && $uooc !== null && $uoos < $uooc) || ($loos !== null && $looc !== null && $loos < $looc)) {
            throw new ToolSelectConfigException($where . '：有寫 target 時，同一側的 OOS 偏移量必須大於等於 OOC');
        }
        if (($uooc === 0.0 && $looc === 0.0) || ($uoos === 0.0 && $loos === 0.0)) {
            throw new ToolSelectConfigException($where . '：upper 與 lower 同一層的偏移量不能同時為 0');
        }
    }

    private static function color($value, $default)
    {
        $value = trim((string) $value);
        return preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value) ? strtoupper($value) : $default;
    }
}
