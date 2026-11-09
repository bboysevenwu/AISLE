<?php
/**
 * Pending Issue Control Table
 * 可用變數：$cfg（pending_issue.ini 整理後的設定）、$canTrash、$export、$config（給 JS 的設定）
 */
?>
<div class="pi-tabs" id="pi-tabs" role="tablist">
    <a href="#" class="pi-tab active" data-model="">全部 <span class="pi-tab-n" data-model-count=""></span></a>
<?php foreach ($cfg['models'] as $key => $label): ?>
    <a href="#" class="pi-tab" data-model="<?php echo e($key); ?>"><?php echo e($label); ?> <span class="pi-tab-n" data-model-count="<?php echo e($key); ?>"></span></a>
<?php endforeach; ?>
</div>

<div class="pi-summary" id="pi-summary">
<?php foreach ($cfg['categories'] as $key => $label): ?>
    <div class="pi-sum-card" data-category="<?php echo e($key); ?>">
        <a href="#" class="pi-sum-title" data-sum-cat="<?php echo e($key); ?>" title="只看這個分類"><?php echo e($label); ?></a>
        <span class="pi-sum-counts">
        <?php foreach ($cfg['statuses'] as $st => $stLabel): ?>
            <a href="#" class="pi-st pi-st-<?php echo e($st); ?>" data-sum-cat="<?php echo e($key); ?>" data-sum-status="<?php echo e($st); ?>" title="只看 <?php echo e($label . ' / ' . $stLabel); ?>">
                <small><?php echo e($stLabel); ?></small><b data-count="<?php echo e($st); ?>">0</b>
            </a>
        <?php endforeach; ?>
        </span>
    </div>
<?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-head">&gt; FILTER
        <span class="pi-live" id="pi-live" title="多人同步狀態">● LIVE</span>
    </div>
    <div class="panel-body">
        <form id="pi-filter" class="pi-filter" autocomplete="off" onsubmit="return false;">
            <div class="pi-f">
                <label>ISSUE 狀態（可多選）</label>
                <div class="pi-checks" id="f-status">
                <?php foreach ($cfg['statuses'] as $key => $label): ?>
                    <label class="pi-check pi-check-<?php echo e($key); ?>"><input type="checkbox" name="status" value="<?php echo e($key); ?>"> <?php echo e($label); ?></label>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="pi-f pi-f-wide">
                <label>分類（可多選）</label>
                <div class="pi-checks" id="f-cat">
                <?php foreach ($cfg['categories'] as $key => $label): ?>
                    <label class="pi-check"><input type="checkbox" name="cat" value="<?php echo e($key); ?>"> <?php echo e($label); ?></label>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="pi-f">
                <label for="f-tool">機台 // TOOL</label>
                <input type="text" id="f-tool" name="tool" list="pi-tools" placeholder="例 APAN01">
            </div>
            <div class="pi-f">
                <label for="f-sponsor-text">SPONSOR</label>
                <div class="pi-combo" id="f-sponsor"></div>
            </div>
            <div class="pi-f pi-f-wide">
                <label for="f-q">關鍵字 // KEYWORD</label>
                <input type="text" id="f-q" name="q" placeholder="Issue / Remark / 機台">
            </div>
            <div class="pi-f pi-f-btns">
                <label class="pi-check"><input type="checkbox" id="f-overdue" name="overdue" value="1"> 只看 Check point 逾期</label>
            <?php if ($canTrash): ?>
                <label class="pi-check pi-check-trash"><input type="checkbox" id="f-trash" name="trash" value="1"> 顯示已刪除（ADMIN）</label>
            <?php endif; ?>
                <button type="button" class="btn btn-ghost btn-sm" id="pi-reset">[ 清除篩選 ]</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">&gt; CONTROL TABLE <span id="pi-count"></span>
        <span class="pi-head-btns">
        <?php if ($export): ?>
            <button type="button" class="btn btn-sm pi-head-btn" id="pi-export" title="匯出目前篩選與排序的結果">[ 匯出 CSV ]</button>
        <?php endif; ?>
            <button type="button" class="btn btn-sm pi-head-btn" id="pi-new">[ + 新增 ISSUE ]</button>
        </span>
    </div>
    <div class="panel-body pi-table-wrap">
        <table class="table pi-table">
            <thead>
                <tr>
                    <th data-sort="id">#</th>
                    <th data-sort="model">機型</th>
                    <th data-sort="tool_id">機台</th>
                    <th data-sort="category">分類</th>
                    <th data-sort="issue">ISSUE</th>
                    <th data-sort="initial_date">INITIAL DATE</th>
                    <th data-sort="status">STATUS</th>
                    <th data-sort="sponsor">SPONSOR</th>
                    <th data-sort="checkpoint_date">CHECK POINT</th>
                    <th data-sort="remark">REMARK</th>
                    <th data-sort="updated_at">更新</th>
                </tr>
            </thead>
            <tbody id="pi-rows">
                <tr><td colspan="11" class="dim">&gt; LOADING...</td></tr>
            </tbody>
        </table>
        <noscript><p class="text-red">&gt; 此頁面需要啟用 JavaScript</p></noscript>
    </div>
</div>

