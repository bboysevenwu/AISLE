/*
 * AISLE 機台借機與 PM 排程看板（jQuery 1.12，ES5 寫法）
 *   - 機型頁籤：每個機型各自的機台、Action、WIP 選項、PM 射程、可用率
 *   - 左欄 Tool ID，右邊以天為單位的時間軸；色塊依時間絕對定位，跨天 / 半天都正確
 *   - 右鍵新增 / 編輯 / 複製 / 刪除；雙擊編輯；拖曳搬移、拉左右邊改起訖（Shift = 以小時對齊）
 *   - 多人同步：version 樂觀鎖（409 可選覆蓋 / 使用最新）＋ 每 N 秒輪詢 ＋「某某 編輯中 / 拖曳中」
 * 設定由 view 放在 window.TOOL_BOARD
 *
 * 時間一律當作「伺服器當地時間」處理：'Y-m-d H:i:s' 以 Date.UTC 轉成毫秒計算，
 * 不受使用者電腦時區影響。
 */
(function ($) {
    if (!$ || !window.TOOL_BOARD) {
        return;
    }
    var CFG = window.TOOL_BOARD;
    var DAY = 86400000, HOUR = 3600000;
    var WEEK = ['日', '一', '二', '三', '四', '五', '六'];
    var TOOL_COL = 120;
    var IS_IE = /Trident\/|MSIE /.test(window.navigator.userAgent);
    var STORE_KEY = 'aisle.tool_board.model';

    // ==================================================================
    // 小工具
    // ==================================================================
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    /** 'Y-m-d H:i:s' / 'Y-m-d' → 毫秒（當作 UTC，不受電腦時區影響） */
    function ms(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(String(s || ''));
        if (!m) {
            return NaN;
        }
        return Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
    }
    function d(t) { return new Date(t); }
    function ymd(t) { var x = d(t); return x.getUTCFullYear() + '-' + pad(x.getUTCMonth() + 1) + '-' + pad(x.getUTCDate()); }
    function hm(t) { var x = d(t); return pad(x.getUTCHours()) + ':' + pad(x.getUTCMinutes()); }
    function full(t) { return ymd(t) + ' ' + hm(t); }
    function short(t) { var x = d(t); return pad(x.getUTCMonth() + 1) + '/' + pad(x.getUTCDate()) + ' ' + hm(t); }
    function hours(h) { return (Math.round(h * 10) / 10) + 'h'; }
    function duration(msLen) {
        var h = Math.round(msLen / HOUR * 10) / 10;
        var days = Math.floor(h / 24), rest = Math.round((h - days * 24) * 10) / 10;
        return days ? days + ' 天' + (rest ? ' ' + rest + ' 小時' : '') : rest + ' 小時';
    }
    function errorText(xhr, fallback) {
        var r = xhr && xhr.responseJSON;
        return (r && r.error) || fallback || '連線失敗，請稍後再試';
    }
    function store(key, value) {
        try {
            if (value === undefined) {
                return window.localStorage.getItem(key);
            }
            window.localStorage.setItem(key, value);
        } catch (e) {
            // 瀏覽器停用 storage 時只是不記憶
        }
        return null;
    }
    function nowMs() { return new Date().getTime(); }

    // ==================================================================
    // 狀態
    // ==================================================================
    var state = {
        model: '',
        b: null,              // board 回應
        actions: {},          // id => action
        lanes: {},            // tool_id => $lane
        winStart: 0, winEnd: 0, todayMs: 0, cellW: 44,
        serverOffset: 0,      // 伺服器時間 - 電腦時間（毫秒）
        cursor: '',
        editors: {},
        editing: null,        // { id, mode: 'edit' | 'drag' }
        drag: null,
        queued: [],           // 拖曳中收到的輪詢結果，放開後再套用
        pollXhr: null,
        loadXhr: null,
        paused: false,
        lastActivity: nowMs(),
        modal: null           // 表單狀態
    };

    var $wrap = $('#tb-wrap'), $menu = $('#tb-menu'), $tip = $('#tb-drag-tip');
    var $modal = $('#tb-modal'), $form = $('#tb-form'), $conflict = $('#tb-conflict');

    function serverNow() { return nowMs() + state.serverOffset; }
    function canEdit() { return !!(state.b && state.b.canEdit); }
    function catOf(code) {
        var list = state.b ? state.b.categories : [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].code === code) {
                return list[i];
            }
        }
        return null;
    }
    function wipOf(code) {
        var list = state.b ? state.b.wip : [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].code === code) {
                return list[i];
            }
        }
        return null;
    }
    function catLabel(code) { var c = catOf(code); return c ? c.label : code; }
    function wipLabel(code) { var w = wipOf(code); return w ? w.label : code + '（已移除）'; }
    function tailMs(a) {
        return (a.need_monitor ? (a.monitor_hours || 0) * HOUR : 0) + (a.need_pilot ? (a.pilot_hours || 0) * HOUR : 0);
    }
    function inWindow(a) { return ms(a.start_at) < state.winEnd && ms(a.occupied_end) > state.winStart; }

    // ==================================================================
    // 提示訊息
    // ==================================================================
    var toastTimer = null;
    function toast(msg, type) {
        var $t = $('#tb-toast');
        $t.removeClass('is-error is-ok').addClass(type === 'error' ? 'is-error' : 'is-ok').text(msg).show();
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { $t.fadeOut(200); }, type === 'error' ? 6000 : 3000);
    }
    function setLive(kind, text) {
        $('#tb-live').removeClass('is-paused is-error')
            .addClass(kind === 'paused' ? 'is-paused' : (kind === 'error' ? 'is-error' : ''))
            .text(kind === 'live' ? '● LIVE' : (kind === 'paused' ? '❚❚ ' + text : '✕ ' + text));
    }

    // ==================================================================
    // 載入
    // ==================================================================
    function pickModel() {
        var keys = $.map(CFG.models, function (m) { return m.key; });
        var want = CFG.model || store(STORE_KEY) || keys[0];
        return $.inArray(want, keys) >= 0 ? want : keys[0];
    }

    function load(model) {
        state.model = model;
        store(STORE_KEY, model);
        $('#tb-tabs .pi-tab').removeClass('active').filter('[data-model="' + model + '"]').addClass('active');
        if (window.history && window.history.replaceState) {
            var url = window.location.href.replace(/([?&])model=[^&]*/, '$1').replace(/[?&]$/, '');
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'model=' + encodeURIComponent(model);
            window.history.replaceState(null, '', url.replace('?&', '?').replace('&&', '&'));
        }
        if (state.loadXhr) {
            state.loadXhr.abort();
        }
        if (state.pollXhr) {
            state.pollXhr.abort();
        }
        $wrap.html('<div class="dim tb-loading">&gt; LOADING...</div>');
        state.loadXhr = $.getJSON(CFG.urls.board, { model: model }).done(function (res) {
            setBoard(res);
            setLive('live');
        }).fail(function (xhr, st) {
            if (st !== 'abort') {
                $wrap.html('<div class="text-red tb-loading">&gt; ' + esc(errorText(xhr, '載入失敗')) + '</div>');
                setLive('error', '載入失敗');
            }
        }).always(function () {
            state.loadXhr = null;
        });
    }

    function setBoard(res) {
        state.b = res;
        state.cellW = res.cellWidth;
        state.winStart = ms(res.firstDay);
        state.winEnd = state.winStart + res.days * DAY;
        state.todayMs = ms(res.today);
        state.serverOffset = ms(res.serverTime) - nowMs();
        state.cursor = res.cursor;
        state.actions = {};
        state.editors = {};
        $.each(res.actions, function (i, a) { state.actions[a.id] = a; });
        $('#tb-title').text(res.label + ' // TOOL BOARD');
        renderMode();
        renderLegend();
        renderPmMsg();
        render();
        poll(true);
    }

    function renderMode() {
        $('#tb-mode').text(canEdit() ? '[ ✎ 可編輯 ]' : '[ 唯讀 ]').toggleClass('is-ro', !canEdit());
        $('#tb-help').text(canEdit()
            ? '右鍵格子新增 Action・雙擊色塊編輯・拖曳色塊搬移、拉左右邊改起訖（按住 Shift 以小時對齊）'
            : '唯讀：雙擊色塊可檢視內容；需要編輯請聯絡管理員把你加進這個機型的可編輯名單');
    }

    // ==================================================================
    // 畫看板
    // ==================================================================
    function render() {
        var b = state.b, cw = state.cellW, i, t;
        var scrollL = $wrap.scrollLeft(), scrollT = $wrap.scrollTop();
        var todayIdx = Math.round((state.todayMs - state.winStart) / DAY);
        var h = [];
        h.push('<table class="tb-grid" style="width:' + (TOOL_COL + b.days * cw) + 'px"><colgroup><col style="width:' + TOOL_COL + 'px">');
        for (i = 0; i < b.days; i++) {
            h.push('<col style="width:' + cw + 'px">');
        }
        h.push('</colgroup><thead><tr class="tb-head-date"><th class="tb-corner">TOOL ID</th>');
        for (i = 0; i < b.days; i++) {
            t = d(state.winStart + i * DAY);
            var wd = t.getUTCDay(), cls = [];
            if (i === todayIdx) { cls.push('tb-today'); }
            if (wd === 0 || wd === 6) { cls.push('tb-wkend'); }
            if (t.getUTCDate() === 1) { cls.push('tb-month'); }
            if (i < todayIdx) { cls.push('tb-past'); }
            h.push('<th class="' + cls.join(' ') + '" title="' + ymd(t.getTime()) + '">' +
                pad(t.getUTCMonth() + 1) + '/' + pad(t.getUTCDate()) + '<small>' + (i === todayIdx ? 'TODAY' : WEEK[wd]) + '</small></th>');
        }
        h.push('</tr><tr class="tb-head-avail"><th class="tb-corner" title="每日預估可用率 = 可用時數 / (機台數 × 24h)">可用率 %</th>');
        for (i = 0; i < b.days; i++) {
            h.push('<th class="tb-av' + (i === todayIdx ? ' tb-today' : '') + '" data-day="' + i + '"></th>');
        }
        h.push('</tr></thead><tbody>');
        if (!b.tools.length) {
            h.push('<tr><td colspan="' + (b.days + 1) + '" class="dim tb-empty">&gt; 這個機型還沒有機台，請在 config/tool_board/' + esc(b.model) + '.ini 的 [tools] 加入</td></tr>');
        }
        $.each(b.tools, function (k, tool) {
            h.push('<tr data-tool="' + esc(tool) + '"><th class="tb-tool">' + esc(tool) + '</th>' +
                '<td colspan="' + b.days + '" class="tb-lane-td"><div class="tb-lane" data-tool="' + esc(tool) + '" style="width:' +
                (b.days * cw) + 'px;background-size:' + cw + 'px 100%"></div></td></tr>');
        });
        h.push('</tbody></table>');
        $wrap.html(h.join(''));

        state.lanes = {};
        $wrap.find('.tb-lane').each(function () {
            state.lanes[$(this).attr('data-tool')] = $(this);
        });
        state.laneBg = laneBackground(todayIdx);
        $.each(b.tools, function (k, tool) { renderLane(tool); });
        renderAvailability(b.availability);
        applySearch();
        $wrap.scrollLeft(scrollL).scrollTop(scrollT);
    }

    /** 每列共用的底層：今天、週末、已過去的日子 */
    function laneBackground(todayIdx) {
        var cw = state.cellW, h = [], i, wd;
        if (todayIdx > 0) {
            h.push('<div class="tb-bg tb-bg-past" style="left:0;width:' + (todayIdx * cw) + 'px"></div>');
        }
        for (i = 0; i < state.b.days; i++) {
            wd = d(state.winStart + i * DAY).getUTCDay();
            if (wd === 0 || wd === 6) {
                h.push('<div class="tb-bg tb-bg-wkend" style="left:' + (i * cw) + 'px;width:' + cw + 'px"></div>');
            }
        }
        if (todayIdx >= 0 && todayIdx < state.b.days) {
            h.push('<div class="tb-bg tb-bg-today" style="left:' + (todayIdx * cw) + 'px;width:' + cw + 'px"></div>');
        }
        var now = serverNow();
        if (now >= state.winStart && now < state.winEnd) {
            h.push('<div class="tb-now" style="left:' + x(now) + 'px" title="現在"></div>');
        }
        return h.join('');
    }

    function x(t) { return (t - state.winStart) / DAY * state.cellW; }

    function renderLane(tool) {
        var $lane = state.lanes[tool];
        if (!$lane) {
            return;
        }
        var h = [state.laneBg, pmHtml(tool)];
        var list = [];
        $.each(state.actions, function (id, a) {
            if (a.tool_id === tool && inWindow(a)) {
                list.push(a);
            }
        });
        list.sort(function (p, q) { return ms(p.start_at) - ms(q.start_at); });
        $.each(list, function (i, a) { h.push(actionHtml(a)); });
        $lane.html(h.join(''));
    }

    function actionHtml(a) {
        var s = ms(a.start_at), e = ms(a.end_at);
        var mon = a.need_monitor ? (a.monitor_hours || 0) * HOUR : 0;
        var pil = a.need_pilot ? (a.pilot_hours || 0) * HOUR : 0;
        var cw = state.cellW;
        var left = x(s), mainW = Math.max(3, (e - s) / DAY * cw), monW = mon / DAY * cw, pilW = pil / DAY * cw;
        var cat = catOf(a.category), wip = wipOf(a.wip_mode);
        var color = cat ? cat.color : '#777777';
        var text = wip ? wip.text_color : '#BBBBBB';
        var eds = state.editors[a.id];
        var cls = 'tb-act' + (eds ? ' tb-locked' : '') + (canEdit() ? ' is-editable' : '') + (wip ? '' : ' tb-wip-gone');
        var badge = '';
        if (a.need_monitor || a.need_pilot) {
            var parts = [], shortParts = [];
            if (a.need_monitor) { parts.push('Mon: ' + hours(a.monitor_hours)); shortParts.push('M' + a.monitor_hours); }
            if (a.need_pilot) { parts.push('Pilot: ' + hours(a.pilot_hours)); shortParts.push('P' + a.pilot_hours); }
            badge = '<span class="tb-badge">' + esc(mainW + monW + pilW > 150 ? parts.join(' | ') : shortParts.join(' ')) + '</span>';
        }
        var catText = cat ? cat.label : a.category;
        var label = catText + (a.pm_type && a.pm_type !== catText ? ' ' + a.pm_type : '');
        var h = '<div class="' + cls + '" data-id="' + a.id + '" title="' + esc(tooltip(a)) + '" style="left:' + left +
            'px;width:' + (mainW + monW + pilW) + 'px">' +
            '<div class="tb-act-main" style="width:' + mainW + 'px;background-color:' + color + ';color:' + text +
            (wip && wip.bold ? ';font-weight:bold' : '') + '">' +
            '<span class="tb-act-label">' + esc(label) + '</span>' + badge +
            (canEdit() && mainW >= 24 ? '<i class="tb-h tb-h-l"></i><i class="tb-h tb-h-r"></i>' : '') + '</div>';
        if (monW > 0) {
            h += '<div class="tb-tail tb-tail-mon" style="left:' + mainW + 'px;width:' + monW + 'px;background-color:' + color + '" title="Monitor ' + hours(a.monitor_hours) + '"></div>';
        }
        if (pilW > 0) {
            h += '<div class="tb-tail tb-tail-pil" style="left:' + (mainW + monW) + 'px;width:' + pilW + 'px;background-color:' + color + '" title="Pilot ' + hours(a.pilot_hours) + '"></div>';
        }
        if (eds) {
            h += '<span class="tb-lock-tag">🔒 ' + esc($.map(eds, function (x2) { return x2.name + (x2.mode === 'drag' ? ' 拖曳中' : ' 編輯中'); }).join('、')) + '</span>';
        }
        return h + '</div>';
    }

    function tooltip(a) {
        var e = ms(a.end_at), lines = [
            a.tool_id + '  ' + catLabel(a.category) + (a.pm_type ? '（' + a.pm_type + '）' : ''),
            'WIP：' + wipLabel(a.wip_mode),
            '時間：' + short(ms(a.start_at)) + ' ～ ' + short(e)
        ];
        if (a.need_monitor) {
            lines.push('Monitor ' + hours(a.monitor_hours) + '：至 ' + short(e + a.monitor_hours * HOUR));
        }
        if (a.need_pilot) {
            lines.push('Pilot ' + hours(a.pilot_hours) + '：至 ' + short(ms(a.occupied_end)));
        }
        if (a.remark) {
            lines.push('備註：' + a.remark);
        }
        lines.push('建立：' + a.created_by_name + '　最後修改：' + a.updated_by_name + ' ' + String(a.updated_at).substring(5, 16));
        return lines.join('\n');
    }

    // ---------------- PM 射程 ----------------
    function pmStatus(tool, p) {
        var s = ms(p.start), due = ms(p.due), first = ms(p.first), cat = state.b.pmCategory, done = false;
        $.each(state.actions, function (id, a) {
            if (a.tool_id === tool && a.category === cat && ms(a.start_at) < due && ms(a.occupied_end) > s) {
                done = true;
                return false;
            }
        });
        var now = serverNow();
        if (done) { return 'done'; }
        if (now > due) { return 'over'; }
        if (now > first) { return 'due'; }
        return 'wait';
    }

    function pmHtml(tool) {
        var pm = state.b.pm, list = (pm && pm.items && pm.items[tool]) || [], shown = [], h = [];
        $.each(list, function (i, p) {
            if (ms(p.start) < state.winEnd && ms(p.due) >= state.winStart) {
                shown.push(p);
            }
        });
        var n = shown.length;
        $.each(shown, function (i, p) {
            var s = ms(p.start), f = ms(p.first), due = ms(p.due), st = pmStatus(tool, p);
            var top = n > 1 ? (i * 100 / n) : 0, height = n > 1 ? (100 / n) : 100;
            var stText = { done: '已排 PM', over: '已超過最後到期日，尚未排 PM', due: '已到點，尚未排 PM', wait: '射程內' }[st];
            var title = p.type + '：射程 ' + short(s) + ' ～ ' + short(due) + '，到點 ' + short(f) + '（' + stText + '）';
            h.push('<div class="tb-pm tb-pm-' + st + '" data-pm="' + i + '" data-type="' + esc(p.type) + '" data-first="' + esc(p.first) +
                '" title="' + esc(title) + '" style="left:' + x(s) + 'px;width:' + Math.max(2, x(due) - x(s)) + 'px;top:' + top +
                '%;height:' + height + '%"><span class="tb-pm-label">' + esc(p.type) + '</span></div>');
            h.push('<div class="tb-pm-first" style="left:' + x(f) + 'px;top:' + top + '%;height:' + height + '%" title="' + esc(p.type + ' 到點 ' + short(f)) + '"><b>▼</b></div>');
            h.push('<div class="tb-pm-due" style="left:' + x(due) + 'px;top:' + top + '%;height:' + height + '%" title="' + esc(p.type + ' 最後到期 ' + short(due)) + '"><b>!</b></div>');
        });
        return h.join('');
    }

    function renderPmMsg() {
        var pm = state.b.pm, $m = $('#tb-pm-msg'), parts = [];
        if (!pm || pm.status === 'off') {
            $m.hide();
            return;
        }
        if (pm.status === 'missing' || pm.status === 'error') {
            parts.push('PM 資料：' + esc(pm.message));
        } else {
            if (pm.loose) {
                parts.push(esc(pm.message));
            }
            if (pm.skipped) {
                var more = '';
                if (pm.reasons && pm.reasons.length) {
                    more = '<details><summary>略過原因（ADMIN）</summary><ul>' +
                        $.map(pm.reasons, function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></details>';
                }
                parts.push('PM 資料：本機型 ' + pm.count + ' 筆；整個檔案略過 ' + pm.skipped + ' 筆格式錯誤的項目' + more);
            }
        }
        if (parts.length) {
            $m.html('&gt; ' + parts.join('<br>&gt; ')).show();
        } else {
            $m.hide();
        }
    }

    // ---------------- 可用率 ----------------
    function renderAvailability(av) {
        if (!av) {
            return;
        }
        state.b.availability = av;
        $wrap.find('.tb-av').each(function () {
            var i = +$(this).attr('data-day'), day = ymd(state.winStart + i * DAY);
            var v = av.values[day], hrs = av.hours ? av.hours[day] : null;
            var cls = 'tb-av' + ($(this).hasClass('tb-today') ? ' tb-today' : '');
            if (v !== null && v !== undefined) {
                if (v < av.alert) { cls += ' tb-av-alert'; } else if (v < av.warn) { cls += ' tb-av-warn'; }
            }
            $(this).attr('class', cls).text(v === null || v === undefined ? '—' : v.toFixed(1))
                .attr('title', day + (hrs !== null && hrs !== undefined ? '：可用 ' + hrs + ' h / ' + (av.tools * 24) + ' h（' + av.tools + ' 台）' : ''));
        });
        if (av.error) {
            toast(av.error, 'error');
        }
    }

    // ---------------- 圖例 / 搜尋 ----------------
    function renderLegend() {
        var b = state.b, h = ['<div class="tb-legend-row"><b>類別</b>'];
        $.each(b.categories, function (i, c) {
            h.push('<span class="tb-sw" style="background:' + esc(c.color) + '"></span>' + esc(c.label) + (c.counts_down ? '' : '（不算停機）'));
        });
        h.push('</div><div class="tb-legend-row"><b>WIP 字色</b>');
        $.each(b.wip, function (i, w) {
            h.push('<span class="tb-wip-sample" style="color:' + esc(w.text_color) + (w.bold ? ';font-weight:bold' : '') + '">' + esc(w.label) + '</span>');
        });
        h.push('</div><div class="tb-legend-row"><b>尾巴</b><span class="tb-sw tb-sw-mon"></span>Monitor<span class="tb-sw tb-sw-pil"></span>Pilot　' +
            '<b>PM</b><span class="tb-sw tb-pm-wait"></span>射程<span class="tb-sw tb-pm-done"></span>已排' +
            '<span class="tb-sw tb-pm-due"></span>已到點未排<span class="tb-sw tb-pm-over"></span>已過期未排　<b class="tb-lg-first">▼</b>到點日<b class="tb-lg-due">!</b>最後到期日</div>');
        $('#tb-legend').html(h.join(''));
    }

    function applySearch() {
        var q = $.trim($('#tb-search').val()).toUpperCase();
        $wrap.find('tbody tr[data-tool]').each(function () {
            $(this).toggle(!q || $(this).attr('data-tool').indexOf(q) >= 0);
        });
    }

    // ==================================================================
    // 右鍵選單
    // ==================================================================
    function closeMenu() { $menu.hide().empty(); }

    function openMenu(e, items) {
        var h = [];
        $.each(items, function (i, it) {
            if (it.sep) {
                h.push('<li class="tb-menu-sep"></li>');
            } else if (it.info) {
                h.push('<li class="tb-menu-info">' + esc(it.info) + '</li>');
            } else {
                h.push('<li class="tb-menu-item' + (it.danger ? ' is-danger' : '') + '" data-i="' + i + '">' + esc(it.label) + '</li>');
            }
        });
        $menu.html(h.join('')).css({ left: 0, top: 0 }).show();
        var w = $menu.outerWidth(), ht = $menu.outerHeight();
        // position: fixed（全螢幕時也正確）
        var left = Math.max(4, Math.min(e.clientX, $(window).width() - w - 8));
        var top = Math.max(4, Math.min(e.clientY, $(window).height() - ht - 8));
        $menu.css({ left: left, top: top });
        $menu.off('click').on('click', '.tb-menu-item', function () {
            var it = items[+$(this).attr('data-i')];
            closeMenu();
            it.run();
        });
    }

    $wrap.on('contextmenu', '.tb-lane', function (e) {
        e.preventDefault();
        if (state.drag) {
            return;
        }
        var $lane = $(this), tool = $lane.attr('data-tool');
        var dayIdx = Math.floor((e.pageX - $lane.offset().left) / state.cellW);
        var day = state.winStart + dayIdx * DAY;
        var $act = $(e.target).closest('.tb-act'), $pm = $(e.target).closest('.tb-pm');
        var items = [];
        if ($act.length) {
            var a = state.actions[+$act.attr('data-id')];
            if (!a) {
                return;
            }
            items.push({ info: a.tool_id + '  ' + catLabel(a.category) + '  ' + short(ms(a.start_at)) });
            items.push({ label: canEdit() ? '編輯' : '檢視', run: function () { openForm('edit', a); } });
            if (canEdit()) {
                items.push({ label: '複製', run: function () { openForm('copy', a); } });
                items.push({ sep: true });
                items.push({ label: '刪除', danger: true, run: function () { removeAction(a); } });
            }
        } else {
            items.push({ info: tool + '  ' + ymd(day) });
            if (canEdit()) {
                items.push({ label: '新增 Action', run: function () { openForm('new', { tool_id: tool, day: day }); } });
                if ($pm.length) {
                    var type = $pm.attr('data-type'), first = ms($pm.attr('data-first'));
                    items.push({ label: '依此 PM（' + type + '）新增 Action', run: function () {
                        openForm('new', { tool_id: tool, day: first, exact: true, category: state.b.pmCategory, pm_type: type });
                    } });
                }
            } else {
                items.push({ info: '唯讀：你沒有編輯 ' + state.b.label + ' 的權限' });
            }
        }
        openMenu(e, items);
    });
    $(document).on('mousedown', function (e) {
        if (!$(e.target).closest('#tb-menu').length) {
            closeMenu();
        }
    }).on('keydown', function (e) {
        if (e.keyCode === 27) {
            closeMenu();
        }
    });
    $wrap.on('scroll', closeMenu);

    $wrap.on('dblclick', '.tb-act', function () {
        var a = state.actions[+$(this).attr('data-id')];
        if (a) {
            openForm('edit', a);
        }
    });

    // ==================================================================
    // 表單
    // ==================================================================
    function f(name) { return $form.find('[name="' + name + '"]'); }

    function formStart() { return ms(f('start_date').val() + ' ' + (f('start_time').val() || '00:00')); }
    function formEnd() { return ms(f('end_date').val() + ' ' + (f('end_time').val() || '00:00')); }
    function setStart(t) { f('start_date').val(ymd(t)); f('start_time').val(hm(t)); }
    function setEnd(t) { f('end_date').val(ymd(t)); f('end_time').val(hm(t)); }

    function fillCategories(selected) {
        var h = [];
        $.each(state.b.categories, function (i, c) {
            h.push('<option value="' + esc(c.code) + '"' + (c.code === selected ? ' selected' : '') + '>' + esc(c.label) + '</option>');
        });
        if (selected && !catOf(selected)) {
            h.push('<option value="" selected>（原類別 ' + esc(selected) + ' 已從設定移除，請改選）</option>');
        }
        f('category').html(h.join(''));
    }

    function fillWip(selected) {
        var h = [];
        $.each(state.b.wip, function (i, w) {
            h.push('<label class="pi-check tb-wip-opt"><input type="radio" name="wip_mode" value="' + esc(w.code) + '"' +
                (w.code === selected ? ' checked' : '') + '> <span style="color:' + esc(w.text_color) + (w.bold ? ';font-weight:bold' : '') +
                '" class="tb-wip-chip">' + esc(w.label) + '</span></label>');
        });
        if (selected && !wipOf(selected)) {
            h.push('<p class="text-red tb-wip-gone-msg">&gt; 原本的 WIP「' + esc(selected) + '」已從 ' + esc(state.b.label) + ' 的設定移除，請改選</p>');
        }
        $('#tb-f-wip').html(h.join(''));
    }

    function defaultWip(catCode) {
        var c = catOf(catCode);
        if (c && c.default_wip && wipOf(c.default_wip)) {
            return c.default_wip;
        }
        return state.b.wip.length ? state.b.wip[0].code : '';
    }

    /**
     * mode: new（data = { tool_id, day, exact?, category?, pm_type? }）/ edit（data = action）/ copy（data = action）
     */
    function openForm(mode, data) {
        closeMenu();
        var ro = !canEdit();
        var a = mode === 'new' ? null : data;
        state.modal = { mode: mode, id: mode === 'edit' ? a.id : 0, wipTouched: mode !== 'new', original: null };
        $('#tb-error, #tb-stale, #tb-editors').hide();
        $('#tb-history').hide();
        $('#tb-history-list').empty();
        $form[0].reset();
        f('id').val(mode === 'edit' ? a.id : 0);
        f('version').val(mode === 'edit' ? a.version : 0);

        if (a) {
            f('tool_id').val(a.tool_id);
            fillCategories(a.category);
            fillWip(a.wip_mode);
            setStart(ms(a.start_at));
            setEnd(ms(a.end_at));
            f('need_monitor').prop('checked', a.need_monitor);
            f('monitor_hours').val(a.need_monitor ? a.monitor_hours : '');
            f('need_pilot').prop('checked', a.need_pilot);
            f('pilot_hours').val(a.need_pilot ? a.pilot_hours : '');
            f('remark').val(a.remark);
            f('pm_type').val(a.pm_type || '');
        } else {
            var cat = data.category && catOf(data.category) ? data.category : (state.b.categories[0] ? state.b.categories[0].code : '');
            var start = data.exact ? data.day : ms(ymd(data.day) + ' ' + state.b.startTime);
            if (start < state.winStart) {
                start = state.winStart;
            }
            f('tool_id').val(data.tool_id);
            fillCategories(cat);
            fillWip(defaultWip(cat));
            setStart(start);
            setEnd(start + (catOf(cat) ? catOf(cat).default_hours : 8) * HOUR);
            f('pm_type').val(data.pm_type || '');
        }

        var title = { 'new': '&gt; NEW ACTION', edit: ro ? '&gt; VIEW ACTION #' + a.id : '&gt; EDIT ACTION #' + (a ? a.id : ''), copy: '&gt; COPY ACTION' }[mode];
        $('#tb-modal-title').html(title + ' <small>' + esc(state.b.label) + '</small>');
        $form.find('input, select, textarea').not('[name=tool_id]').prop('disabled', ro);
        $('#tb-save').toggle(!ro);
        $('#tb-delete').toggle(!ro && mode === 'edit');
        $('#tb-meta').text(mode === 'edit' ? '建立：' + a.created_by_name + ' ' + String(a.created_at).substring(0, 16) +
            '　最後修改：' + a.updated_by_name + ' ' + String(a.updated_at).substring(0, 16) : '');
        syncHours();
        spanFromRange();
        updateInfo();
        renderEditorsNote();
        state.modal.lastStart = formStart();
        state.modal.original = $form.serialize();
        $('body').addClass('pi-modal-open');
        $modal.show();
        if (mode === 'edit') {
            state.editing = ro ? null : { id: a.id, mode: 'edit' };
            loadHistory(a.id);
            if (!ro) {
                poll(false);
            }
        }
        if (!ro) {
            setTimeout(function () { f('category').focus(); }, 30);
        }
    }

    function closeForm(force) {
        if (!force && canEdit() && state.modal && $form.serialize() !== state.modal.original &&
            !window.confirm('有尚未儲存的修改，確定要關閉嗎？')) {
            return;
        }
        $modal.hide();
        $('body').removeClass('pi-modal-open');
        state.modal = null;
        if (state.editing && state.editing.mode === 'edit') {
            state.editing = null;
            poll(false);
        }
    }

    function syncHours() {
        var ro = !canEdit();
        $.each([['need_monitor', 'monitor_hours', '#tb-mon-box'], ['need_pilot', 'pilot_hours', '#tb-pilot-box']], function (i, p) {
            var on = f(p[0]).prop('checked');
            f(p[1]).prop('disabled', ro || !on);
            $(p[2]).toggleClass('is-off', !on).find('.tb-quick').prop('disabled', ro);
        });
    }

    /** 時長、回到生產時間 */
    function updateInfo() {
        var s = formStart(), e = formEnd();
        if (isNaN(s) || isNaN(e)) {
            $('#tb-duration, #tb-return').text('');
            return;
        }
        $('#tb-duration').text(e > s ? '= ' + duration(e - s) : '');
        var mon = f('need_monitor').prop('checked') ? parseFloat(f('monitor_hours').val()) || 0 : 0;
        var pil = f('need_pilot').prop('checked') ? parseFloat(f('pilot_hours').val()) || 0 : 0;
        var parts = [];
        if (e > s) {
            if (mon) { parts.push('Monitor 結束（機台回到生產）：' + short(e + mon * HOUR)); }
            if (pil) { parts.push('Pilot 結束：' + short(e + (mon + pil) * HOUR)); }
            if (!mon && !pil) { parts.push('機台回到生產：' + short(e)); }
        }
        $('#tb-return').text(parts.join('　'));
    }

    function renderEditorsNote() {
        if (!state.modal || !state.modal.id) {
            $('#tb-editors').hide();
            return;
        }
        var eds = state.editors[state.modal.id];
        if (eds && eds.length) {
            $('#tb-editors').text('> ' + $.map(eds, function (x2) { return x2.name; }).join('、') + ' 也正在編輯這筆，存檔時若對方已先存，會提示衝突').show();
        } else {
            $('#tb-editors').hide();
        }
    }

    function loadHistory(id) {
        $.getJSON(CFG.urls.history, { id: id }).done(function (res) {
            if (!state.modal || state.modal.id !== id || !res.logs.length) {
                return;
            }
            var ops = { create: '新增', update: '修改', move: '拖曳', 'delete': '刪除' };
            $('#tb-history-list').html($.map(res.logs, function (l) {
                var dt = l.detail || {}, a2 = dt.after, b2 = dt.before, desc = '';
                if (a2 && (l.op === 'move' || (b2 && (a2.start_at !== b2.start_at || a2.end_at !== b2.end_at)))) {
                    desc = (b2 ? short(ms(b2.start_at)) + '～' + short(ms(b2.end_at)) + ' → ' : '') + short(ms(a2.start_at)) + '～' + short(ms(a2.end_at));
                } else if (a2) {
                    desc = short(ms(a2.start_at)) + '～' + short(ms(a2.end_at));
                }
                return '<li>' + esc(String(l.at).substring(5, 16)) + '　' + esc(l.by) + '　' + esc(ops[l.op] || l.op) +
                    (dt.overwrite ? '（覆蓋）' : '') + (desc ? '　<span class="dim">' + esc(desc) + '</span>' : '') + '</li>';
            }).join(''));
            $('#tb-history').show();
        });
    }

    // ---------------- 開始 / 時長 / 結束 連動 ----------------
    // 改時長 → 結束 = 開始 + 時長；改結束 → 重算時長；改開始 → 結束跟著平移、時長不變
    var SPAN_MAX = 2160;
    function fmtHours(h) { return String(Math.round(h * 100) / 100); }
    function spanFromRange() {
        var s = formStart(), e = formEnd();
        $('#tb-span-err').hide();
        f('span_hours').val(!isNaN(s) && !isNaN(e) && e > s ? fmtHours((e - s) / HOUR) : '');
    }
    function endFromSpan() {
        var raw = $.trim(f('span_hours').val()), h = parseFloat(raw), s = formStart();
        if (raw === '' || isNaN(h) || h <= 0 || h > SPAN_MAX) {
            $('#tb-span-err').text('> 時長需為大於 0、最多 ' + SPAN_MAX + ' 的小時數（可有小數，例 1.5）').show();
            return;
        }
        $('#tb-span-err').hide();
        if (!isNaN(s)) {
            setEnd(s + Math.round(h * HOUR / 60000) * 60000);
        }
    }

    $form.on('change input', '[name=start_date], [name=start_time]', function () {
        var m = state.modal;
        if (!m) {
            return;
        }
        var s = formStart();
        if (!isNaN(s) && m.lastStart !== undefined && !isNaN(m.lastStart)) {
            var e = formEnd();
            if (!isNaN(e)) {
                setEnd(e + (s - m.lastStart));
            }
        }
        m.lastStart = s;
        updateInfo();
    }).on('focus', '[name=start_date], [name=start_time]', function () {
        if (state.modal) {
            state.modal.lastStart = formStart();
        }
    }).on('change input', '[name=end_date], [name=end_time]', function () {
        spanFromRange();
        updateInfo();
    }).on('input change', '[name=span_hours]', function () {
        endFromSpan();
        updateInfo();
    }).on('change', '[name=category]', function () {
        var m = state.modal, c = catOf(this.value);
        if (!m || !c) {
            return;
        }
        if (m.mode === 'new') {
            f('span_hours').val(fmtHours(c.default_hours));
            endFromSpan();
        }
        if (!m.wipTouched) {
            fillWip(defaultWip(c.code));
        }
        updateInfo();
    }).on('change', '[name=wip_mode]', function () {
        if (state.modal) {
            state.modal.wipTouched = true;
        }
    }).on('change', '[name=need_monitor], [name=need_pilot]', function () {
        syncHours();
        var box = this.name === 'need_monitor' ? 'monitor_hours' : 'pilot_hours';
        if (this.checked && !f(box).val()) {
            f(box).focus();
        }
        updateInfo();
    }).on('input change', '[name=monitor_hours], [name=pilot_hours]', updateInfo);

    $form.on('click', '.tb-span-quick', function () {
        f('span_hours').val($(this).attr('data-h'));
        endFromSpan();
        updateInfo();
    });
    $form.on('click', '.tb-quick', function () {
        var $t = $('#' + $(this).attr('data-target'));
        $t.val($(this).attr('data-h'));
        f($t.attr('name') === 'monitor_hours' ? 'need_monitor' : 'need_pilot').prop('checked', true);
        syncHours();
        updateInfo();
    });

    function formPayload() {
        var s = formStart(), e = formEnd();
        return {
            id: f('id').val(),
            version: f('version').val(),
            model: state.model,
            tool_id: f('tool_id').val(),
            category: f('category').val(),
            wip_mode: $form.find('[name=wip_mode]:checked').val() || '',
            start_at: isNaN(s) ? '' : full(s),
            end_at: isNaN(e) ? '' : full(e),
            need_monitor: f('need_monitor').prop('checked') ? '1' : '0',
            monitor_hours: f('monitor_hours').val(),
            need_pilot: f('need_pilot').prop('checked') ? '1' : '0',
            pilot_hours: f('pilot_hours').val(),
            remark: f('remark').val(),
            pm_type: f('pm_type').val()
        };
    }

    function showFormError(msg) {
        $('#tb-error').text('> ' + msg).show();
        $modal.scrollTop(0);
    }

    function save(payload) {
        if ($('#tb-span-err').is(':visible')) {
            return showFormError('時長（小時）格式不正確');
        }
        if (!payload.wip_mode) {
            return showFormError('請勾選 WIP 處理方式');
        }
        if (!payload.start_at || !payload.end_at) {
            return showFormError('請填寫開始與結束時間');
        }
        if (ms(payload.end_at) <= ms(payload.start_at)) {
            return showFormError('結束時間必須晚於開始時間');
        }
        var $btn = $('#tb-save').prop('disabled', true);
        $.post(CFG.urls.save, payload).done(function (res) {
            upsert(res.action);
            closeForm(true);
            toast(payload.id > 0 ? '已儲存' : '已新增 ' + res.action.tool_id + ' ' + catLabel(res.action.category), 'ok');
            poll(false, true);
        }).fail(function (xhr) {
            var r = xhr.responseJSON || {};
            if (xhr.status === 409) {
                conflict(payload, r, 'form');
            } else if (xhr.status !== 401) {
                showFormError(errorText(xhr, '儲存失敗'));
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    }

    $form.on('submit', function () { save(formPayload()); });
    $('#tb-cancel, #tb-cancel-x').on('click', function () { closeForm(false); });
    $modal.on('mousedown', function (e) {
        if (e.target === this) {
            closeForm(false);
        }
    });
    $(document).on('keydown', function (e) {
        if (e.keyCode === 27 && $modal.is(':visible') && !$conflict.is(':visible')) {
            closeForm(false);
        }
    });
    $('#tb-delete').on('click', function () {
        var a = state.actions[+f('id').val()];
        if (a) {
            removeAction(a, true);
        }
    });
    $('#tb-stale-load').on('click', function () {
        var a = state.actions[state.modal ? state.modal.id : 0];
        if (a) {
            openForm('edit', a);
        }
    });

    function removeAction(a, fromForm) {
        if (!window.confirm('確定刪除 ' + a.tool_id + ' 的「' + catLabel(a.category) + '」（' + short(ms(a.start_at)) + ' ～ ' + short(ms(a.end_at)) + '）？')) {
            return;
        }
        $.post(CFG.urls['delete'], { id: a.id, version: a.version }).done(function () {
            drop(a.id);
            if (fromForm) {
                closeForm(true);
            }
            toast('已刪除', 'ok');
            poll(false, true);
        }).fail(function (xhr) {
            var r = xhr.responseJSON || {};
            if (xhr.status === 409 && r.action && !r.action.deleted) {
                upsert(r.action);
                toast(r.error + '，請確認後再刪除', 'error');
                if (fromForm) {
                    openForm('edit', r.action);
                }
            } else if (xhr.status === 409) {
                drop(a.id);
                if (fromForm) {
                    closeForm(true);
                }
                toast(errorText(xhr), 'error');
            } else if (xhr.status !== 401) {
                toast(errorText(xhr, '刪除失敗'), 'error');
            }
        });
    }

    // ==================================================================
    // 衝突（409）
    // ==================================================================
    /**
     * mine: 我要送出的內容（save 的 payload 或 move 的 {start_at, end_at}）
     * kind: form / drag
     */
    function conflict(mine, r, kind) {
        var latest = r.action;
        if (!latest || latest.deleted || r.reason === 'gone') {
            if (latest) {
                drop(latest.id);
            } else if (mine.id) {
                drop(+mine.id);
            }
            if (kind === 'form') {
                closeForm(true);
            }
            toast(r.error || '這筆已被刪除', 'error');
            return;
        }
        upsertSilently(latest);
        var full2 = kind === 'drag' ? $.extend({}, latest, { start_at: mine.start_at, end_at: mine.end_at }) : mine;
        var rows = [
            ['類別', catLabel(full2.category), catLabel(latest.category)],
            ['WIP', wipLabel(full2.wip_mode), wipLabel(latest.wip_mode)],
            ['開始', String(full2.start_at).substring(0, 16), String(latest.start_at).substring(0, 16)],
            ['結束', String(full2.end_at).substring(0, 16), String(latest.end_at).substring(0, 16)],
            ['Monitor', tailText(full2.need_monitor, full2.monitor_hours), tailText(latest.need_monitor, latest.monitor_hours)],
            ['Pilot', tailText(full2.need_pilot, full2.pilot_hours), tailText(latest.need_pilot, latest.pilot_hours)],
            ['備註', full2.remark || '', latest.remark || '']
        ];
        $('#tb-conflict-msg').text('> ' + r.error + '。要用你的版本覆蓋，還是使用最新版本？');
        $('#tb-conflict-rows').html($.map(rows, function (row) {
            return '<tr class="' + (row[1] !== row[2] ? 'pi-diff-hot' : '') + '"><td>' + esc(row[0]) + '</td><td>' + esc(row[1]) + '</td><td>' + esc(row[2]) + '</td></tr>';
        }).join(''));
        $conflict.show();
        $('#tb-conflict-overwrite').off('click').on('click', function () {
            $conflict.hide();
            if (kind === 'form') {
                f('version').val(latest.version);
                save($.extend({}, mine, { version: latest.version, overwrite: '1' }));
            } else {
                sendMove(latest, mine.start_at, mine.end_at, true);
            }
        });
        $('#tb-conflict-load').off('click').on('click', function () {
            $conflict.hide();
            upsert(latest);
            if (kind === 'form') {
                openForm('edit', latest);
            }
        });
        $('#tb-conflict-cancel').off('click').on('click', function () {
            $conflict.hide();
            if (kind === 'drag') {
                upsert(latest);
            } else {
                f('version').val(latest.version);   // 繼續編輯，下次存檔以最新版本為基準
            }
        });
    }
    function tailText(on, h) { return on ? hours(h) : '—'; }

    // ==================================================================
    // 資料更新
    // ==================================================================
    function upsertSilently(a) {
        if (inWindow(a)) {
            state.actions[a.id] = a;
        } else {
            delete state.actions[a.id];
        }
    }
    function upsert(a) {
        var old = state.actions[a.id];
        upsertSilently(a);
        renderLane(a.tool_id);
        if (old && old.tool_id !== a.tool_id) {
            renderLane(old.tool_id);
        }
    }
    function drop(id) {
        var old = state.actions[id];
        delete state.actions[id];
        if (old) {
            renderLane(old.tool_id);
        }
    }

    // ==================================================================
    // 拖曳：中間 = 搬移；左右邊 = 改開始 / 結束；預設以天對齊，按住 Shift 以小時對齊
    // ==================================================================
    $wrap.on('mousedown', '.tb-act.is-editable', function (e) {
        if (e.which !== 1 || !canEdit()) {
            return;
        }
        var $act = $(this), a = state.actions[+$act.attr('data-id')];
        if (!a) {
            return;
        }
        e.preventDefault();
        closeMenu();
        var $lane = state.lanes[a.tool_id];
        // 色塊夠寬才能拉邊（左右各 7px），太窄的色塊一律整段搬移
        var $main = $act.children('.tb-act-main'), mw = $main.outerWidth(), ox = e.pageX - $main.offset().left, mode = 'm';
        if (mw >= 24 && ox <= 7) {
            mode = 'l';
        } else if (mw >= 24 && ox >= mw - 7 && ox <= mw) {
            mode = 'r';
        }
        state.drag = {
            a: a,
            mode: mode,
            $act: $act,
            $lane: $lane,
            x0: e.pageX - $lane.offset().left,
            s0: ms(a.start_at),
            e0: ms(a.end_at),
            tail: tailMs(a),
            started: false,
            s: ms(a.start_at),
            e: ms(a.end_at),
            bad: false
        };
    });

    $(document).on('mousemove', function (e) {
        var g = state.drag;
        if (!g) {
            return;
        }
        var lx = e.pageX - g.$lane.offset().left, dx = lx - g.x0;
        if (!g.started) {
            if (Math.abs(dx) < 4) {
                return;
            }
            g.started = true;
            g.$act.addClass('tb-dragging');
            $('body').addClass('tb-is-dragging');
            state.editing = { id: g.a.id, mode: 'drag' };
            poll(false);
        }
        var unit = e.shiftKey ? HOUR : DAY;
        var delta = Math.round(dx / state.cellW * DAY / unit) * unit;
        var s = g.s0, en = g.e0;
        if (g.mode === 'm') {
            s = g.s0 + delta;
            en = g.e0 + delta;
            if (s < state.winStart && s !== g.s0) {
                en += state.winStart - s;
                s = state.winStart;
            }
        } else if (g.mode === 'l') {
            s = Math.min(g.s0 + delta, g.e0 - HOUR);
            if (s < state.winStart && s !== g.s0) {
                s = Math.min(state.winStart, g.e0 - HOUR);
            }
        } else {
            en = Math.max(g.e0 + delta, g.s0 + HOUR);
        }
        if (s >= state.winEnd) {
            var shift = s - (state.winEnd - unit);
            s -= shift;
            if (g.mode === 'm') {
                en -= shift;
            }
        }
        g.s = s;
        g.e = en;
        g.bad = overlaps(g.a, s, en + g.tail);
        // 更新畫面
        var cw = state.cellW, mainW = Math.max(3, (en - s) / DAY * cw);
        var monW = g.a.need_monitor ? g.a.monitor_hours * HOUR / DAY * cw : 0;
        var pilW = g.a.need_pilot ? g.a.pilot_hours * HOUR / DAY * cw : 0;
        g.$act.css({ left: x(s), width: mainW + monW + pilW }).toggleClass('tb-bad', g.bad);
        g.$act.children('.tb-act-main').css('width', mainW);
        g.$act.children('.tb-tail-mon').css('left', mainW);
        g.$act.children('.tb-tail-pil').css('left', mainW + monW);
        $tip.text(short(s) + ' → ' + short(en) + (g.tail ? '（尾巴至 ' + short(en + g.tail) + '）' : '') +
            (g.bad ? '　✕ 與其他 Action 重疊' : '') + (e.shiftKey ? '　[小時]' : '　[天]，按住 Shift 以小時對齊'))
            .toggleClass('is-bad', g.bad).css({ left: e.clientX + 14, top: e.clientY + 16 }).show();
        // 拖到邊緣自動捲動
        var wl = $wrap.offset().left, wr = wl + $wrap.innerWidth();
        if (e.pageX > wr - 40) {
            $wrap.scrollLeft($wrap.scrollLeft() + 16);
        } else if (e.pageX < wl + TOOL_COL + 40) {
            $wrap.scrollLeft($wrap.scrollLeft() - 16);
        }
    }).on('mouseup', function () {
        var g = state.drag;
        if (!g) {
            return;
        }
        state.drag = null;
        $tip.hide();
        $('body').removeClass('tb-is-dragging');
        if (!g.started) {
            return;
        }
        state.editing = null;
        if (g.bad || (g.s === g.s0 && g.e === g.e0)) {
            if (g.bad) {
                toast('與同台其他 Action（含 Monitor / Pilot 尾巴）重疊，已還原', 'error');
            }
            renderLane(g.a.tool_id);
            flushQueue();
            poll(false);
            return;
        }
        sendMove(g.a, full(g.s), full(g.e), false);
        flushQueue();
    });

    function overlaps(a, s, occ) {
        var hit = false;
        $.each(state.actions, function (id, o) {
            if (o.id !== a.id && o.tool_id === a.tool_id && ms(o.start_at) < occ && ms(o.occupied_end) > s) {
                hit = true;
                return false;
            }
        });
        return hit;
    }

    function sendMove(a, startAt, endAt, overwrite) {
        var payload = { id: a.id, version: a.version, start_at: startAt, end_at: endAt };
        if (overwrite) {
            payload.overwrite = '1';
        }
        $.post(CFG.urls.move, payload).done(function (res) {
            upsert(res.action);
            toast(res.action.tool_id + ' 已改為 ' + short(ms(res.action.start_at)) + ' ～ ' + short(ms(res.action.end_at)), 'ok');
            poll(false, true);
        }).fail(function (xhr) {
            var r = xhr.responseJSON || {};
            renderLane(a.tool_id);
            if (xhr.status === 409) {
                conflict({ id: a.id, start_at: startAt, end_at: endAt }, r, 'drag');
            } else if (xhr.status !== 401) {
                if (r.action) {
                    upsert(r.action);
                }
                toast(errorText(xhr, '拖曳失敗，已還原'), 'error');
            }
        });
    }

    // ==================================================================
    // 輪詢
    // ==================================================================
    function poll(force, withAvail) {
        if (!state.b || (state.pollXhr && !force)) {
            return;
        }
        if (state.pollXhr) {
            state.pollXhr.abort();
        }
        var model = state.model;
        state.pollXhr = $.getJSON(CFG.urls.poll, {
            model: model,
            since: state.cursor,
            editing: state.editing ? state.editing.id : 0,
            mode: state.editing ? state.editing.mode : 'view',
            pm_sig: state.b.pm ? state.b.pm.sig : '',
            avail: withAvail ? 1 : 0
        }).done(function (res) {
            if (model !== state.model) {
                return;
            }
            if (state.drag && state.drag.started) {
                state.queued.push(res);
                return;
            }
            applyPoll(res);
            if (!state.paused) {
                setLive('live');
            }
        }).fail(function (xhr, st) {
            if (st !== 'abort' && xhr.status !== 401) {
                setLive('error', '同步失敗，重試中');
            }
        }).always(function () {
            state.pollXhr = null;
        });
    }

    function flushQueue() {
        var q = state.queued;
        state.queued = [];
        $.each(q, function (i, res) { applyPoll(res); });
    }

    function applyPoll(res) {
        if (res.today !== state.b.today) {
            // 跨日：時間視窗移動，整個重新載入
            load(state.model);
            return;
        }
        state.cursor = res.cursor;
        var dirty = {};
        $.each(res.changed, function (i, a) {
            var old = state.actions[a.id];
            if (old && old.version >= a.version) {
                return;
            }
            if (old) {
                dirty[old.tool_id] = true;
            }
            upsertSilently(a);
            dirty[a.tool_id] = true;
            noteStale(a, false);
        });
        $.each(res.deleted, function (i, id) {
            var old = state.actions[id];
            if (old) {
                delete state.actions[id];
                dirty[old.tool_id] = true;
                noteStale(old, true);
            }
        });
        // 編輯中提示
        var oldEds = state.editors;
        state.editors = res.editors || {};
        $.each([oldEds, state.editors], function (k, map) {
            $.each(map, function (id) {
                var a = state.actions[id];
                if (a) {
                    dirty[a.tool_id] = true;
                }
            });
        });
        if (res.canEdit !== state.b.canEdit) {
            state.b.canEdit = res.canEdit;
            renderMode();
            dirty = {};
            $.each(state.b.tools, function (i, t) { dirty[t] = true; });
        }
        if (res.pm) {
            state.b.pm = res.pm;
            renderPmMsg();
            $.each(state.b.tools, function (i, t) { dirty[t] = true; });
        }
        if (res.changed.length || res.deleted.length) {
            // PM 狀態（已排 / 未排）可能改變
            $.each(state.b.tools, function (i, t) {
                if (state.b.pm && state.b.pm.items && state.b.pm.items[t]) {
                    dirty[t] = true;
                }
            });
        }
        $.each(dirty, function (tool) { renderLane(tool); });
        if (res.availability) {
            renderAvailability(res.availability);
        }
        $('#tb-online').html(res.online.length ? '線上：<b>' + esc(res.online.join('、')) + '</b>' : '');
        renderEditorsNote();
    }

    /** 別人改了我正在編輯的那一筆：立刻提示 */
    function noteStale(a, gone) {
        if (!state.modal || state.modal.id !== a.id) {
            return;
        }
        if (!gone && a.version <= +f('version').val()) {
            return;
        }
        $('#tb-stale-text').text(gone ? '> 這筆已被刪除，存檔將會失敗' : '> 這筆已被 ' + a.updated_by_name + ' 於 ' + String(a.updated_at).substring(5, 16) + ' 更新');
        $('#tb-stale-load').toggle(!gone);
        $('#tb-stale').show();
    }

    // 閒置偵測：超過 idleSeconds 沒操作，或分頁在背景，就暫停輪詢，讓 session 照常逾時
    function isHidden() { return document.hidden === true || document.msHidden === true; }
    function pause(reason) {
        state.paused = true;
        setLive('paused', reason);
    }
    function resume() {
        state.paused = false;
        setLive('live');
        poll(true, true);
    }
    function tick() {
        if (isHidden()) {
            pause('已暫停同步（分頁在背景）');
        } else if (nowMs() - state.lastActivity > CFG.idleSeconds * 1000) {
            pause('已暫停同步（閒置）');
        } else {
            poll(false);
        }
    }
    $(document).on('mousemove keydown mousedown wheel touchstart', function () {
        state.lastActivity = nowMs();
        if (state.paused && !isHidden()) {
            resume();
        }
    });
    $(document).on('visibilitychange msvisibilitychange', function () {
        if (isHidden()) {
            pause('已暫停同步（分頁在背景）');
        } else if (nowMs() - state.lastActivity <= CFG.idleSeconds * 1000) {
            resume();
        }
    });
    // 每分鐘重畫「現在」的紅線位置與 PM 狀態
    setInterval(function () {
        if (state.b && !state.drag) {
            state.laneBg = laneBackground(Math.round((state.todayMs - state.winStart) / DAY));
            $.each(state.b.tools, function (i, t) { renderLane(t); });
        }
    }, 60000);

    // 離開頁面時清除自己的線上 / 編輯中
    $(window).on('pagehide beforeunload', function () {
        if (navigator.sendBeacon && window.FormData) {
            var fd = new FormData();
            fd.append('_csrf', $('meta[name="csrf-token"]').attr('content') || '');
            navigator.sendBeacon(CFG.urls.release, fd);
        }
    });

    // ==================================================================
    // 全螢幕 / 恢復
    //   優先用瀏覽器全螢幕 API；瀏覽器不允許時改成「頁面內最大化」（蓋滿瀏覽器視窗）
    //   右鍵選單、表單、衝突視窗都在 #tb-root 裡面，全螢幕時一樣看得到
    // ==================================================================
    var $root = $('#tb-root'), rootEl = $root[0];
    var FS_TEXT = { off: '[ ⛶ 全螢幕 ]', on: '[ 🗗 恢復 ]' };
    function fsElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
    }
    function isFull() { return $root.hasClass('tb-full'); }
    function fitWrap() {
        if (isFull()) {
            var top = $wrap.offset().top - $(window).scrollTop();
            $wrap.css('max-height', Math.max(200, $(window).height() - top - 16) + 'px');
        } else {
            $wrap.css('max-height', '');
        }
    }
    function setFull(on, native) {
        $root.toggleClass('tb-full', on).toggleClass('tb-full-native', on && !!native);
        $('body').toggleClass('tb-body-full', on);
        $('#tb-fs').text(on ? FS_TEXT.on : FS_TEXT.off).attr('title', on ? '恢復正常大小（Esc）' : '看板全螢幕（快捷鍵 F）');
        closeMenu();
        setTimeout(fitWrap, 0);
    }
    function enterFull() {
        var req = rootEl.requestFullscreen || rootEl.webkitRequestFullscreen || rootEl.msRequestFullscreen;
        if (!req) {
            setFull(true, false);
            return;
        }
        try {
            var p = req.call(rootEl);
            if (p && p.then) {
                p.then(null, function () { setFull(true, false); });   // 被瀏覽器或政策擋下：頁面內最大化
            }
        } catch (err) {
            setFull(true, false);
        }
    }
    function exitFull() {
        if (fsElement()) {
            var ex = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
            if (ex) {
                ex.call(document);
                return;
            }
        }
        setFull(false);
    }
    function toggleFull() {
        if (isFull()) {
            exitFull();
        } else {
            enterFull();
        }
    }
    $(document).on('fullscreenchange webkitfullscreenchange MSFullscreenChange', function () {
        var el = fsElement();
        if (el === rootEl) {
            setFull(true, true);
        } else if (!el && $root.hasClass('tb-full-native')) {
            setFull(false);
        }
    });
    $('#tb-fs').on('click', toggleFull);
    $(document).on('keydown', function (e) {
        if (e.ctrlKey || e.altKey || e.metaKey || $(e.target).is('input, textarea, select') ||
            $modal.is(':visible') || $conflict.is(':visible')) {
            return;
        }
        if ((e.key === 'f' || e.key === 'F' || e.keyCode === 70) && !e.shiftKey) {
            e.preventDefault();
            toggleFull();
        } else if (e.keyCode === 27 && isFull() && !fsElement() && !$menu.is(':visible')) {
            setFull(false);   // 頁面內最大化用 Esc 恢復（瀏覽器全螢幕的 Esc 由瀏覽器處理）
        }
    });
    $(window).on('resize', fitWrap);

    // ==================================================================
    // 其他操作
    // ==================================================================
    $('#tb-tabs').on('click', '.pi-tab', function (e) {
        e.preventDefault();
        if ($modal.is(':visible')) {
            return;
        }
        var m = String($(this).attr('data-model'));
        if (m !== state.model) {
            load(m);
        }
    });
    $('#tb-search').on('input', applySearch);
    $('#tb-legend-btn').on('click', function (e) {
        e.preventDefault();
        $('#tb-legend').toggle();
    });
    $('#tb-ie-ok').on('click', function () { $('#tb-ie').hide(); });

    // ==================================================================
    // 啟動
    // ==================================================================
    if (!CFG.models.length) {
        $wrap.html('<div class="text-red tb-loading">&gt; config/tool_board.ini 沒有設定機型</div>');
        return;
    }
    if (IS_IE) {
        $('#tb-ie').show();
    }
    load(pickModel());
    setInterval(tick, CFG.pollSeconds * 1000);
})(window.jQuery);
