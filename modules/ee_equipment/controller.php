<?php
/**
 * 設備狀態（config.ini：只有 EE）
 * 示範：頁面 + jQuery AJAX 定時刷新 JSON
 * 範例資料寫死在 sampleData()，實作時改為查詢資料庫
 */
class EeEquipmentController extends Controller
{
    public function indexAction()
    {
        $this->render('index', array('rows' => $this->sampleData()));
    }

    /** index.php?m=ee_equipment&a=status  (AJAX) */
    public function statusAction()
    {
        $this->json(array('ok' => true, 'time' => now(), 'rows' => $this->sampleData()));
    }

    private function sampleData()
    {
        $states = array('RUN', 'RUN', 'RUN', 'IDLE', 'DOWN');
        $rows = array();
        foreach (array('ETCH-01', 'ETCH-02', 'CVD-01', 'PVD-03', 'CMP-02', 'LITHO-01') as $i => $tool) {
            $rows[] = array(
                'tool'  => $tool,
                'state' => $states[mt_rand(0, count($states) - 1)],
                'temp'  => mt_rand(180, 260) / 10,
                'wph'   => mt_rand(40, 120),
            );
        }
        return $rows;
    }
}
