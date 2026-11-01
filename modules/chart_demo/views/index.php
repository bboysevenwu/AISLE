<?php
// ECharts：public/assets/vendor/ 有檔案就用本機的（內網主機），否則走 CDN
$echartsLocal = 'vendor/echarts-5.4.3.min.js';
$echartsSrc = is_file(AISLE_ROOT . '/public/assets/' . $echartsLocal)
    ? asset($echartsLocal)
    : 'https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js';
?>
<div class="panel">
    <div class="panel-head">&gt; SELECTORS</div>
    <div class="panel-body">
        <form id="cd-filters" class="cd-filters" onsubmit="return false;">
            <div class="cd-field">
                <label for="cd-start">起始日期 // FROM</label>
                <input type="date" id="cd-start" value="<?php echo e($start); ?>" max="<?php echo e($end); ?>" placeholder="YYYY-MM-DD">
            </div>
            <div class="cd-field">
                <label for="cd-end">結束日期 // TO</label>
                <input type="date" id="cd-end" value="<?php echo e($end); ?>" max="<?php echo e($end); ?>" placeholder="YYYY-MM-DD">
            </div>
            <div class="cd-field">
                <label>快速區間</label>
                <span class="cd-quick">
                    <button type="button" class="btn btn-sm" data-days="7">7D</button>
                    <button type="button" class="btn btn-sm" data-days="30">30D</button>
                    <button type="button" class="btn btn-sm" data-days="90">90D</button>
                </span>
            </div>
            <div class="cd-field">
                <label for="cd-tool">機台 // TOOL</label>
                <select id="cd-tool">
                    <?php foreach ($tools as $t): ?>
                        <option value="<?php echo e($t); ?>"><?php echo e($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cd-field">
                <label>&nbsp;</label>
                <button type="submit" id="cd-apply" class="btn">[ 查詢 ]</button>
            </div>
        </form>
        <p class="cd-msg text-red" id="cd-msg"></p>
        <?php if ($source === 'sample'): ?>
            <p class="dim cd-note">&gt; 目前使用範例資料（app.ini [chart_demo] source = sample）。接資料庫請實作 modules/chart_demo/ChartDataSource.php 的部署者實作區。</p>
        <?php endif; ?>
    </div>
</div>

<?php
// 依 config/chart_demo.ini 決定每個區塊要畫哪些圖
$byKind = array('single' => array(), 'newest' => array(), 'tbt' => array());
foreach ($groups as $g) {
    foreach ($byKind as $kind => $list) {
        if ($g[$kind]) {
            $byKind[$kind][] = $g;
        }
    }
}
?>
<?php if ($byKind['single']): ?>
<div class="panel">
    <div class="panel-head">&gt; SINGLE // 單台機台趨勢 <span class="cd-single-title"></span></div>
    <div class="panel-body cd-boxes cd-boxes-grid">
        <?php foreach ($byKind['single'] as $g): ?>
        <div class="cd-box" data-kind="single" data-group="<?php echo e($g['id']); ?>">
            <div class="cd-box-head">
                <b><?php echo e($g['title']); ?></b>
                <span class="cd-box-tools"><button type="button" class="btn btn-sm cd-table-btn">[ 資料表 ]</button></span>
            </div>
            <div class="cd-chart"></div>
            <div class="cd-table"></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($byKind['newest']): ?>
<div class="panel">
    <div class="panel-head">&gt; NEWEST_TBT // 最新值排排站</div>
    <div class="panel-body cd-boxes">
        <div class="cd-sub">
            <label for="cd-model" class="inline">機型 // MODEL</label>
            <select id="cd-model">
                <option value="All">All</option>
                <option value="1980">1980（PAN）</option>
                <option value="2000">2000（PAP）</option>
            </select>
            <span class="dim">&gt; 每台取區間內最後一筆 · 點長條可切換上方的單台機台</span>
        </div>
        <?php foreach ($byKind['newest'] as $g): ?>
        <div class="cd-box" data-kind="newest" data-group="<?php echo e($g['id']); ?>">
            <div class="cd-box-head">
                <b><?php echo e($g['title']); ?></b>
                <span class="cd-box-tools"><button type="button" class="btn btn-sm cd-table-btn">[ 資料表 ]</button></span>
            </div>
            <div class="cd-chart"></div>
            <div class="cd-table"></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($byKind['tbt']): ?>
<div class="panel">
    <div class="panel-head">&gt; TBT // 歷史趨勢排排站</div>
    <div class="panel-body cd-boxes">
        <p class="dim cd-sub">&gt; 依機台排序，區段內依時間排序 · 顏色區分機台、線型區分參數 · 點圖例可開關機台</p>
        <?php foreach ($byKind['tbt'] as $g): ?>
        <div class="cd-box" data-kind="tbt" data-group="<?php echo e($g['id']); ?>">
            <div class="cd-box-head">
                <b><?php echo e($g['title']); ?></b> <span class="dim cd-box-info"></span>
                <span class="cd-box-tools">
                    <button type="button" class="btn btn-sm cd-legend-all">[ 全選 ]</button>
                    <button type="button" class="btn btn-sm cd-legend-none">[ 全不選 ]</button>
                    <button type="button" class="btn btn-sm cd-table-btn">[ 資料表 ]</button>
                </span>
            </div>
            <div class="cd-chart cd-chart-tall"></div>
            <div class="cd-table"></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo e($echartsSrc); ?>"></script>
<script>
window.AISLE_CHART_DEMO = {
    groups: <?php echo js($groups); ?>,
    tools:  <?php echo js($tools); ?>,
    models: <?php echo js((object) $models); ?>,
    urls: {
        tbt:    <?php echo js(url('chart_demo', 'tbt')); ?>
    }
};
</script>
<script src="<?php echo e(asset('js/chart_demo.js')); ?>"></script>
