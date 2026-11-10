<?php
/**
 * 機台借機與 PM 排程看板
 * 可用變數：$error（設定錯誤）、$models（機型頁籤）、$config（給 JS 的設定）
 */
?>
<?php if ($error !== ''): ?>
<div class="alert alert-error">&gt; 設定錯誤：<?php echo e($error); ?></div>
<?php endif; ?>

<div class="pi-tabs" id="tb-tabs" role="tablist">
<?php foreach ($models as $m): ?>
    <a href="#" class="pi-tab" data-model="<?php echo e($m['key']); ?>"><?php echo e($m['label']); ?>
        <span class="tb-tab-mode"><?php echo $m['error'] !== '' ? '⚠' : ($m['canEdit'] ? '✎' : ''); ?></span></a>
<?php endforeach; ?>
</div>

<div class="panel tb-panel">
    <div class="panel-head">&gt; <span id="tb-title">TOOL BOARD</span>
        <span class="tb-mode" id="tb-mode"></span>
        <span class="pi-live" id="tb-live" title="多人同步狀態">● LIVE</span>
    </div>
    <div class="panel-body">
        <div class="tb-toolbar">
            <input type="text" id="tb-search" class="tb-search" placeholder="搜尋 Tool ID" autocomplete="off">
            <span class="tb-online" id="tb-online"></span>
            <a href="#" class="tb-legend-btn" id="tb-legend-btn">[ 圖例 ]</a>
            <span class="tb-help dim" id="tb-help"></span>
        </div>
        <div class="tb-legend" id="tb-legend" style="display:none"></div>
        <div class="alert pi-warn tb-pm-msg" id="tb-pm-msg" style="display:none"></div>
        <div class="tb-wrap" id="tb-wrap">
            <div class="dim tb-loading">&gt; LOADING...</div>
        </div>
        <noscript><p class="text-red">&gt; 此頁面需要啟用 JavaScript</p></noscript>
    </div>
</div>

<!-- 右鍵選單 -->
<ul class="tb-menu" id="tb-menu" style="display:none"></ul>

<!-- 拖曳中的時間提示 -->
<div class="tb-drag-tip" id="tb-drag-tip" style="display:none"></div>

