<div class="pi-summary" id="pi-summary">
<?php foreach ($categories as $key => $label): ?>
    <a class="pi-sum-card" href="#" data-category="<?php echo e($key); ?>">
        <span class="pi-sum-title"><?php echo e($label); ?></span>
        <span class="pi-sum-counts">
            <span class="pi-st pi-st-open" title="OPEN" data-count="open">0</span>
            <span class="pi-st pi-st-pending" title="PENDING" data-count="pending">0</span>
            <span class="pi-st pi-st-close" title="CLOSE" data-count="close">0</span>
        </span>
    </a>
<?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-head">&gt; SEARCH
        <span class="pi-live" id="pi-live" title="自動刷新狀態">● LIVE</span>
    </div>
    <div class="panel-body">
        <form id="pi-filter" class="pi-filter" autocomplete="off">
            <div class="pi-f pi-f-wide">
                <label for="f-q">KEYWORD</label>
                <input type="text" id="f-q" name="q" placeholder="ToolID / 標題 / 內容 / remark / sponsor / 處理者">
            </div>
            <div class="pi-f">
                <label for="f-tool">TOOL ID</label>
                <input type="text" id="f-tool" name="tool" list="pi-tools">
            </div>
            <div class="pi-f">
                <label for="f-category">CATEGORY</label>
                <select id="f-category" name="category">
                    <option value="">全部</option>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="pi-f">
                <label>STATUS</label>
                <div class="pi-checks">
                <?php foreach ($statuses as $key => $label): ?>
                    <label class="pi-check"><input type="checkbox" name="status" value="<?php echo e($key); ?>"<?php echo $key !== 'close' ? ' checked' : ''; ?>> <?php echo e($label); ?></label>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="pi-f pi-f-date">
                <label for="f-from">發生日期 FROM</label>
                <input type="text" id="f-from" name="from" placeholder="YYYY-MM-DD" maxlength="10">
            </div>
            <div class="pi-f pi-f-date">
                <label for="f-to">TO</label>
                <input type="text" id="f-to" name="to" placeholder="YYYY-MM-DD" maxlength="10">
            </div>
            <div class="pi-f pi-f-btns">
                <button type="submit" class="btn">[ 搜尋 ]</button>
                <button type="button" class="btn btn-ghost" id="pi-reset">[ 清除 ]</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">&gt; ISSUES <span id="pi-count"></span>
        <button type="button" class="btn btn-sm pi-head-btn" id="pi-new">[ + 新增 ISSUE ]</button>
    </div>
    <div class="panel-body pi-table-wrap">
        <table class="table pi-table">
            <thead>
                <tr>
                    <th>#</th><th>TOOL ID</th><th>CATEGORY</th><th>ISSUE</th><th>STATUS</th>
                    <th>SPONSOR</th><th>處理者</th><th>REMARK</th><th>發生日期</th><th>更新</th>
                </tr>
            </thead>
            <tbody id="pi-rows">
                <tr><td colspan="10" class="dim">&gt; LOADING...</td></tr>
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
            <form id="pi-form" autocomplete="off">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="version" value="0">
                <div class="pi-form-grid">
                    <div>
                        <label for="e-tool">TOOL ID *</label>
                        <input type="text" id="e-tool" name="tool_id" list="pi-tools" maxlength="64" required>
                    </div>
                    <div>
                        <label for="e-category">CATEGORY *</label>
                        <select id="e-category" name="category" required>
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="e-status">STATUS *</label>
                        <select id="e-status" name="status">
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="e-date">發生日期 *</label>
                        <input type="text" id="e-date" name="occurred_on" placeholder="YYYY-MM-DD" maxlength="10" required>
                    </div>
                    <div>
                        <label for="e-sponsor">SPONSOR</label>
                        <input type="text" id="e-sponsor" name="sponsor" list="pi-people" maxlength="100">
                    </div>
                    <div>
                        <label for="e-owner">現在處理者</label>
                        <input type="text" id="e-owner" name="owner" list="pi-people" maxlength="100">
                    </div>
                </div>
                <label for="e-title">ISSUE *</label>
                <input type="text" id="e-title" name="title" maxlength="200" required>
                <label for="e-desc">說明（可直接 Ctrl+V 貼上圖片）</label>
                <textarea id="e-desc" name="description" rows="4"></textarea>
                <label for="e-remark">REMARK</label>
                <textarea id="e-remark" name="remark" rows="2"></textarea>

                <label>IMAGES</label>
                <div class="pi-paste" id="pi-paste" tabindex="0">
                    &gt; 在此按 Ctrl+V 貼上截圖、拖曳圖片進來，或
                    <label class="pi-file">選擇檔案<input type="file" id="pi-file" accept="image/png,image/jpeg,image/gif" multiple></label>
                </div>
                <div class="pi-images" id="pi-images"></div>

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

<datalist id="pi-tools">
<?php foreach ($suggest['tools'] as $t): ?>
    <option value="<?php echo e($t); ?>">
<?php endforeach; ?>
</datalist>
<datalist id="pi-people">
<?php foreach ($suggest['people'] as $p): ?>
    <option value="<?php echo e($p); ?>">
<?php endforeach; ?>
</datalist>

<script>
window.PENDING_ISSUE = <?php echo js($config); ?>;
</script>
<script src="<?php echo e(asset('js/pending_issue.js')); ?>"></script>