<!-- 新增 / 編輯視窗 -->
<div class="pi-modal" id="pi-modal" style="display:none">
    <div class="pi-dialog panel">
        <div class="panel-head"><span id="pi-modal-title">&gt; NEW ISSUE</span>
            <button type="button" class="pi-close" id="pi-cancel-x" title="關閉">×</button>
        </div>
        <div class="panel-body">
            <div class="alert alert-error" id="pi-error" style="display:none"></div>
            <div class="alert pi-warn" id="pi-editors" style="display:none"></div>
            <div class="alert pi-warn" id="pi-stale" style="display:none">
                <span id="pi-stale-text"></span>
                <button type="button" class="btn btn-sm" id="pi-stale-load">[ 載入最新 ]</button>
            </div>
            <form id="pi-form" autocomplete="off" onsubmit="return false;">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="version" value="0">
                <div class="pi-form-grid">
                    <div>
                        <label for="e-tool">機台 // TOOL *</label>
                        <input type="text" id="e-tool" name="tool_id" list="pi-tools" maxlength="64" placeholder="例 APAN01">
                    </div>
                    <div>
                        <label for="e-model">機型 // MODEL *</label>
                        <select id="e-model" name="model">
                            <option value="">-- 由機台自動判定 --</option>
                        <?php foreach ($cfg['models'] as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="e-category">分類 // CATEGORY *</label>
                        <select id="e-category" name="category">
                        <?php foreach ($cfg['categories'] as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="e-status">狀態 // STATUS *</label>
                        <select id="e-status" name="status">
                        <?php foreach ($cfg['statuses'] as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="e-sponsor-text">SPONSOR *</label>
                        <div class="pi-combo" id="e-sponsor"></div>
                    </div>
                    <div>
                        <label for="e-initial">INITIAL DATE *</label>
                        <input type="date" id="e-initial" name="initial_date" placeholder="YYYY-MM-DD" maxlength="10">
                    </div>
                    <div>
                        <label for="e-checkpoint">CHECK POINT DATE</label>
                        <input type="date" id="e-checkpoint" name="checkpoint_date" placeholder="YYYY-MM-DD" maxlength="10">
                    </div>
                </div>
                <label for="e-issue">ISSUE 描述 *</label>
                <textarea id="e-issue" name="issue" rows="4"></textarea>
                <label for="e-remark">REMARK</label>
                <textarea id="e-remark" name="remark" rows="3"></textarea>

                <label>圖片 // IMAGES</label>
                <div class="pi-paste" id="pi-paste" tabindex="0">
                    <span id="pi-paste-hint">&gt; 在編輯視窗中按 Ctrl+V 貼上截圖、把圖片拖曳到這裡，或</span>
                    <label class="pi-file">選擇檔案<input type="file" id="pi-file" accept="image/png,image/jpeg,image/gif" multiple></label>
                </div>
                <div class="pi-images" id="pi-images"></div>

                <div class="pi-history" id="pi-history" style="display:none">
                    <label>狀態歷程 // STATUS LOG</label>
                    <ol id="pi-history-list"></ol>
                </div>

                <p class="dim pi-meta" id="pi-meta"></p>
                <div class="pi-actions">
                    <button type="submit" class="btn" id="pi-save">[ 儲存 ]</button>
                    <button type="button" class="btn btn-ghost" id="pi-cancel">[ 取消 ]</button>
                    <button type="button" class="btn btn-red pi-del" id="pi-delete" style="display:none">[ 刪除 ]</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 存檔衝突（409） -->
<div class="pi-modal pi-modal-top" id="pi-conflict" style="display:none">
    <div class="pi-dialog pi-dialog-sm panel">
        <div class="panel-head">&gt; ⚠ CONFLICT</div>
        <div class="panel-body">
            <p id="pi-conflict-msg"></p>
            <table class="table pi-diff">
                <thead><tr><th>欄位</th><th>你的版本</th><th>最新版本</th></tr></thead>
                <tbody id="pi-conflict-rows"></tbody>
            </table>
            <div class="pi-actions">
                <button type="button" class="btn btn-red" id="pi-conflict-overwrite" title="用你畫面上的內容覆蓋最新版本">[ 覆蓋（用我的版本） ]</button>
                <button type="button" class="btn" id="pi-conflict-load" title="放棄你的修改，載入最新內容">[ 載入最新 ]</button>
                <button type="button" class="btn btn-ghost" id="pi-conflict-cancel">[ 取消，繼續編輯 ]</button>
            </div>
        </div>
    </div>
</div>

<!-- IE11 警告 -->
<div class="pi-modal pi-modal-top" id="pi-ie" style="display:none">
    <div class="pi-dialog pi-dialog-sm panel">
        <div class="panel-head">&gt; ⚠ BROWSER WARNING</div>
        <div class="panel-body">
            <p>偵測到 <b>Internet Explorer 11</b>。</p>
            <p>這個頁面在 IE11 上<b>無法用 Ctrl+V 貼上截圖</b>，部分畫面也可能顯示不正常。<br>
               請改用 <b>Chrome</b> 或 <b>Edge</b> 開啟。</p>
            <p class="dim">繼續使用時，圖片仍可用拖曳或「選擇檔案」上傳。</p>
            <div class="pi-actions">
                <button type="button" class="btn" id="pi-ie-ok">[ 我知道了，繼續使用 ]</button>
            </div>
        </div>
    </div>
</div>

<datalist id="pi-tools"></datalist>

<script>
window.PENDING_ISSUE = <?php echo js($config); ?>;
</script>
<script src="<?php echo e(asset('js/pending_issue.js')); ?>"></script>
