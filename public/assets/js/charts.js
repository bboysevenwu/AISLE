/*
 * charts 頁面 (jQuery 1.12 + ECharts 5)，所有 chart（BSLr、M1BF…）共用
 *
 * 三個區塊：single 單台趨勢 / newest 最新值排排站 / tbt 歷史趨勢排排站
 * 每個區塊依 charts/<名稱>.ini 的 [plot.xxx] 各畫一張圖（CFG.groups），
 * 同一組的參數（例 M1BF_Z_C1、M1BF_Z_C2）畫在同一張圖上。
 *
 * 後端回傳格式：
 *   { "APAN01": { "Date": [...], "Data": { "M1BF_Z_C1": [...], "M1BF_Z_C2": [...] } }, ... }
 *   每個參數陣列與 Date 等長，沒有值的位置是 null
 *
 * 資料載入方式：
 *   進入頁面時只呼叫一次 tbt，取回日期區間內「全部機台、全部參數」的資料存在 cache，所有圖都從這份資料取得：
 *     TBT 直接畫；single 取出所選機台；Newest_TBT 取每台每個參數在區間內的最後一個值（再依機型篩選）
 *   之後切換機台、切換機型只用 cache 重畫，不再發 request；
 *   只有改日期區間（查詢 / 快速區間）才會重新取 tbt。
 *
 * 編碼方式：顏色 = 機台，線型 / 長條紋理 = 參數（同組第 1 個參數實線無紋理，第 2 個虛線斜紋…）
 * 只用 ES5 語法，避免舊瀏覽器出錯
 */
