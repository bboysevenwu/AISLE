<?php
/**
 * 每日預估可用率（看板第一列）
 *
 * 換公式不必改模組程式：
 *   1. 新增 modules/tool_board/formulas/<名稱>.php，例 FabHours.php
 *   2. 檔案內寫 class FabHoursAvailability extends AvailabilityFormula，實作 compute()
 *   3. config/tool_board.ini（或機型檔）[availability] formula = "fab_hours"
 */
abstract class AvailabilityFormula
{
    /**
     * 計算某一天的可用率
     *
     * @param string $day      'Y-m-d'
     * @param array  $tools    該機型的機台清單（tool_id 陣列）
     * @param array  $downtime tool_id => array(array(開始 timestamp, 結束 timestamp), ...)，已合併、不重疊
     * @param array  $actions  原始 Action（公式要看類別、WIP 時用）
     * @param array  $cfg      該機型合併後的設定（ToolBoardConfig::forModel）
     * @return float|null      百分比；null 顯示 "—"
     */
    abstract public function compute($day, $tools, $downtime, $actions, $cfg);

    /** 某台機台在 [from, to) 之間的停機秒數 */
    protected function downSeconds($intervals, $from, $to)
    {
        $sum = 0;
        foreach ($intervals as $iv) {
            $s = max($iv[0], $from);
            $e = min($iv[1], $to);
            if ($e > $s) {
                $sum += $e - $s;
            }
        }
        return $sum;
    }
}

/** 預設：可用率 = Σ(每台當天可用時數) / (機台數 × 24h) × 100% */
class DefaultAvailability extends AvailabilityFormula
{
    public function compute($day, $tools, $downtime, $actions, $cfg)
    {
        if (!$tools) {
            return null;
        }
        $from = strtotime($day . ' 00:00:00');
        $to = strtotime($day . ' 00:00:00 +1 day');
        $total = 0;
        $avail = 0;
        foreach ($tools as $t) {
            $len = $to - $from;
            $down = isset($downtime[$t]) ? $this->downSeconds($downtime[$t], $from, $to) : 0;
            $total += $len;
            $avail += max(0, $len - $down);
        }
        return $total > 0 ? round($avail / $total * 100, 1) : null;
    }
}

/** 整理停機區間並逐日呼叫公式 */
class ToolBoardAvailability
{
    /**
     * @return array array('values' => array(Y-m-d => 百分比|null), 'hours' => array(Y-m-d => 可用時數), 'error' => '')
     */
    public static function compute($cfg, $actions, $firstDay, $days)
    {
        $out = array('values' => array(), 'hours' => array(), 'error' => '');
        try {
            $formula = self::formula($cfg['availability']['formula']);
        } catch (Exception $e) {
            $out['error'] = $e->getMessage();
            $formula = new DefaultAvailability();
        }
        $downtime = self::downtime($cfg, $actions);
        for ($i = 0; $i < $days; $i++) {
            $day = date('Y-m-d', strtotime($firstDay . ' +' . $i . ' day'));
            $v = $formula->compute($day, $cfg['tools'], $downtime, $actions, $cfg);
            $out['values'][$day] = $v === null ? null : round((float) $v, 1);
            // 提示用的可用時數一律用預設算法
            $from = strtotime($day . ' 00:00:00');
            $to = strtotime($day . ' 00:00:00 +1 day');
            $hours = 0;
            foreach ($cfg['tools'] as $t) {
                $down = 0;
                if (isset($downtime[$t])) {
                    foreach ($downtime[$t] as $iv) {
                        $s = max($iv[0], $from);
                        $e = min($iv[1], $to);
                        if ($e > $s) {
                            $down += $e - $s;
                        }
                    }
                }
                $hours += ($to - $from - $down) / 3600;
            }
            $out['hours'][$day] = round($hours, 1);
        }
        return $out;
    }

    /** 每台機台的停機區間（依設定決定類別、Monitor / Pilot 尾巴是否算），合併重疊 */
    public static function downtime($cfg, $actions)
    {
        $av = $cfg['availability'];
        $raw = array();
        foreach ($actions as $a) {
            $cat = isset($cfg['categories'][$a['category']]) ? $cfg['categories'][$a['category']] : null;
            if (!$cat || !$cat['counts_down']) {
                continue;
            }
            $s = strtotime($a['start_at']);
            $e = strtotime($a['end_at']);
            $raw[$a['tool_id']][] = array($s, $e);
            $mon = $a['need_monitor'] ? (int) round($a['monitor_hours'] * 3600) : 0;
            $pil = $a['need_pilot'] ? (int) round($a['pilot_hours'] * 3600) : 0;
            if ($mon > 0 && $av['count_monitor']) {
                $raw[$a['tool_id']][] = array($e, $e + $mon);
            }
            if ($pil > 0 && $av['count_pilot']) {
                $raw[$a['tool_id']][] = array($e + $mon, $e + $mon + $pil);
            }
        }
        $out = array();
        foreach ($raw as $tool => $list) {
            usort($list, function ($x, $y) {
                return $x[0] - $y[0];
            });
            $merged = array();
            foreach ($list as $iv) {
                $n = count($merged);
                if ($n && $iv[0] <= $merged[$n - 1][1]) {
                    $merged[$n - 1][1] = max($merged[$n - 1][1], $iv[1]);
                } else {
                    $merged[] = $iv;
                }
            }
            $out[$tool] = $merged;
        }
        return $out;
    }

    private static function formula($name)
    {
        if ($name === '' || $name === 'default') {
            return new DefaultAvailability();
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            throw new RuntimeException('可用率公式名稱不正確：' . $name);
        }
        $camel = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        $class = $camel . 'Availability';
        if (!class_exists($class, false)) {
            $file = dirname(__FILE__) . '/formulas/' . $camel . '.php';
            if (!is_file($file)) {
                throw new RuntimeException('找不到可用率公式 modules/tool_board/formulas/' . $camel . '.php，暫用預設公式');
            }
            require_once $file;
        }
        if (!class_exists($class, false) || !is_subclass_of($class, 'AvailabilityFormula')) {
            throw new RuntimeException('公式檔沒有 ' . $class . ' 類別（需 extends AvailabilityFormula），暫用預設公式');
        }
        return new $class();
    }
}
