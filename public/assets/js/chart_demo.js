/*
 * chart_demo 頁面 (jQuery 1.12 + ECharts 5)
 * 三個區塊：single 單台趨勢 / newest 最新值排排站 / tbt 歷史趨勢排排站
 * 後端回傳格式：{ "APAN01": { "Date": [...], "Data": [...] }, ... }
 * 只用 ES5 語法，避免舊瀏覽器出錯
 */
(function ($, window) {
    'use strict';

    var CFG = window.AISLE_CHART_DEMO;
    if (!$ || !CFG) {
        return;
    }

    // ---------- 主題 ----------
    // 機台顏色：8 色類別色盤（已用 dataviz validate_palette.js 檢查深色底 #001406：
    // 亮度、彩度、色盲區分、對比全部通過）。同機型內依序取色，1980 實線 / 2000 虛線 + 斜線紋理，
    // 所以 APAN01 與 APAP01 同色但線型不同。顏色跟著機台走，篩選後不會變色。
    var PALETTE = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
    var INK = '#00ff41';       // 主要文字
    var INK_DIM = '#00b82e';   // 次要文字
    var GRID = '#0a2a12';      // 格線
    var AXIS = '#00661a';      // 軸線
    var BAND = 'rgba(0, 255, 65, 0.05)';  // TBT 交錯底色
    var FONT = '"Share Tech Mono", Consolas, "Courier New", "Microsoft JhengHei", monospace';

    var COLOR = {};
    var DASHED = {};
    (function assignColors() {
        var counter = {};
        $.each(CFG.tools, function (i, tool) {
            var model = CFG.models[tool] || '';
            counter[model] = counter[model] || 0;
            COLOR[tool] = PALETTE[counter[model] % PALETTE.length];
            DASHED[tool] = model === '2000';
            counter[model]++;
        });
    })();

    function colorOf(tool) {
        return COLOR[tool] || '#7dffb0';
    }

    function baseOption() {
        return {
            backgroundColor: 'transparent',
            textStyle: { color: INK, fontFamily: FONT },
            animationDuration: 400,
            tooltip: {
                backgroundColor: 'rgba(0, 10, 3, 0.95)',
                borderColor: INK_DIM,
                borderWidth: 1,
                textStyle: { color: INK, fontFamily: FONT, fontSize: 13 },
                extraCssText: 'box-shadow: 0 0 12px rgba(0,255,65,0.3);'
            },
            grid: { left: 50, right: 24, top: 36, bottom: 40, containLabel: false }
        };
    }

    function valueAxis() {
        return {
            type: 'value',
            name: 'Data',
            nameTextStyle: { color: INK_DIM },
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: INK_DIM },
            splitLine: { lineStyle: { color: GRID } },
            scale: true
        };
    }

    function axisLine() {
        return { lineStyle: { color: AXIS } };
    }

    // ---------- 共用 ----------
    var charts = {};
    var lastData = { single: {}, newest: {}, tbt: {} };
    var requests = {};

    function initChart(key, id) {
        charts[key] = window.echarts.init(document.getElementById(id));
        return charts[key];
    }

    function showMessage(chart, text) {
        chart.clear();
        chart.setOption({
            title: {
                text: text,
                left: 'center',
                top: 'middle',
                textStyle: { color: INK_DIM, fontSize: 14, fontWeight: 'normal', fontFamily: FONT }
            }
        });
    }

    function loadingOpts() {
        return { text: 'LOADING...', color: INK, textColor: INK, maskColor: 'rgba(0, 0, 0, 0.6)', fontFamily: FONT };
    }

    /** 取資料：同一區塊重複查詢時取消前一次 */
    function loadData(key, url, params, onData) {
        var chart = charts[key];
        if (requests[key]) {
            requests[key].abort();
        }
        chart.showLoading('default', loadingOpts());
        requests[key] = $.ajax({ url: url, data: params, dataType: 'json', cache: false })
            .done(function (res) {
                lastData[key] = res || {};
                chart.hideLoading();
                if ($.isEmptyObject(lastData[key])) {
                    showMessage(chart, '> 查無資料 // NO DATA');
                } else {
                    onData(lastData[key]);
                }
                renderTable(key);
            })
            .fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                chart.hideLoading();
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || ('HTTP ' + xhr.status);
                showMessage(chart, '> ERROR: ' + msg);
            });
    }

    /** 資料表檢視：機台 / 時間 / 數值 */
    function renderTable(key) {
        var $box = $('#cd-' + key + '-table');
        var rows = [];
        $.each(lastData[key], function (tool, s) {
            for (var i = 0; i < s.Date.length; i++) {
                rows.push('<tr><td>' + AISLE.escape(tool) + '</td><td>' + AISLE.escape(s.Date[i]) +
                    '</td><td>' + AISLE.escape(s.Data[i]) + '</td></tr>');
            }
        });
        $box.html(rows.length
            ? '<table class="table"><thead><tr><th>TOOL</th><th>DATE</th><th>DATA</th></tr></thead><tbody>' +
              rows.join('') + '</tbody></table>'
            : '<p class="dim">&gt; 查無資料</p>');
    }

    // ---------- 1. single ----------
    function drawSingle(data) {
        var tool = CFG.tools.length ? $('#cd-tool').val() : '';
        var s = data[tool];
        var points = [];
        for (var i = 0; i < s.Date.length; i++) {
            points.push([s.Date[i], s.Data[i]]);
        }
        var opt = baseOption();
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'line', lineStyle: { color: INK_DIM, type: 'dashed' } };
        opt.xAxis = {
            type: 'time',
            axisLine: axisLine(),
            axisLabel: { color: INK_DIM, hideOverlap: true, formatter: '{MM}-{dd}' },
            splitLine: { show: false }
        };
        opt.yAxis = valueAxis();
        opt.dataZoom = [{ type: 'inside' }];
        opt.series = [{
            name: tool,
            type: 'line',
            data: points,
            showSymbol: points.length <= 45,
            symbolSize: 8,
            lineStyle: { width: 2, type: DASHED[tool] ? 'dashed' : 'solid' },
            itemStyle: { color: colorOf(tool) },
            emphasis: { focus: 'series' }
        }];
        charts.single.clear();
        charts.single.setOption(opt);
    }

    // ---------- 2. newest ----------
    function drawNewest(data) {
        var tools = [];
        var bars = [];
        $.each(data, function (tool, s) {
            var n = s.Data.length - 1;
            tools.push(tool);
            var style = { color: colorOf(tool), borderRadius: [4, 4, 0, 0] };
            if (DASHED[tool]) {
                // 2000 機型加斜線紋理，不只靠顏色區分
                style.decal = { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [2, 4], rotation: Math.PI / 4, color: 'rgba(0,0,0,0.45)' };
            }
            bars.push({ value: s.Data[n], date: s.Date[n], itemStyle: style });
        });
        var opt = baseOption();
        opt.tooltip.trigger = 'item';
        opt.tooltip.formatter = function (p) {
            return AISLE.escape(p.name) + '<br>' + AISLE.escape(p.data.date) + '<br>Data: <b>' + AISLE.escape(p.value) + '</b>';
        };
        opt.xAxis = {
            type: 'category',
            data: tools,
            axisLine: axisLine(),
            axisTick: { show: false },
            axisLabel: { color: INK, interval: 0, rotate: tools.length > 14 ? 45 : 0 }
        };
        opt.yAxis = valueAxis();
        opt.yAxis.scale = false;
        opt.series = [{
            type: 'bar',
            data: bars,
            barMaxWidth: 36,
            barCategoryGap: '30%',
            emphasis: { itemStyle: { shadowBlur: 12, shadowColor: INK } },
            cursor: 'pointer'
        }];
        charts.newest.clear();
        charts.newest.setOption(opt);
    }

    // ---------- 3. TBT ----------
    // X 軸為類別軸：每台機台佔一段，段內依時間排列；每台一條 series，
    // 其他段落填 null，所以每台只畫在自己的區段，相鄰機台以交錯底色分隔
    function drawTbt(data) {
        var keys = [];      // 類別值（唯一）："APAN01|2026-01-01"
        var segments = [];  // { tool, from, to }
        $.each(data, function (tool, s) {
            var from = keys.length;
            for (var i = 0; i < s.Date.length; i++) {
                keys.push(tool + '|' + s.Date[i]);
            }
            segments.push({ tool: tool, from: from, to: keys.length - 1 });
        });

        var centers = {};
        var bands = [];
        $.each(segments, function (i, seg) {
            centers[Math.floor((seg.from + seg.to) / 2)] = seg.tool;
            if (i % 2 === 1) {
                bands.push([{ xAxis: keys[seg.from] }, { xAxis: keys[seg.to] }]);
            }
        });

        var series = [];
        var legend = [];
        $.each(segments, function (i, seg) {
            var s = data[seg.tool];
            var arr = new Array(keys.length);
            for (var k = 0; k < keys.length; k++) {
                arr[k] = null;
            }
            for (var j = 0; j < s.Data.length; j++) {
                arr[seg.from + j] = s.Data[j];
            }
            legend.push(seg.tool);
            series.push({
                name: seg.tool,
                type: 'line',
                data: arr,
                connectNulls: false,
                showSymbol: false,
                symbolSize: 8,
                lineStyle: { width: 2, type: DASHED[seg.tool] ? 'dashed' : 'solid' },
                itemStyle: { color: colorOf(seg.tool) },
                emphasis: { focus: 'series' }
            });
        });
        // 交錯底色放在一條不顯示的輔助 series 上
        series.push({
            name: '_bands',
            type: 'line',
            data: [],
            silent: true,
            markArea: { silent: true, itemStyle: { color: BAND }, data: bands }
        });

        var opt = baseOption();
        opt.grid.top = 64;
        opt.grid.bottom = 70;
        opt.legend = {
            type: 'scroll',
            top: 0,
            data: legend,
            textStyle: { color: INK, fontFamily: FONT },
            pageTextStyle: { color: INK },
            pageIconColor: INK,
            pageIconInactiveColor: AXIS,
            inactiveColor: '#1f4a2a',
            itemWidth: 22,
            itemHeight: 8
        };
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'line', lineStyle: { color: INK_DIM, type: 'dashed' } };
        opt.tooltip.formatter = function (params) {
            for (var i = 0; i < params.length; i++) {
                var p = params[i];
                if (p.value !== null && p.value !== undefined && p.seriesName !== '_bands') {
                    var date = String(p.name).split('|')[1];
                    return p.marker + AISLE.escape(p.seriesName) + '<br>' + AISLE.escape(date) +
                        '<br>Data: <b>' + AISLE.escape(p.value) + '</b>';
                }
            }
            return '';
        };
        opt.xAxis = {
            type: 'category',
            data: keys,
            boundaryGap: false,
            axisLine: axisLine(),
            axisTick: { show: false },
            axisLabel: {
                color: INK,
                interval: function (idx) { return centers.hasOwnProperty(idx); },
                formatter: function (v) { return String(v).split('|')[0]; }
            },
            splitLine: { show: false }
        };
        opt.yAxis = valueAxis();
        opt.dataZoom = [
            { type: 'inside' },
            {
                type: 'slider', height: 18, bottom: 12,
                borderColor: AXIS, fillerColor: 'rgba(0,255,65,0.12)',
                handleStyle: { color: INK_DIM }, moveHandleStyle: { color: INK_DIM },
                dataBackground: { lineStyle: { color: AXIS }, areaStyle: { color: 'rgba(0,255,65,0.08)' } },
                textStyle: { color: INK_DIM },
                labelFormatter: function (i, v) { return String(v).replace('|', ' '); }
            }
        ];
        opt.series = series;
        charts.tbt.clear();
        charts.tbt.setOption(opt);

        var n = 0;
        $.each(data, function (tool, s) { n += s.Data.length; });
        $('#cd-tbt-info').text('> ' + segments.length + ' 台機台 · ' + n + ' 筆 · 依機台排序，區段內依時間排序 · 實線 1980 / 虛線 2000 · 點圖例可開關機台');
    }

    // ---------- 選擇器 ----------
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function isDate(s) { return /^\d{4}-\d{2}-\d{2}$/.test(s); }

    function range() {
        var start = $.trim($('#cd-start').val());
        var end = $.trim($('#cd-end').val());
        var $msg = $('#cd-msg').text('');
        if (!isDate(start) || !isDate(end)) {
            $msg.text('> 日期格式需為 YYYY-MM-DD');
            return null;
        }
        if (start > end) {
            $msg.text('> 起始日期不可晚於結束日期');
            return null;
        }
        return { start: start, end: end };
    }

    function loadSingle() {
        var r = range();
        var tool = $('#cd-tool').val();
        if (!r || !tool) {
            return;
        }
        $('#cd-single-title').text('· ' + tool);
        loadData('single', CFG.urls.single, { tool: tool, start: r.start, end: r.end }, drawSingle);
    }

    function loadNewest() {
        loadData('newest', CFG.urls.newest, { model: $('#cd-model').val() }, drawNewest);
    }

    function loadTbt() {
        var r = range();
        if (!r) {
            return;
        }
        loadData('tbt', CFG.urls.tbt, { start: r.start, end: r.end }, drawTbt);
    }

    $(function () {
        if (!window.echarts) {
            $('.cd-chart').html('<p class="text-red">&gt; ECharts 載入失敗：請確認可連到 CDN，或執行 scripts/shell/fetch_vendor.sh 放到 public/assets/vendor/</p>');
            return;
        }
        initChart('single', 'cd-single');
        initChart('newest', 'cd-newest');
        initChart('tbt', 'cd-tbt');

        if (!CFG.tools.length) {
            $.each(charts, function (k, c) { showMessage(c, '> 沒有任何機台 // NO TOOLS'); });
            return;
        }

        $('#cd-filters').on('submit', function () {
            loadSingle();
            loadTbt();
            return false;
        });
        $('#cd-tool').on('change', loadSingle);
        $('#cd-model').on('change', loadNewest);
        $('.cd-quick').on('click', 'button', function () {
            var days = parseInt($(this).data('days'), 10);
            var end = new Date();
            var start = new Date(end.getTime() - (days - 1) * 86400000);
            $('#cd-start').val(ymd(start));
            $('#cd-end').val(ymd(end));
            loadSingle();
            loadTbt();
        });

        // 點最新值長條 → 切換單台機台
        charts.newest.on('click', function (p) {
            if (p.componentType === 'series' && p.name) {
                $('#cd-tool').val(p.name);
                loadSingle();
                $('html, body').animate({ scrollTop: $('#cd-single').offset().top - 80 }, 200);
            }
        });

        $('#cd-tbt-all').on('click', function () {
            charts.tbt.dispatchAction({ type: 'legendAllSelect' });
        });
        $('#cd-tbt-none').on('click', function () {
            $.each(lastData.tbt, function (tool) {
                charts.tbt.dispatchAction({ type: 'legendUnSelect', name: tool });
            });
        });

        $('.cd-table-btn').on('click', function () {
            $('#cd-' + $(this).data('chart') + '-table').toggle();
        });

        var resizeTimer = null;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                $.each(charts, function (k, c) { c.resize(); });
            }, 150);
        });

        loadSingle();
        loadNewest();
        loadTbt();
    });
})(window.jQuery, window);
