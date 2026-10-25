<?php
/**
 * 製程配方（config.ini：只有 PE）
 * 範例資料寫死，實作時改為查詢資料庫
 */
class PeRecipeController extends Controller
{
    public function indexAction()
    {
        $keyword = $this->query('q');
        $rows = array(
            array('id' => 'RCP-1001', 'step' => 'ETCH',  'version' => 'v3.2', 'owner' => 'pe_lin'),
            array('id' => 'RCP-1002', 'step' => 'CVD',   'version' => 'v1.8', 'owner' => 'pe_chen'),
            array('id' => 'RCP-1003', 'step' => 'CMP',   'version' => 'v2.0', 'owner' => 'pe_wang'),
            array('id' => 'RCP-1004', 'step' => 'LITHO', 'version' => 'v5.1', 'owner' => 'pe_lin'),
        );
        if ($keyword !== '') {
            $filtered = array();
            foreach ($rows as $r) {
                if (stripos($r['id'] . ' ' . $r['step'] . ' ' . $r['owner'], $keyword) !== false) {
                    $filtered[] = $r;
                }
            }
            $rows = $filtered;
        }
        $this->render('index', array('rows' => $rows, 'keyword' => $keyword));
    }
}
