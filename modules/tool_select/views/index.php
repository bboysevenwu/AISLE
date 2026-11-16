<?php
/**
 * 設備選機表
 * 可用變數：$error（設定錯誤）、$models（機型頁籤）、$config（給 JS 的設定）
 */
?>
<?php if ($error !== ''): ?>
<div class="alert alert-error">&gt; 設定錯誤：<?php echo e($error); ?></div>
<?php endif; ?>

<!-- ts-root：全螢幕時放大這一整塊（頁籤 + 表格），下拉選單也在裡面 -->
<div class="ts-root" id="ts-root">
<div class="pi-tabs" id="ts-tabs" role="tablist">
<?php foreach ($models as $m): ?>
    <a href="#" class="pi-tab" data-model="<?php echo e($m['key']); ?>"><?php echo e($m['label']); ?><?php echo $m['error'] !== '' ? ' ⚠' : ''; ?></a>
<?php endforeach; ?>
</div>

<div class="panel ts-panel">
    <div class="panel-head">&gt; <span id="ts-title">TOOL SELECT</span>
        <span class="ts-head-right">
            <span class="dim" id="ts-updated"></span>
            <button type="button" class="btn btn-sm" id="ts-refresh" title="重新讀取數值檔">[ ⟳ 重新整理 ]</button>
            <button type="button" class="btn btn-sm" id="ts-fs" title="選機表全螢幕（快捷鍵 F）">[ ⛶ 全螢幕 ]</button>
        </span>
    </div>
    <div class="panel-body">
        <div class="ts-toolbar">
            <div class="ts-field">
                <label>分類 // CATEGORY</label>
                <div class="ts-dd" id="ts-cat"></div>
            </div>
            <div class="ts-field">
                <label for="ts-q">關鍵字 // KEYWORD</label>
                <input type="text" id="ts-q" class="ts-q" placeholder="項目 / 分類，空白分隔多個" autocomplete="off">
            </div>
            <div class="ts-field">
                <label>機台 // TOOL</label>
                <div class="ts-dd" id="ts-tool"></div>
            </div>
            <div class="ts-field ts-field-check">
                <label class="ts-check"><input type="checkbox" id="ts-ooc-only"> 只顯示有超規的項目</label>
            </div>
            <div class="ts-field ts-field-btns">
                <button type="button" class="btn btn-sm btn-ghost" id="ts-clear">[ 清除篩選 ]</button>
                <?php if ($config['canExport']): ?>
                <button type="button" class="btn btn-sm" id="ts-export" title="匯出目前篩選結果">[ 匯出 CSV ]</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="ts-infobar">
            <span class="dim" id="ts-summary"></span>
            <span class="ts-legend" id="ts-legend"></span>
        </div>
        <div class="alert pi-warn" id="ts-msg" style="display:none"></div>
        <div class="alert alert-error" id="ts-error" style="display:none"></div>
        <div class="ts-wrap" id="ts-wrap">
            <div class="dim ts-empty">&gt; LOADING...</div>
        </div>
        <noscript><p class="text-red">&gt; 此頁面需要啟用 JavaScript</p></noscript>
    </div>
</div>
</div><!-- /ts-root -->

<script>
window.TOOL_SELECT = <?php echo js($config); ?>;
</script>
<script src="<?php echo e(asset('js/tool_select.js')); ?>"></script>
