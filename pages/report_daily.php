<?php
/**
 * 範例：選單分類「共用報表」底下的頁面（config.ini：group = "report"）
 * 一般 PHP 頁面，由 index.php?m=report_daily 載入；範例資料寫死，實作時改為查詢資料庫
 */
$rows = array(
    array(date("Y-m-d"), "ETCH-01", 98.2, 1),
    array(date("Y-m-d"), "ETCH-02", 96.7, 3),
    array(date("Y-m-d"), "CVD-01",  99.1, 0),
);
?>
<div class="panel terminal">
    <div class="panel-head">&gt; DAILY REPORT :: <?php echo e($rows[0][0]); ?></div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>日期</th><th>機台</th><th>稼動率 %</th><th>異常次數</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r[0]); ?></td>
                    <td><?php echo e($r[1]); ?></td>
                    <td><?php echo e(number_format($r[2], 1)); ?></td>
                    <td><?php echo e($r[3]); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