(function ($, window) {
    'use strict';

    var CFG = window.AISLE_CHARTS;
    if (!$ || !CFG) {
        return;
    }

    // ---------- 主題 ----------
    // 機台顏色：8 色類別色盤（已用 dataviz validate_palette.js 檢查深色底 #001406：
    // 亮度、彩度、色盲區分、對比全部通過）。同機型內依序取色，APAN01 與 APAP01 同色，
    // 以 X 軸機台名稱區分。顏色跟著機台走，篩選後不會變色。
    var PALETTE = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
    // 參數：線型與長條紋理，依參數在組內的順序取用
    var LINE_TYPES = ['solid', 'dashed', 'dotted', [10, 4, 2, 4], [2, 6]];
    var LINE_NAMES = ['實線', '虛線', '點線', '點劃線', '稀點線'];
    var DECALS = [
        null,
        { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [2, 4], rotation: Math.PI / 4, color: 'rgba(0,0,0,0.5)' },
        { symbol: 'circle', symbolSize: 0.6, dashArrayX: [[4, 4]], dashArrayY: [4, 0], color: 'rgba(0,0,0,0.5)' },
        { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [2, 4], rotation: -Math.PI / 4, color: 'rgba(0,0,0,0.5)' },
        { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [1, 3], rotation: 0, color: 'rgba(0,0,0,0.5)' }
    ];
    var INK = '#00ff41';       // 主要文字
    var INK_DIM = '#00b82e';   // 次要文字
    var GRID = '#0a2a12';      // 格線
    var AXIS = '#00661a';      // 軸線
    var NEUTRAL = '#7dffb0';   // 圖例中代表「參數」的中性色
    var BAND = 'rgba(0, 255, 65, 0.05)';  // TBT 交錯底色
    var OOS_COLOR = '#ff3b3b';  // spec 線：OOS（規格界限）
    var OOC_COLOR = '#ffb000';  // spec 線：OOC（管制界限）
    var SPEC_KEYS = [
        { key: 'upper_oos', label: 'OOS', color: OOS_COLOR, type: 'solid' },
        { key: 'upper_ooc', label: 'OOC', color: OOC_COLOR, type: 'dashed' },
        { key: 'lower_ooc', label: 'OOC', color: OOC_COLOR, type: 'dashed' },
        { key: 'lower_oos', label: 'OOS', color: OOS_COLOR, type: 'solid' }
    ];
    var FONT = '"Share Tech Mono", Consolas, "Courier New", "Microsoft JhengHei", monospace';

    var COLOR = {};
    (function assignColors() {
        var counter = {};
        $.each(CFG.tools, function (i, tool) {
            var model = CFG.models[tool] || '';
            counter[model] = counter[model] || 0;
            COLOR[tool] = PALETTE[counter[model] % PALETTE.length];
            counter[model]++;
        });
    })();

    var GROUPS = {};
    $.each(CFG.groups, function (i, g) { GROUPS[g.id] = g; });

    function colorOf(tool) { return COLOR[tool] || NEUTRAL; }
    function lineTypeOf(i) { return LINE_TYPES[i % LINE_TYPES.length]; }
    function decalOf(i) { return DECALS[i % DECALS.length]; }
    function shortName(param, group) {
        // 圖例只顯示參數名稱與組標題不同的部分，例 M1BF_Z 組內的 M1BF_Z_C1 → C1
        var prefix = group.title + '_';
        return param.indexOf(prefix) === 0 && param.length > prefix.length ? param.substring(prefix.length) : param;
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
            grid: { left: 50, right: 24, top: 40, bottom: 40, containLabel: false }
        };
    }

    /** Y 軸；config 有設上下限就固定，否則自動（自動時會把 spec 線納入範圍）。kind = single / newest / tbt */
    function valueAxis(group, kind) {
        var lim = (group.limits && group.limits[kind]) || {};
        var axis = {
            type: 'value',
            name: group.unit || 'Data',
            nameTextStyle: { color: INK_DIM },
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: INK_DIM },
            splitLine: { lineStyle: { color: GRID } },
            scale: kind !== 'newest'
        };
        var spec = specValues(group);
        if (lim.min !== null && lim.min !== undefined) {
            axis.min = lim.min;
        } else if (spec.length) {
            axis.min = function (v) { return specBounds(v, spec, kind).min; };
        }
        if (lim.max !== null && lim.max !== undefined) {
            axis.max = lim.max;
        } else if (spec.length) {
            axis.max = function (v) { return specBounds(v, spec, kind).max; };
        }
        return axis;
    }

    /** 這組圖有設定的 spec 值（數字陣列） */
    function specValues(group) {
        var out = [];
        $.each(SPEC_KEYS, function (i, k) {
            var v = group.spec ? group.spec[k.key] : null;
            if (v !== null && v !== undefined) {
                out.push(v);
            }
        });
        return out;
    }

    /** 資料範圍 v（{min, max}）加上 spec 線後的 Y 軸上下限，取整到刻度間距 */
    function specBounds(v, spec, kind) {
        var lo = Math.min.apply(null, spec);
        var hi = Math.max.apply(null, spec);
        if (isFinite(v.min)) {
            lo = Math.min(lo, v.min);
        }
        if (isFinite(v.max)) {
            hi = Math.max(hi, v.max);
        }
        if (kind === 'newest') {   // 長條圖從 0 開始
            lo = Math.min(lo, 0);
            hi = Math.max(hi, 0);
        }
        var span = hi - lo;
        if (span <= 0) {
            span = Math.abs(hi) || 1;
        }
        var step = niceStep(span / 5);
        var min = Math.floor(lo / step) * step;
        var max = Math.ceil(hi / step) * step;
        return { min: +min.toFixed(10), max: +max.toFixed(10) };
    }

    function niceStep(x) {
        var p = Math.pow(10, Math.floor(Math.log(x) / Math.LN10));
        var f = x / p;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
    }

    /** spec 水平線：放在一條沒有資料的輔助 series 上；沒設定 spec 時回傳 null */
    function specSeries(group) {
        var lines = [];
        $.each(SPEC_KEYS, function (i, k) {
            var v = group.spec ? group.spec[k.key] : null;
            if (v === null || v === undefined) {
                return;
            }
            lines.push({
                name: k.key,
                yAxis: v,
                lineStyle: { color: k.color, type: k.type, width: 1.5 },
                label: {
                    show: true,
                    position: k.key.indexOf('upper') === 0 ? 'insideEndTop' : 'insideEndBottom',
                    color: k.color,
                    fontFamily: FONT,
                    formatter: k.label + ' ' + fmt(v)
                }
            });
        });
        if (!lines.length) {
            return null;
        }
        return {
            name: '_spec',
            type: 'line',
            data: [],
            silent: true,
            markLine: { silent: true, symbol: 'none', animation: false, data: lines }
        };
    }

    /** 有 spec 時把輔助 series 加到 series 陣列最後 */
    function pushSpec(series, group) {
        var s = specSeries(group);
        if (s) {
            series.push(s);
        }
    }

    function axisLine() {
        return { lineStyle: { color: AXIS } };
    }

    function legendBase(data) {
        return {
            type: 'scroll',
            top: 0,
            data: data,
            textStyle: { color: INK, fontFamily: FONT },
            pageTextStyle: { color: INK },
            pageIconColor: INK,
            pageIconInactiveColor: AXIS,
            inactiveColor: '#1f4a2a',
            itemWidth: 26,
            itemHeight: 10
        };
    }

    function fmt(v) {
        return v === null || v === undefined ? '-' : AISLE.escape(v);
    }

    // ---------- 圖表盒 ----------
    // boxes['tbt:m1bf_z'] = { kind, group, $el, chart, shown }
    var boxes = {};
    var cache = { tbt: null };
    var request = null;

    function eachBox(kind, fn) {
        $.each(boxes, function (key, box) {
            if (!kind || box.kind === kind) {
                fn(box);
            }
        });
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

    /** 把資料畫到圖表盒；沒有資料時顯示 NO DATA，並更新資料表 */
    function show(box, data, draw) {
        box.shown = data;
        if ($.isEmptyObject(data)) {
            showMessage(box.chart, '> 查無資料 // NO DATA');
        } else {
            draw(box, data);
        }
        renderTable(box);
    }

    /** 資料表檢視：機台 / 時間 / 該組每個參數一欄 */
    function renderTable(box) {
        var params = box.group.params;
        var head = '<tr><th>TOOL</th><th>DATE</th>';
        $.each(params, function (i, p) { head += '<th>' + AISLE.escape(p) + '</th>'; });
        head += '</tr>';
        var rows = [];
        $.each(box.shown, function (tool, s) {
            if (!$.isArray(s.Date)) {
                // newest：各參數的日期分開存，值後面附上日期
                var tr0 = '<tr><td>' + AISLE.escape(tool) + '</td><td>LATEST</td>';
                $.each(params, function (j, p) {
                    tr0 += '<td>' + fmt(s.Data[p][0]) + (s.Date[p][0] ? ' <span class="dim">(' + AISLE.escape(s.Date[p][0]) + ')</span>' : '') + '</td>';
                });
                rows.push(tr0 + '</tr>');
                return;
            }
            for (var i = 0; i < s.Date.length; i++) {
                var tr = '<tr><td>' + AISLE.escape(tool) + '</td><td>' + AISLE.escape(s.Date[i]) + '</td>';
                for (var j = 0; j < params.length; j++) {
                    tr += '<td>' + fmt(s.Data[params[j]] ? s.Data[params[j]][i] : null) + '</td>';
                }
                rows.push(tr + '</tr>');
            }
        });
        box.$el.find('.cd-table').html(rows.length
            ? '<table class="table"><thead>' + head + '</thead><tbody>' + rows.join('') + '</tbody></table>'
            : '<p class="dim">&gt; 查無資料</p>');
    }

    // ---------- 1. single：所選機台，組內每個參數一條線 ----------
    function drawSingle(box, data) {
        var group = box.group;
        var tool;
        for (tool in data) {
            if (data.hasOwnProperty(tool)) {
                break;
            }
        }
        var s = data[tool];
        var series = [];
        $.each(group.params, function (pi, param) {
            var points = [];
            var col = s.Data[param] || [];
            for (var i = 0; i < s.Date.length; i++) {
                points.push([s.Date[i], col[i] === undefined ? null : col[i]]);
            }
            series.push({
                name: param,
                type: 'line',
                data: points,
                connectNulls: !!group.connect,   // ini 的 connect = On：跳過空值把線連起來
                showSymbol: points.length <= 45,
                symbolSize: 8,
                color: colorOf(tool),
                lineStyle: { width: 2, type: lineTypeOf(pi) },
                emphasis: { focus: 'series' }
            });
        });
        var opt = baseOption();
        opt.legend = legendBase(group.params.slice(0));
        opt.legend.formatter = function (name) { return shortName(name, group); };
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'line', lineStyle: { color: INK_DIM, type: 'dashed' } };
        opt.xAxis = {
            type: 'time',
            axisLine: axisLine(),
            axisLabel: { color: INK_DIM, hideOverlap: true, formatter: '{MM}-{dd}' },
            splitLine: { show: false }
        };
        opt.yAxis = valueAxis(group, 'single');
        opt.dataZoom = [{ type: 'inside' }];
        pushSpec(series, group);
        opt.series = series;
        box.chart.clear();
        box.chart.setOption(opt);
    }

    // ---------- 2. newest：X 軸機台，組內每個參數一根長條 ----------
    function drawNewest(box, data) {
        var group = box.group;
        var tools = [];
        $.each(data, function (tool) { tools.push(tool); });
        var series = [];
        $.each(group.params, function (pi, param) {
            var bars = [];
            $.each(tools, function (ti, tool) {
                var s = data[tool];
                var style = { color: colorOf(tool), borderRadius: [4, 4, 0, 0] };
                if (decalOf(pi)) {
                    style.decal = decalOf(pi);
                }
                bars.push({ value: s.Data[param][0], date: s.Date[param][0], itemStyle: style });
            });
            // series 的 itemStyle 只決定圖例的樣子（中性色 + 該參數的紋理），每根長條另用機台色
            var legendStyle = { color: NEUTRAL };
            if (decalOf(pi)) {
                legendStyle.decal = decalOf(pi);
            }
            series.push({
                name: param,
                type: 'bar',
                data: bars,
                barMaxWidth: 28,
                barGap: '15%',
                itemStyle: legendStyle,
                emphasis: { focus: 'series', itemStyle: { shadowBlur: 12, shadowColor: INK } },
                cursor: 'pointer'
            });
        });
        var opt = baseOption();
        opt.legend = legendBase(group.params.slice(0));
        opt.legend.formatter = function (name) { return shortName(name, group); };
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'shadow', shadowStyle: { color: 'rgba(0,255,65,0.06)' } };
        opt.tooltip.formatter = function (params) {
            if (!params.length) {
                return '';
            }
            var html = '<b>' + AISLE.escape(params[0].name) + '</b>';
            $.each(params, function (i, p) {
                html += '<br>' + p.marker + AISLE.escape(p.seriesName) + '：<b>' + fmt(p.value) + '</b>' +
                    ' <span style="color:' + INK_DIM + '">(' + fmt(p.data.date) + ')</span>';
            });
            return html;
        };
        opt.xAxis = {
            type: 'category',
            data: tools,
            axisLine: axisLine(),
            axisTick: { show: false },
            axisLabel: { color: INK, interval: 0, rotate: tools.length > 14 ? 45 : 0 }
        };
        opt.yAxis = valueAxis(group, 'newest');
        pushSpec(series, group);
        opt.series = series;
        box.chart.clear();
        box.chart.setOption(opt);
    }

    // ---------- 3. TBT ----------
    // X 軸為類別軸：每台機台佔一段，段內依時間排列。每台每個參數一條 series，
    // 同一台的 series 用同一個名稱，所以圖例以機台為單位開關；其他段落填 null，
    // 所以每台只畫在自己的區段，相鄰機台以交錯底色分隔。
    function drawTbt(box, data) {
        var group = box.group;
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
        var paramOf = [];   // seriesIndex → 參數名稱（tooltip 用）
        $.each(segments, function (i, seg) {
            var s = data[seg.tool];
            legend.push(seg.tool);
            $.each(group.params, function (pi, param) {
                var col = s.Data[param] || [];
                var arr = new Array(keys.length);
                for (var k = 0; k < keys.length; k++) {
                    arr[k] = null;
                }
                for (var j = 0; j < s.Date.length; j++) {
                    arr[seg.from + j] = col[j] === undefined ? null : col[j];
                }
                paramOf.push(param);
                series.push({
                    name: seg.tool,
                    type: 'line',
                    data: arr,
                    connectNulls: !!group.connect,   // ini 的 connect = On：跳過空值把線連起來
                    showSymbol: false,
                    symbolSize: 8,
                    color: colorOf(seg.tool),
                    lineStyle: { width: 2, type: lineTypeOf(pi) },
                    emphasis: { focus: 'series' }
                });
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
        opt.legend = legendBase(legend);
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'line', lineStyle: { color: INK_DIM, type: 'dashed' } };
        opt.tooltip.formatter = function (params) {
            var html = '';
            $.each(params, function (i, p) {
                if (p.seriesName === '_bands' || p.seriesName === '_spec' || p.value === null || p.value === undefined) {
                    return;
                }
                if (!html) {
                    html = p.marker + AISLE.escape(p.seriesName) + '<br>' + AISLE.escape(String(p.name).split('|')[1]);
                }
                html += '<br>' + AISLE.escape(paramOf[p.seriesIndex]) + '：<b>' + fmt(p.value) + '</b>';
            });
            return html;
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
        opt.yAxis = valueAxis(group, 'tbt');
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
        pushSpec(series, group);
        opt.series = series;
        box.chart.clear();
        box.chart.setOption(opt);

        var styles = $.map(group.params, function (p, i) {
            return shortName(p, group) + ' ' + LINE_NAMES[i % LINE_NAMES.length];
        });
        box.$el.find('.cd-box-info').text(segments.length + ' 台 · ' + keys.length + ' 個時間點 · ' + styles.join(' / '));
    }

    // ---------- 從 cache 取出各圖要的資料 ----------
    /** 只保留某組的參數 */
    function pick(s, params) {
        var out = { Date: s.Date, Data: {} };
        $.each(params, function (i, p) { out.Data[p] = s.Data[p] || []; });
        return out;
    }

    function showTbt() {
        eachBox('tbt', function (box) {
            var data = {};
            $.each(cache.tbt, function (tool, s) { data[tool] = pick(s, box.group.params); });
            show(box, data, drawTbt);
        });
    }

    /** 單台機台：從 cache.tbt 取出，不發 request */
    function showSingle() {
        var tool = $('#cd-tool').val();
        $('.cd-single-title').text(tool ? '· ' + tool : '');
        if (!cache.tbt || !tool) {
            return;
        }
        eachBox('single', function (box) {
            var data = {};
            if (cache.tbt[tool]) {
                data[tool] = pick(cache.tbt[tool], box.group.params);
            }
            show(box, data, drawSingle);
        });
    }

    /**
     * 最新值：每台每個參數在區間內最後一個非 null 的值，再依機型篩選，不發 request
     * 各參數最後一筆的日期可能不同，所以 Date 也依參數分開存：
     *   { Date: { param: [日期] }, Data: { param: [值] } }
     */
    function showNewest() {
        if (!cache.tbt) {
            return;
        }
        var model = $('#cd-model').val() || 'All';  // ini 沒設定機型時沒有下拉選單
        eachBox('newest', function (box) {
            var data = {};
            $.each(cache.tbt, function (tool, s) {
                if (model !== 'All' && CFG.models[tool] !== model) {
                    return;
                }
                var latest = { Date: {}, Data: {} };
                var any = false;
                $.each(box.group.params, function (pi, p) {
                    var col = s.Data[p] || [];
                    latest.Date[p] = [null];
                    latest.Data[p] = [null];
                    for (var i = col.length - 1; i >= 0; i--) {
                        if (col[i] !== null && col[i] !== undefined) {
                            latest.Date[p] = [s.Date[i]];
                            latest.Data[p] = [col[i]];
                            any = true;
                            break;
                        }
                    }
                });
                if (any) {
                    data[tool] = latest;
                }
            });
            show(box, data, drawNewest);
        });
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

    /** 取日期區間內全部機台、全部參數的資料（所有圖共用），進頁面與改日期時呼叫 */
    function loadRange() {
        var r = range();
        if (!r) {
            return;
        }
        if (request) {
            request.abort();
        }
        eachBox(null, function (box) { box.chart.showLoading('default', loadingOpts()); });
        request = $.ajax({ url: CFG.urls.tbt, data: { start: r.start, end: r.end }, dataType: 'json', cache: false })
            .done(function (res) {
                cache.tbt = res || {};
                eachBox(null, function (box) { box.chart.hideLoading(); });
                showTbt();
                showSingle();
                showNewest();
            })
            .fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || ('HTTP ' + xhr.status);
                eachBox(null, function (box) {
                    box.chart.hideLoading();
                    showMessage(box.chart, '> ERROR: ' + msg);
                });
            });
    }

    $(function () {
        if (!window.echarts) {
            $('.cd-chart').html('<p class="text-red">&gt; ECharts 載入失敗：請確認可連到 CDN，或執行 scripts/shell/fetch_vendor.sh 放到 public/assets/vendor/</p>');
            return;
        }
        $('.cd-box').each(function () {
            var $el = $(this);
            var kind = $el.data('kind');
            var group = GROUPS[$el.data('group')];
            boxes[kind + ':' + group.id] = {
                kind: kind,
                group: group,
                $el: $el,
                chart: window.echarts.init($el.find('.cd-chart')[0]),
                shown: {}
            };
        });

        if (!CFG.tools.length) {
            eachBox(null, function (box) { showMessage(box.chart, '> 沒有任何機台 // NO TOOLS'); });
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
        eachBox('newest', function (box) {
            box.chart.on('click', function (p) {
                if (p.componentType === 'series' && p.name) {
                    $('#cd-tool').val(p.name);
                    showSingle();
                    var $first = $('.cd-box[data-kind="single"]').first();
                    if ($first.length) {
                        $('html, body').animate({ scrollTop: $first.offset().top - 80 }, 200);
                    }
                }
            });
        });

        function boxOf(el) {
            var $el = $(el).closest('.cd-box');
            return boxes[$el.data('kind') + ':' + $el.data('group')];
        }
        $('.cd-legend-all').on('click', function () {
            boxOf(this).chart.dispatchAction({ type: 'legendAllSelect' });
        });
        $('.cd-legend-none').on('click', function () {
            var box = boxOf(this);
            $.each(box.shown, function (tool) {
                box.chart.dispatchAction({ type: 'legendUnSelect', name: tool });
            });
        });
        $('.cd-table-btn').on('click', function () {
            boxOf(this).$el.find('.cd-table').toggle();
        });

        var resizeTimer = null;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                eachBox(null, function (box) { box.chart.resize(); });
            }, 150);
        });

        loadRange();
    });
})(window.jQuery, window);
