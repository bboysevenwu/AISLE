可用率公式放這裡，一個公式一個檔（選用）。

範例：config/tool_board.ini [availability] formula = "fab_hours"
  → 載入 modules/tool_board/formulas/FabHours.php 的 FabHoursAvailability

每個機型可以用不同公式：在機型檔寫 [availability]，沒寫的機型沿用共用檔
  config/tool_board/PAN.ini   [availability]  formula = "pan_hours"    → PanHours.php
  config/tool_board/PAP.ini   [availability]  formula = "pap_hours"    → PapHours.php
  config/tool_board/POV.ini   （沒寫）                                  → 共用檔的 formula
同一個機型檔也可以寫 alert_below（低於幾 % 紅底）。
$cfg['model'] 是目前機型代碼，同一個公式檔也可以依機型分支。

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
