<?php
/**
 * 範例：一般 PHP 頁面（route = "pages/ee_daily_check.php"）
 *
 * 由 index.php?m=ee_daily_check 在權限檢查通過後載入，自動套用系統版面。
 * 這裡不需要寫任何權限程式；可用變數：$user（登入者）、$page（config.ini 的頁面設定）。
 * 新增這類頁面：放一個 .php 到 pages/，再在 config.ini 加 [page.xxx] 並寫進 [access] 即可。
 */
$items = array(
    array('id' => 'chk_pressure', 'label' => '腔體壓力在規格內'),
    array('id' => 'chk_gas',      'label' => '氣體管路無洩漏警報'),
    array('id' => 'chk_pm',       'label' => 'PM 紀錄已更新'),
    array('id' => 'chk_interlock','label' => '安全連鎖測試通過'),
);
?>
<div class="panel terminal">
    <div class="panel-head">&gt; DAILY CHECK :: <?php echo e(date('Y-m-d')); ?></div>
    <div class="panel-body">
        <p class="dim">&gt; operator: <?php echo e($user['display_name']); ?></p>
        <?php foreach ($items as $item): ?>
            <p class="line">
                <label class="inline" style="color:#00ff41">
                    <input type="checkbox" class="daily-item" id="<?php echo e($item['id']); ?>">
                    <?php echo e($item['label']); ?>
                </label>
            </p>
        <?php endforeach; ?>
        <p class="line" id="daily-status">&gt; 0 / <?php echo count($items); ?> completed</p>
    </div>
</div>
<script>
$(function () {
    var total = $('.daily-item').length;
    $('.daily-item').on('change', function () {
        var done = $('.daily-item:checked').length;
        $('#daily-status').text('> ' + done + ' / ' + total + ' completed' + (done === total ? ' :: ALL CLEAR' : ''));
    });
});
</script>