<!-- 新增 / 編輯 Action -->
<div class="pi-modal" id="tb-modal" style="display:none">
    <div class="pi-dialog pi-dialog-sm panel">
        <div class="panel-head"><span id="tb-modal-title">&gt; NEW ACTION</span>
            <button type="button" class="pi-close" id="tb-cancel-x" title="關閉">×</button>
        </div>
        <div class="panel-body">
            <div class="alert alert-error" id="tb-error" style="display:none"></div>
            <div class="alert pi-warn" id="tb-editors" style="display:none"></div>
            <div class="alert pi-warn" id="tb-stale" style="display:none">
                <span id="tb-stale-text"></span>
                <button type="button" class="btn btn-sm" id="tb-stale-load">[ 載入最新 ]</button>
            </div>
            <form id="tb-form" autocomplete="off" onsubmit="return false;">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="version" value="0">
                <input type="hidden" name="pm_type" value="">
                <div class="pi-form-grid">
                    <div>
                        <label for="tb-f-tool">機台 // TOOL</label>
                        <input type="text" id="tb-f-tool" name="tool_id" readonly>
                    </div>
                    <div>
                        <label for="tb-f-category">Action 類別 // CATEGORY *</label>
                        <select id="tb-f-category" name="category"></select>
                    </div>
                </div>
                <div class="pi-form-grid">
                    <div>
                        <label for="tb-f-sdate">開始 // START *</label>
                        <div class="tb-dt">
                            <input type="date" id="tb-f-sdate" name="start_date" required>
                            <input type="time" id="tb-f-stime" name="start_time" step="1800" required>
                        </div>
                    </div>
                    <div>
                        <label for="tb-f-edate">結束 // END *</label>
                        <div class="tb-dt">
                            <input type="date" id="tb-f-edate" name="end_date" required>
                            <input type="time" id="tb-f-etime" name="end_time" step="1800" required>
                        </div>
                    </div>
                </div>
                <p class="dim tb-duration" id="tb-duration"></p>

                <label>WIP 處理方式 // WIP MODE *</label>
                <div class="pi-checks tb-wip" id="tb-f-wip"></div>

                <div class="pi-form-grid">
                    <div>
                        <label class="pi-check"><input type="checkbox" name="need_monitor" value="1" id="tb-f-mon"> 需要 Monitor</label>
                        <div class="tb-hours" id="tb-mon-box">
                            <input type="number" name="monitor_hours" id="tb-f-monh" min="0.5" max="999" step="0.5" placeholder="時數"> h
                            <button type="button" class="btn btn-sm btn-ghost tb-quick" data-target="tb-f-monh" data-h="2">2h</button>
                            <button type="button" class="btn btn-sm btn-ghost tb-quick" data-target="tb-f-monh" data-h="4">4h</button>
                        </div>
                    </div>
                    <div>
                        <label class="pi-check"><input type="checkbox" name="need_pilot" value="1" id="tb-f-pilot"> 需要 Pilot（試產）</label>
                        <div class="tb-hours" id="tb-pilot-box">
                            <input type="number" name="pilot_hours" id="tb-f-pilh" min="0.5" max="999" step="0.5" placeholder="時數"> h
                            <button type="button" class="btn btn-sm btn-ghost tb-quick" data-target="tb-f-pilh" data-h="12">12h</button>
                            <button type="button" class="btn btn-sm btn-ghost tb-quick" data-target="tb-f-pilh" data-h="24">24h</button>
                        </div>
                    </div>
                </div>
                <p class="tb-return" id="tb-return"></p>

                <label for="tb-f-remark">備註 // REMARK</label>
                <textarea id="tb-f-remark" name="remark" rows="2" maxlength="500" placeholder="例：借機人、單號"></textarea>

                <div class="pi-history" id="tb-history" style="display:none">
                    <label>異動紀錄 // HISTORY</label>
                    <ol id="tb-history-list"></ol>
                </div>
                <p class="dim pi-meta" id="tb-meta"></p>
                <div class="pi-actions">
                    <button type="submit" class="btn" id="tb-save">[ 儲存 ]</button>
                    <button type="button" class="btn btn-ghost" id="tb-cancel">[ 取消 ]</button>
                    <button type="button" class="btn btn-red pi-del" id="tb-delete" style="display:none">[ 刪除 ]</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 存檔 / 拖曳衝突（409） -->
<div class="pi-modal pi-modal-top" id="tb-conflict" style="display:none">
    <div class="pi-dialog pi-dialog-sm panel">
        <div class="panel-head">&gt; ⚠ CONFLICT</div>
        <div class="panel-body">
            <p id="tb-conflict-msg"></p>
            <table class="table pi-diff">
                <thead><tr><th>欄位</th><th>你的版本</th><th>最新版本</th></tr></thead>
                <tbody id="tb-conflict-rows"></tbody>
            </table>
            <div class="pi-actions">
                <button type="button" class="btn btn-red" id="tb-conflict-overwrite" title="用你的內容覆蓋最新版本">[ 覆蓋（用我的版本） ]</button>
                <button type="button" class="btn" id="tb-conflict-load" title="放棄你的修改，使用最新內容">[ 使用最新版本 ]</button>
                <button type="button" class="btn btn-ghost" id="tb-conflict-cancel">[ 取消 ]</button>
            </div>
        </div>
    </div>
</div>

<!-- IE11 警告 -->
<div class="pi-modal pi-modal-top" id="tb-ie" style="display:none">
    <div class="pi-dialog pi-dialog-sm panel">
        <div class="panel-head">&gt; ⚠ BROWSER WARNING</div>
        <div class="panel-body">
            <p>偵測到 <b>Internet Explorer 11</b>。</p>
            <p>這個看板在 IE11 上<b>無法正常拖曳與顯示</b>，請改用 <b>Chrome</b> 或 <b>Edge</b> 開啟。</p>
            <div class="pi-actions">
                <button type="button" class="btn" id="tb-ie-ok">[ 我知道了 ]</button>
            </div>
        </div>
    </div>
</div>

<div class="tb-toast" id="tb-toast" style="display:none"></div>

<script>
window.TOOL_BOARD = <?php echo js($config); ?>;
</script>
<script src="<?php echo e(asset('js/tool_board.js')); ?>"></script>
