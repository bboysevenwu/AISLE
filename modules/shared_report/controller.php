<?php
/**
 * 共用報表（config.ini：EE、PE 都可見）
 * 示範動作層級權限：config.ini 的 action.export = "PE"
 * EE 可以看報表，但 export 動作會被 App 擋下 (403)
 */
class SharedReportController extends Controller
{
    public function indexAction()
    {
        $this->render('index', array(
            'rows'      => $this->data(),
            'canExport' => $this->can('export'),
        ));
    }

    /** index.php?m=shared_report&a=export  → 下載 CSV */
    public function exportAction()
    {
        audit($this->module, 'export');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="aisle_report_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM，讓 Excel 正確顯示中文
        fputcsv($out, array('日期', '產出', '良率(%)', '當機次數'));
        foreach ($this->data() as $r) {
            fputcsv($out, array($r['date'], $r['output'], $r['yield'], $r['downs']));
        }
        fclose($out);
    }

    private function data()
    {
        $rows = array();
        for ($i = 6; $i >= 0; $i--) {
            $rows[] = array(
                'date'   => date('Y-m-d', strtotime('-' . $i . ' day')),
                'output' => 1000 + $i * 37,
                'yield'  => number_format(95 + ($i % 4) * 0.8, 1),
                'downs'  => $i % 3,
            );
        }
        return $rows;
    }
}
