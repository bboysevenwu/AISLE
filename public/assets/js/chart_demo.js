/*
 * chart_demo 頁面 (jQuery 1.12 + ECharts 5)
 * 三個區塊：single 單台趨勢 / newest 最新值排排站 / tbt 歷史趨勢排排站
 * 後端回傳格式：{ "APAN01": { "Date": [...], "Data": [...] }, ... }
 *
 * 資料載入方式：
 *   進入頁面時只呼叫一次 tbt，取回日期區間內「全部機台」的資料存在 cache，三個區塊都從這份資料取得：
 *     TBT 直接畫；single 取出所選機台；Newest_TBT 取每台在區間內的最後一筆（再依機型篩選）
 *   之後切換機台、切換機型只用 cache 重畫，不再發 request；
 *   只有改日期區間（查詢 / 快速區間）才會重新取 tbt。
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
    var cache = { tbt: null };   // 後端回傳的區間內全部機台資料
    var shown = { single: {}, newest: {}, tbt: {} };  // 各圖目前顯示的資料（資料表用）
    var request = null;

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

    /** 取資料，成功時呼叫 onData(res)；失敗時在 errorCharts 上顯示錯誤 */
    function fetchJson(url, params, errorCharts, onData) {
        $.each(errorCharts, function (i, c) { c.showLoading('default', loadingOpts()); });
        return $.ajax({ url: url, data: params, dataType: 'json', cache: false })
            .done(function (res) {
                $.each(errorCharts, function (i, c) { c.hideLoading(); });
                onData(res || {});
            })
            .fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || ('HTTP ' + xhr.status);
                $.each(errorCharts, function (i, c) {
                    c.hideLoading();
                    showMessage(c, '> ERROR: ' + msg);
                });
            });
    }

    /** 把資料畫到某個圖；沒有資料時顯示 NO DATA，並更新資料表 */
    function show(key, data, draw) {
        shown[key] = data;
        if ($.isEmptyObject(data)) {
            showMessage(charts[key], '> 查無資料 // NO DATA');
        } else {
            draw(data);
        }
        renderTable(key);
    }

    /** 資料表檢視：機台 / 時間 / 數值 */
    function renderTable(key) {
        var $box = $('#cd-' + key + '-table');
        var rows = [];
        $.each(shown[key], function (tool, s) {
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
        var tool;
        for (tool in data) {
            if (data.hasOwnProperty(tool)) {
                break;
            }
        }
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

    /** 單台機台：從 cache.tbt 取出，不發 request */
    function showSingle() {
        var tool = $('#cd-tool').val();
        $('#cd-single-title').text(tool ? '· ' + tool : '');
        if (!cache.tbt || !tool) {
            return;
        }
        var data = {};
        if (cache.tbt[tool]) {
            data[tool] = cache.tbt[tool];
        }
        show('single', data, drawSingle);
    }

    /** 最新值：取 cache.tbt 每台的最後一筆（後端已依時間排序），再依機型篩選，不發 request */
    function showNewest() {
        if (!cache.tbt) {
            return;
        }
        var model = $('#cd-model').val();
        var data = {};
        $.each(cache.tbt, function (tool, s) {
            var n = s.Data.length - 1;
            if (n >= 0 && (model === 'All' || CFG.models[tool] === model)) {
                data[tool] = { Date: [s.Date[n]], Data: [s.Data[n]] };
            }
        });
        show('newest', data, drawNewest);
    }

    /** 取日期區間內全部機台的資料（TBT 與 single 共用） */
    function loadRange() {
        var r = range();
        if (!r) {
            return;
        }
        if (request) {
            request.abort();
        }
        request = fetchJson(CFG.urls.tbt, { start: r.start, end: r.end }, [charts.tbt, charts.single, charts.newest], function (res) {
            cache.tbt = res;
            show('tbt', res, drawTbt);
            showSingle();
            showNewest();
        });
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
            loadRange();
            return false;
        });
        $('#cd-tool').on('change', showSingle);
        $('#cd-model').on('change', showNewest);
        $('.cd-quick').on('click', 'button', function () {
            var days = parseInt($(this).data('days'), 10);
            var end = new Date();
            var start = new Date(end.getTime() - (days - 1) * 86400000);
            $('#cd-start').val(ymd(start));
            $('#cd-end').val(ymd(end));
            loadRange();
        });

        // 點最新值長條 → 切換單台機台
        charts.newest.on('click', function (p) {
            if (p.componentType === 'series' && p.name) {
                $('#cd-tool').val(p.name);
                showSingle();
                $('html, body').animate({ scrollTop: $('#cd-single').offset().top - 80 }, 200);
            }
        });

        $('#cd-tbt-all').on('click', function () {
            charts.tbt.dispatchAction({ type: 'legendAllSelect' });
        });
        $('#cd-tbt-none').on('click', function () {
            $.each(shown.tbt, function (tool) {
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

        loadRange();
    });
})(window.jQuery, window);
