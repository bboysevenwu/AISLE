可用率公式放這裡，一個公式一個檔（選用）。

範例：config/tool_board.ini [availability] formula = "fab_hours"
  → 載入 modules/tool_board/formulas/FabHours.php 的 FabHoursAvailability

<?php
class FabHoursAvailability extends AvailabilityFormula
{
    public function compute($day, $tools, $downtime, $actions, $cfg)
    {
        // $downtime[tool_id] = array(array(開始 timestamp, 結束 timestamp), ...)
        // 回傳百分比（float），或 null 顯示 "—"
        // $this->downSeconds($downtime[$t], $from, $to) 可算某台在區間內的停機秒數
    }
}

注意：PHP 5.4 語法（不要用 ??、型別宣告、::class）。
