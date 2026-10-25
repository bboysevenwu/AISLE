<?php
/**
 * 範例：獨立頁面 / 既有網站的舊頁面（route = "public/legacy/pe_notice.php"）
 *
 * 使用者直接開啟 /legacy/pe_notice.php。唯一要加的是第一行的 require：
 * auth_check.php 會用這個檔案的路徑對照 config.ini 找到頁面代號 pe_notice，
 * 檢查目前身份是否在 [access] 清單內；通過後才會執行下面的程式。
 * 可用變數：$AUTH_USER（登入者）、$AUTH_PAGE（頁面設定）。
 */
require_once dirname(__FILE__) . '/../../core/auth_check.php';

// 以下是頁面原本的內容；layout_start() / layout_end() 是選用的，可套用系統版面
layout_start();
?>
<div class="panel terminal">
    <div class="panel-head">&gt; PROCESS NOTICE</div>
    <div class="panel-body">
        <p class="line">&gt; 2026-10-23　CVD 配方 RCP-1002 升版至 v1.9，請於下批次生效。</p>
        <p class="line">&gt; 2026-10-21　CMP 研磨液更換供應商，前 3 批需加做量測。</p>
        <p class="line dim">&gt; viewer: <?php echo e($AUTH_USER['display_name']); ?> (<?php echo e($AUTH_USER['role']); ?>)</p>
    </div>
</div>
<?php
layout_end();
