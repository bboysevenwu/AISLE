/*
 * AISLE Pending Issue Control Table (jQuery 1.12，維持 IE11 可執行的 ES5 寫法)
 *   - 列表一次載入，篩選 / 排序 / 統計都在前端做；篩選條件寫進網址
 *   - 每 N 秒輪詢同步別人的修改與「某某 編輯中」；閒置或分頁在背景時暫停，讓登入照常逾時
 *   - 存檔以 version 做樂觀鎖，衝突（409）時可選「覆蓋」或「載入最新」
 *   - 編輯視窗可 Ctrl+V 貼上截圖、拖曳或選檔上傳圖片
 * 設定由 view 放在 window.PENDING_ISSUE
 */
(function ($) {
    if (!$ || !window.PENDING_ISSUE) {
        return;
    }
    var CFG = window.PENDING_ISSUE;
    var STATUS_ORDER = { open: 0, monitor: 1, close: 2 };
    var DEFAULT_STATUS = ['open', 'monitor'];
    var IS_IE = /Trident\/|MSIE /.test(window.navigator.userAgent);

    // ==================================================================
    // 小工具
    // ==================================================================
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function nl2br(s) { return esc(s).replace(/\r?\n/g, '<br>'); }
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    function nowMs() { return new Date().getTime(); }
    function daysBetween(a, b) {
        // a、b 為 YYYY-MM-DD，回傳 b - a 的天數
        var pa = a.split('-'), pb = b.split('-');
        var ta = Date.UTC(+pa[0], +pa[1] - 1, +pa[2]), tb = Date.UTC(+pb[0], +pb[1] - 1, +pb[2]);
        return Math.round((tb - ta) / 86400000);
    }
    function errorText(xhr, fallback) {
        var r = xhr && xhr.responseJSON;
        return (r && r.error) || fallback || '連線失敗，請稍後再試';
    }
    function store(kind, key, value) {
        try {
            var s = window[kind];
            if (value === undefined) {
                return s.getItem(key);
            }
            s.setItem(key, value);
        } catch (e) {
            // 瀏覽器停用 storage 時只是不記憶
        }
        return null;
    }
    /** 把符合的字標亮（不分大小寫） */
    function highlight(text, q) {
        text = String(text);
        if (!q) {
            return esc(text);
        }
        var i = text.toLowerCase().indexOf(q.toLowerCase());
        if (i < 0) {
            return esc(text);
        }
        return esc(text.substring(0, i)) + '<mark>' + esc(text.substring(i, i + q.length)) + '</mark>' + esc(text.substring(i + q.length));
    }
    function shortTime(dt) { return dt ? String(dt).substring(5, 16) : ''; }
    function shortDate(dt) { return dt ? String(dt).substring(5, 10).replace('-', '/') : ''; }

    // ==================================================================
    // Sponsor 元件：下拉選單 + 直接輸入篩選（只能選清單裡的人）
    // ==================================================================
    function Combo($wrap, opts) {
        var self = this;
        this.opts = opts;
        this.items = opts.items.slice(0);
        this.extra = null;        // 不在清單內的目前值（例如已停用的帳號）
        this.value = '';
        this.active = -1;
        this.shown = [];
        $wrap.html(
            '<input type="text" class="pi-combo-input" id="' + esc(opts.inputId) + '" placeholder="' + esc(opts.placeholder || '') + '" autocomplete="off">' +
            '<button type="button" class="pi-combo-btn" tabindex="-1" title="展開清單">▼</button>' +
            '<ul class="pi-combo-list" style="display:none"></ul>'
        );
        this.$input = $wrap.find('.pi-combo-input');
        this.$list = $wrap.find('.pi-combo-list');

        this.$input.on('focus click', function () {
            self.open(self.$input.val() === self.labelOf(self.value) ? '' : self.$input.val());
        }).on('input', function () {
            self.open(self.$input.val());
        }).on('keydown', function (e) {
            var k = e.keyCode;
            if (k === 40 || k === 38) {
                e.preventDefault();
                if (!self.$list.is(':visible')) {
                    self.open('');
                    return;
                }
                self.move(k === 40 ? 1 : -1);
            } else if (k === 13) {
                e.preventDefault();
                if (self.$list.is(':visible') && self.active >= 0 && self.shown[self.active]) {
                    self.choose(self.shown[self.active].id);
                }
            } else if (k === 27) {
                if (self.$list.is(':visible')) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.close();
                    self.$input.val(self.labelOf(self.value));
                }
            }
        }).on('blur', function () {
            setTimeout(function () { self.commit(); }, 150);
        });
        $wrap.find('.pi-combo-btn').on('mousedown', function (e) {
            e.preventDefault();
        }).on('click', function () {
            if (self.$list.is(':visible')) {
                self.close();
            } else {
                self.$input.focus();
                self.open('');
            }
        });
        this.$list.on('mousedown', 'li', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            self.choose(id === undefined || id === '' ? '' : String(id));
        });
    }
    Combo.prototype.all = function () {
        var list = [];
        if (this.opts.allowAll) {
            list.push({ id: '', name: this.opts.allLabel || '全部', account: '' });
        }
        list = list.concat(this.items);
        if (this.extra) {
            list.push(this.extra);
        }
        return list;
    };
    Combo.prototype.labelOf = function (id) {
        var list = this.all();
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].id) === String(id)) {
                return list[i].id === '' ? '' : list[i].name;
            }
        }
        return '';
    };
    Combo.prototype.open = function (q) {
        q = $.trim(q || '').toLowerCase();
        var list = this.all(), html = '', self = this;
        this.shown = [];
        $.each(list, function (i, it) {
            if (q && it.id !== '' && it.name.toLowerCase().indexOf(q) < 0 && it.account.toLowerCase().indexOf(q) < 0) {
                return;
            }
            if (q && it.id === '') {
                return;
            }
            self.shown.push(it);
        });
        $.each(this.shown, function (i, it) {
            var sel = String(it.id) === String(self.value);
            html += '<li data-id="' + esc(it.id) + '" class="' + (sel ? 'is-selected' : '') + (it.inactive ? ' is-inactive' : '') + '">' +
                (it.id === '' ? esc(it.name) : highlight(it.name, q) +
                    (it.account && it.account !== it.name ? ' <span class="dim">' + highlight(it.account, q) + '</span>' : '') +
                    (it.inactive ? ' <span class="dim">（已停用）</span>' : '')) +
                '</li>';
        });
        if (!this.shown.length) {
            html = '<li class="pi-combo-empty dim">&gt; 沒有符合的 AISLE 使用者</li>';
        }
        this.$list.html(html).show();
        this.active = -1;
        for (var i = 0; i < this.shown.length; i++) {
            if (String(this.shown[i].id) === String(this.value)) {
                this.active = i;
            }
        }
        if (this.active < 0 && this.shown.length && q) {
            this.active = 0;
        }
        this.paintActive();
    };
    Combo.prototype.move = function (d) {
        if (!this.shown.length) {
            return;
        }
        this.active = (this.active + d + this.shown.length) % this.shown.length;
        this.paintActive();
    };
    Combo.prototype.paintActive = function () {
        var $li = this.$list.children('li').removeClass('is-active');
        if (this.active >= 0) {
            var el = $li.eq(this.active).addClass('is-active')[0];
            if (el && el.offsetTop !== undefined) {
                var list = this.$list[0];
                if (el.offsetTop < list.scrollTop) {
                    list.scrollTop = el.offsetTop;
                } else if (el.offsetTop + el.offsetHeight > list.scrollTop + list.clientHeight) {
                    list.scrollTop = el.offsetTop + el.offsetHeight - list.clientHeight;
                }
            }
        }
    };
    Combo.prototype.close = function () {
        this.$list.hide();
    };
    Combo.prototype.choose = function (id) {
        var changed = String(id) !== String(this.value);
        this.value = String(id);
        this.$input.val(this.labelOf(id)).removeClass('is-invalid');
        this.close();
        if (changed && this.opts.onChange) {
            this.opts.onChange(this.value);
        }
    };
    /** 離開欄位：打了字卻沒選 → 完全符合一人就選他，否則還原 */
    Combo.prototype.commit = function () {
        if (this.$input.is(':focus')) {
            return;
        }
        this.close();
        var text = $.trim(this.$input.val());
        if (text === this.labelOf(this.value)) {
            return;
        }
        if (text === '' && this.opts.allowAll) {
            this.choose('');
            return;
        }
        var list = this.all(), hit = null, lower = text.toLowerCase();
        for (var i = 0; i < list.length; i++) {
            if (list[i].id !== '' && (list[i].name.toLowerCase() === lower || list[i].account.toLowerCase() === lower)) {
                hit = list[i];
                break;
            }
        }
        if (hit) {
            this.choose(String(hit.id));
            return;
        }
        this.$input.val(this.labelOf(this.value));
        if (this.opts.onInvalid) {
            this.opts.onInvalid();
        }
    };
    /** 設定目前值；不在清單內（已停用）的帳號用 name 顯示 */
    Combo.prototype.set = function (id, name) {
        id = id === null || id === undefined ? '' : String(id);
        this.extra = null;
        var found = false;
        for (var i = 0; i < this.items.length; i++) {
            if (String(this.items[i].id) === id) {
                found = true;
            }
        }
        if (!found && id !== '') {
            this.extra = { id: id, name: name || ('#' + id), account: '', inactive: true };
        }
        this.value = id;
        this.$input.val(this.labelOf(id)).removeClass('is-invalid');
    };

    // ==================================================================
    // 主程式
    // ==================================================================
    $(function () {
        var $rows = $('#pi-rows');
        var $modal = $('#pi-modal');
        var $form = $('#pi-form');
        var $error = $('#pi-error');
        var $images = $('#pi-images');
        var $conflict = $('#pi-conflict');
        var $live = $('#pi-live');

        var sponsorItems = $.map(CFG.sponsors, function (u) {
            return { id: String(u.id), name: u.name, account: u.account };
        });
        var sponsorName = {};
        $.each(CFG.sponsors, function (i, u) { sponsorName[u.id] = u.name; });

        var state = {
            rows: {},            // id => row
            cursor: '',
            trash: false,
            loaded: false,
            filter: { model: '', status: DEFAULT_STATUS.slice(0), cat: [], tool: '', sponsor: '', q: '', overdue: false },
            sort: { key: '', dir: 'asc' },
            editors: {},         // issue_id => [名字]
            editing: null,       // 編輯中的 issue（新增時為 null）
            editingId: 0,
            original: null,      // 開啟編輯視窗當下的表單值，衝突比對用
            images: [],
            uploading: 0,
            dirty: false,
            conflictIssue: null,
            lastActivity: nowMs(),
            paused: false,
            pollXhr: null
        };

        // ---------------- 網址參數 ----------------
        function getParam(name) {
            var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
            return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : null;
        }
        function splitList(v, allowed) {
            var out = [];
            $.each(String(v || '').split(','), function (i, s) {
                if (s && allowed[s] !== undefined) {
                    out.push(s);
                }
            });
            return out;
        }
        function readUrl() {
            var f = state.filter, v;
            v = getParam('model');
            f.model = v && CFG.models[v] !== undefined ? v : '';
            v = getParam('status');
            f.status = v === null ? DEFAULT_STATUS.slice(0) : splitList(v, CFG.statuses);
            f.cat = splitList(getParam('cat'), CFG.categories);
            f.tool = getParam('tool') || '';
            f.sponsor = getParam('sponsor') || '';
            f.q = getParam('q') || '';
            f.overdue = getParam('overdue') === '1';
            v = getParam('sort');
            state.sort.key = v || '';
            state.sort.dir = getParam('dir') === 'desc' ? 'desc' : 'asc';
        }
        function filterParams() {
            var f = state.filter, p = {};
            if (f.model) { p.model = f.model; }
            p.status = f.status.join(',');
            if (f.cat.length) { p.cat = f.cat.join(','); }
            if (f.tool) { p.tool = f.tool; }
            if (f.sponsor) { p.sponsor = f.sponsor; }
            if (f.q) { p.q = f.q; }
            if (f.overdue) { p.overdue = 1; }
            if (state.sort.key) {
                p.sort = state.sort.key;
                p.dir = state.sort.dir;
            }
            return p;
        }
        function writeUrl() {
            if (!window.history || !window.history.replaceState) {
                return;
            }
            var p = $.extend({ m: getParam('m') || 'pending_issue' }, filterParams());
            window.history.replaceState(null, '', window.location.pathname + '?' + $.param(p));
        }

        // ---------------- 篩選與排序 ----------------
        function isOverdue(r) {
            return r.checkpoint_date && r.status !== 'close' && !r.deleted && r.checkpoint_date < today();
        }
        function matches(r, ignoreModel) {
            var f = state.filter;
            if (!ignoreModel && f.model && r.model !== f.model) { return false; }
            if (f.status.length && $.inArray(r.status, f.status) < 0) { return false; }
            if (f.cat.length && $.inArray(r.category, f.cat) < 0) { return false; }
            if (f.tool && r.tool_id.indexOf(f.tool.toUpperCase()) < 0) { return false; }
            if (f.sponsor && String(r.sponsor_id) !== String(f.sponsor)) { return false; }
            if (f.q) {
                var q = f.q.toLowerCase();
                if ((r.issue + '\n' + r.remark + '\n' + r.tool_id).toLowerCase().indexOf(q) < 0) { return false; }
            }
            if (f.overdue && !isOverdue(r)) { return false; }
            return true;
        }
        function sortValue(r, key) {
            switch (key) {
            case 'id': return r.id;
            case 'status': return STATUS_ORDER[r.status];
            case 'sponsor': return String(r.sponsor_name).toLowerCase();
            case 'model': return String(CFG.models[r.model] || r.model).toLowerCase();
            case 'category': return String(CFG.categories[r.category] || r.category).toLowerCase();
            default: return String(r[key] === null || r[key] === undefined ? '' : r[key]).toLowerCase();
            }
        }
        function compare(a, b) {
            var key = state.sort.key, va, vb;
            if (key) {
                va = sortValue(a, key);
                vb = sortValue(b, key);
                // 空值一律排最後
                if (va === '' && vb !== '') { return 1; }
                if (vb === '' && va !== '') { return -1; }
                if (va < vb) { return state.sort.dir === 'asc' ? -1 : 1; }
                if (va > vb) { return state.sort.dir === 'asc' ? 1 : -1; }
                return b.id - a.id;
            }
            // 預設：Open → Monitor → Close，Check point 早的在前（沒填的最後），再依新到舊
            if (a.status !== b.status) { return STATUS_ORDER[a.status] - STATUS_ORDER[b.status]; }
            if (a.checkpoint_date !== b.checkpoint_date) {
                if (!a.checkpoint_date) { return 1; }
                if (!b.checkpoint_date) { return -1; }
                return a.checkpoint_date < b.checkpoint_date ? -1 : 1;
            }
            return b.id - a.id;
        }

        // ---------------- 畫面 ----------------
        function statusTag(s) {
            return '<span class="pi-tag pi-tag-' + esc(s) + '">' + esc(CFG.statuses[s] || s) + '</span>';
        }
        function render() {
            var list = [], all = [];
            $.each(state.rows, function (id, r) {
                all.push(r);
                if (matches(r, false)) {
                    list.push(r);
                }
            });
            list.sort(compare);

            var html = '';
            $.each(list, function (i, r) {
                var od = isOverdue(r) ? daysBetween(r.checkpoint_date, today()) : 0;
                html += '<tr class="pi-row pi-row-' + esc(r.status) + (r.deleted ? ' pi-row-deleted' : '') + '" data-id="' + r.id + '">' +
                    '<td class="dim">' + r.id + '</td>' +
                    '<td class="pi-nowrap">' + esc(CFG.models[r.model] || r.model) + '</td>' +
                    '<td class="pi-tool">' + esc(r.tool_id) + '</td>' +
                    '<td>' + esc(CFG.categories[r.category] || r.category) + '</td>' +
                    '<td class="pi-issue"><div class="pi-clip">' + nl2br(r.issue) + '</div>' +
                        (r.image_count ? '<span class="pi-imgcount" title="圖片">[IMG×' + r.image_count + ']</span>' : '') +
                        '<span class="pi-editing" data-editing="' + r.id + '"></span></td>' +
                    '<td class="pi-nowrap">' + esc(r.initial_date) + '</td>' +
                    '<td class="pi-nowrap">' + statusTag(r.status) +
                        '<small class="pi-by" title="最後改狀態：' + esc(r.status_changed_by_name + ' ' + (r.status_changed_at || '')) + '">' +
                        esc(r.status_changed_by_name) + ' ' + esc(shortDate(r.status_changed_at)) + '</small></td>' +
                    '<td class="pi-nowrap">' + esc(r.sponsor_name) + (r.sponsor_active ? '' : ' <span class="dim">(已停用)</span>') + '</td>' +
                    '<td class="pi-nowrap' + (od > 0 ? ' pi-overdue' : '') + '">' + esc(r.checkpoint_date) +
                        (od > 0 ? '<small>逾期 ' + od + ' 天</small>' : '') + '</td>' +
                    '<td class="pi-remark"><div class="pi-clip">' + nl2br(r.remark) + '</div></td>' +
                    '<td class="dim pi-nowrap" title="' + esc(r.updated_at) + '">' + esc(r.updated_by_name) + '<br>' + esc(shortTime(r.updated_at)) +
                        (r.deleted ? '<br><span class="text-red">已刪除</span>' +
                            (CFG.canRestore ? '<br><button type="button" class="btn btn-sm pi-restore" data-id="' + r.id + '">[ 還原 ]</button>' : '') : '') +
                    '</td></tr>';
            });
            if (!list.length) {
                html = '<tr><td colspan="11" class="dim">&gt; ' + (state.loaded ? '沒有符合條件的 issue' : 'LOADING...') + '</td></tr>';
            }
            $rows.html(html);
            $('#pi-count').text('(' + list.length + ' / ' + all.length + ')');
            renderSort();
            renderSummary(all);
            renderTools(all);
            renderEditors();
        }

        function renderSort() {
            $('.pi-table th[data-sort]').each(function () {
                var $th = $(this), on = $th.data('sort') === state.sort.key;
                $th.toggleClass('is-sorted', on).attr('data-dir', on ? state.sort.dir : null);
            });
        }

        function renderSummary(all) {
            var sum = {}, models = { '': 0 };
            $.each(CFG.categories, function (c) { sum[c] = { open: 0, monitor: 0, close: 0 }; });
            $.each(CFG.models, function (m) { models[m] = 0; });
            $.each(all, function (i, r) {
                if (r.deleted) {
                    return;
                }
                if (r.status !== 'close') {
                    models[''] += 1;
                    if (models[r.model] !== undefined) {
                        models[r.model] += 1;
                    }
                }
                if ((!state.filter.model || r.model === state.filter.model) && sum[r.category] && sum[r.category][r.status] !== undefined) {
                    sum[r.category][r.status] += 1;
                }
            });
            $('#pi-summary .pi-sum-card').each(function () {
                var $c = $(this), s = sum[$c.data('category')] || {};
                $c.find('[data-count]').each(function () {
                    $(this).text(s[$(this).data('count')] || 0);
                });
            });
            $('#pi-tabs [data-model-count]').each(function () {
                var n = models[$(this).attr('data-model-count')] || 0;
                $(this).text(n ? '(' + n + ')' : '').attr('title', '未結案 ' + n + ' 筆');
            });
            $('#pi-tabs .pi-tab').each(function () {
                $(this).toggleClass('active', String($(this).data('model')) === state.filter.model);
            });
        }

        function renderTools(all) {
            var seen = {}, html = '';
            $.each(all, function (i, r) {
                if (!seen[r.tool_id] && (!state.filter.model || r.model === state.filter.model)) {
                    seen[r.tool_id] = true;
                }
            });
            $.each($.map(seen, function (v, k) { return k; }).sort(), function (i, t) {
                html += '<option value="' + esc(t) + '">';
            });
            $('#pi-tools').html(html);
        }

        function renderEditors() {
            $rows.find('[data-editing]').each(function () {
                var names = state.editors[$(this).data('editing')];
                $(this).html(names ? '<span class="pi-who">✎ ' + esc(names.join(', ')) + ' 編輯中</span>' : '');
            });
            var others = state.editingId ? state.editors[state.editingId] : null;
            if (others && $modal.is(':visible')) {
                $('#pi-editors').html('&gt; ' + esc(others.join(', ')) + ' 也正在編輯這筆；誰先存檔，另一人存檔時會出現衝突提示').show();
            } else {
                $('#pi-editors').hide();
            }
        }

        function syncFilterInputs() {
            var f = state.filter;
            $('#f-status input').each(function () { this.checked = $.inArray(this.value, f.status) >= 0; });
            $('#f-cat input').each(function () { this.checked = $.inArray(this.value, f.cat) >= 0; });
            $('#f-tool').val(f.tool);
            $('#f-q').val(f.q);
            $('#f-overdue').prop('checked', f.overdue);
            filterSponsor.set(f.sponsor);
        }

        function setLive(mode, text) {
            $live.removeClass('is-paused is-error');
            if (mode === 'paused') {
                $live.addClass('is-paused').text('⏸ ' + text);
            } else if (mode === 'error') {
                $live.addClass('is-error').text('● OFFLINE');
            } else {
                $live.text('● LIVE');
            }
        }

        // ---------------- 載入與輪詢 ----------------
        function load() {
            return $.get(CFG.urls.list, { trash: state.trash ? 1 : 0 }).done(function (res) {
                state.rows = {};
                $.each(res.rows, function (i, r) { state.rows[r.id] = r; });
                state.cursor = res.cursor;
                state.loaded = true;
                render();
            }).fail(function (xhr) {
                if (xhr.status !== 401) {
                    $rows.html('<tr><td colspan="11" class="text-red">&gt; ' + esc(errorText(xhr)) + '</td></tr>');
                }
            });
        }

        function poll(force) {
            if ((state.paused && !force) || state.pollXhr || !state.loaded) {
                return;
            }
            state.pollXhr = $.get(CFG.urls.poll, {
                since: state.cursor,
                editing: state.editingId,
                trash: state.trash ? 1 : 0
            }).done(function (res) {
                var changed = false;
                state.cursor = res.cursor;
                state.editors = res.editors || {};
                $.each(res.changed || [], function (i, r) {
                    var cur = state.rows[r.id];
                    if (!cur || cur.version < r.version) {
                        state.rows[r.id] = r;
                        changed = true;
                        noteStale(r, false);
                    }
                });
                $.each(res.deleted || [], function (i, id) {
                    if (state.rows[id]) {
                        noteStale(state.rows[id], true);
                        delete state.rows[id];
                        changed = true;
                    }
                });
                if (changed) {
                    render();
                } else {
                    renderEditors();
                }
                if (!state.paused) {
                    setLive('live');
                }
            }).fail(function (xhr) {
                if (xhr.status !== 401) {
                    setLive('error');
                }
            }).always(function () {
                state.pollXhr = null;
            });
        }

        /** 別人改了我正在編輯的那一筆：立刻提示，不用等到存檔 */
        function noteStale(r, gone) {
            if (!$modal.is(':visible') || state.editingId !== r.id) {
                return;
            }
            var mine = +$form.find('[name=version]').val();
            if (!gone && r.version <= mine) {
                return;
            }
            $('#pi-stale-text').text(gone
                ? '> 這筆已被刪除，存檔將會失敗'
                : '> 這筆已被 ' + r.updated_by_name + ' 於 ' + r.updated_at + ' 更新');
            $('#pi-stale-load').toggle(!gone);
            $('#pi-stale').show();
        }

        // 閒置偵測：超過 idleSeconds 沒操作，或分頁在背景，就暫停輪詢，讓 session 照常逾時
        function isHidden() { return document.hidden === true || document.msHidden === true; }
        function pause(reason) {
            if (!state.paused) {
                state.paused = true;
            }
            setLive('paused', reason);
        }
        function resume() {
            state.paused = false;
            setLive('live');
            poll(true);
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
        $(document).on('mousemove keydown mousedown wheel touchstart paste', function () {
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

        // ---------------- 篩選操作 ----------------
        function applyFilter() {
            writeUrl();
            render();
        }
        var filterSponsor = new Combo($('#f-sponsor'), {
            items: sponsorItems,
            allowAll: true,
            allLabel: '全部',
            inputId: 'f-sponsor-text',
            placeholder: '全部（可輸入篩選）',
            onChange: function (id) {
                state.filter.sponsor = id;
                applyFilter();
            }
        });

        $('#pi-tabs').on('click', '.pi-tab', function (e) {
            e.preventDefault();
            state.filter.model = String($(this).data('model') || '');
            applyFilter();
        });
        $('#f-status, #f-cat').on('change', 'input', function () {
            state.filter.status = $('#f-status input:checked').map(function () { return this.value; }).get();
            state.filter.cat = $('#f-cat input:checked').map(function () { return this.value; }).get();
            applyFilter();
        });
        var typing = null;
        $('#f-tool, #f-q').on('input', function () {
            clearTimeout(typing);
            typing = setTimeout(function () {
                state.filter.tool = $.trim($('#f-tool').val());
                state.filter.q = $.trim($('#f-q').val());
                applyFilter();
            }, 250);
        });
        $('#f-overdue').on('change', function () {
            state.filter.overdue = this.checked;
            applyFilter();
        });
        $('#f-trash').on('change', function () {
            state.trash = this.checked;
            state.loaded = false;
            load();
        });
        $('#pi-reset').on('click', function () {
            state.filter = { model: state.filter.model, status: DEFAULT_STATUS.slice(0), cat: [], tool: '', sponsor: '', q: '', overdue: false };
            state.sort = { key: '', dir: 'asc' };
            syncFilterInputs();
            applyFilter();
        });
        $('#pi-summary').on('click', '[data-sum-cat]', function (e) {
            e.preventDefault();
            var st = $(this).data('sum-status');
            state.filter.cat = [String($(this).data('sum-cat'))];
            state.filter.status = st ? [String(st)] : [];
            syncFilterInputs();
            applyFilter();
        });
        $('.pi-table').on('click', 'th[data-sort]', function () {
            var key = $(this).data('sort');
            if (state.sort.key === key) {
                if (state.sort.dir === 'asc') {
                    state.sort.dir = 'desc';
                } else {
                    state.sort = { key: '', dir: 'asc' };   // 第三次點回到預設排序
                }
            } else {
                state.sort = { key: key, dir: 'asc' };
            }
            applyFilter();
        });

        $('#pi-export').on('click', function () {
            var p = filterParams();
            if (state.trash) {
                p.trash = 1;
            }
            window.location.href = CFG.urls['export'] + '&' + $.param(p);
        });

        // ---------------- 編輯視窗 ----------------
        var editSponsor = new Combo($('#e-sponsor'), {
            items: sponsorItems,
            allowAll: false,
            inputId: 'e-sponsor-text',
            placeholder: '輸入帳號或名字篩選',
            onChange: function () { state.dirty = true; },
            onInvalid: function () {
                showError('Sponsor 必須是 AISLE 使用者，請從清單中選擇');
                editSponsor.$input.addClass('is-invalid');
            }
        });

        var FIELDS = ['tool_id', 'model', 'category', 'issue', 'initial_date', 'status', 'checkpoint_date', 'remark'];
        var FIELD_LABEL = {
            tool_id: '機台', model: '機型', category: '分類', issue: 'Issue 描述', initial_date: 'Initial date',
            status: '狀態', sponsor_id: 'Sponsor', checkpoint_date: 'Check point', remark: 'Remark', images: '圖片'
        };

        function formValues() {
            var v = {};
            $.each(FIELDS, function (i, k) { v[k] = $.trim($form.find('[name=' + k + ']').val() || ''); });
            v.tool_id = v.tool_id.toUpperCase();
            v.sponsor_id = editSponsor.value;
            v.images = $.map(state.images, function (img) { return img.id; }).join(',');
            return v;
        }
        function issueValues(issue) {
            var v = {};
            $.each(FIELDS, function (i, k) { v[k] = issue[k] === null || issue[k] === undefined ? '' : String(issue[k]); });
            v.sponsor_id = String(issue.sponsor_id);
            v.images = $.map(issue.images || [], function (img) { return img.id; }).join(',');
            return v;
        }
        function displayValue(k, v, issue) {
            if (k === 'model') { return CFG.models[v] || v; }
            if (k === 'category') { return CFG.categories[v] || v; }
            if (k === 'status') { return CFG.statuses[v] || v; }
            if (k === 'sponsor_id') { return issue && String(issue.sponsor_id) === v ? issue.sponsor_name : (sponsorName[v] || v); }
            if (k === 'images') { return v ? v.split(',').length + ' 張' : '0 張'; }
            return v;
        }

        function detectModel(tool) {
            var keys = $.map(CFG.models, function (v, k) { return k; });
            keys.sort(function (a, b) { return b.length - a.length; });
            tool = tool.toUpperCase();
            for (var i = 0; i < keys.length; i++) {
                if (tool.indexOf(keys[i]) >= 0) {
                    return keys[i];
                }
            }
            return '';
        }

        function openEditor(issue) {
            var f = $form[0];
            f.reset();
            $error.hide();
            $('#pi-stale').hide();
            state.dirty = false;
            state.images = [];
            state.editing = issue;
            if (issue) {
                state.editingId = issue.id;
                $('#pi-modal-title').text('> ISSUE #' + issue.id);
                var v = issueValues(issue);
                $.each(FIELDS, function (i, k) { $form.find('[name=' + k + ']').val(v[k]); });
                $form.find('[name=id]').val(issue.id);
                $form.find('[name=version]').val(issue.version);
                editSponsor.set(issue.sponsor_id, issue.sponsor_name);
                state.images = (issue.images || []).slice(0);
                $('#pi-meta').text('建立：' + issue.created_by_name + ' ' + issue.created_at +
                    ' ／ 最後修改：' + issue.updated_by_name + ' ' + issue.updated_at +
                    (issue.closed_at ? ' ／ 結案：' + issue.closed_at : '') + ' ／ v' + issue.version);
                $('#pi-delete').toggle(!!CFG.canDelete);   // 刪除只有 ADMIN（config.ini action.delete）
                renderHistory(issue.status_logs || []);
            } else {
                state.editingId = 0;
                $('#pi-modal-title').text('> NEW ISSUE');
                $form.find('[name=id]').val(0);
                $form.find('[name=version]').val(0);
                $form.find('[name=initial_date]').val(today());
                $form.find('[name=status]').val('open');
                $form.find('[name=model]').val(state.filter.model);
                if (state.filter.cat.length === 1) {
                    $form.find('[name=category]').val(state.filter.cat[0]);
                }
                editSponsor.set(sponsorName[CFG.me.id] ? CFG.me.id : '');
                $('#pi-meta').text('');
                $('#pi-delete').hide();
                renderHistory([]);
            }
            state.original = formValues();
            renderImages();
            $modal.show();
            $('body').addClass('pi-modal-open');
            renderEditors();
            $form.find('[name=tool_id]').focus();
            state.lastActivity = nowMs();
            if (state.paused) {
                resume();
            } else {
                poll(true);   // 立刻讓別人看到「編輯中」
            }
        }

        function renderHistory(logs) {
            if (!logs.length) {
                $('#pi-history').hide();
                return;
            }
            var html = '';
            $.each(logs, function (i, l) {
                html += '<li>' + esc(l.changed_at) + ' · <b>' + esc(l.by_name) + '</b> · ' +
                    (l.from_status ? statusTag(l.from_status) + ' → ' : '新增 ') + statusTag(l.to_status) + '</li>';
            });
            $('#pi-history-list').html(html);
            $('#pi-history').show();
        }

        function release() {
            $.post(CFG.urls.release);
        }

        function closeEditor(force) {
            if (!force && state.dirty && !window.confirm('內容尚未儲存，確定要放棄修改？')) {
                return;
            }
            $modal.hide();
            $conflict.hide();
            $('body').removeClass('pi-modal-open');
            if (state.editingId) {
                release();
            }
            state.editingId = 0;
            state.editing = null;
            state.dirty = false;
            renderEditors();
        }

        function showError(msg) {
            $error.html('&gt; ' + esc(msg)).show();
            $modal.scrollTop(0);
        }

        $('#pi-new').on('click', function () { openEditor(null); });
        $('#pi-cancel, #pi-cancel-x').on('click', function () { closeEditor(false); });
        $modal.on('mousedown', function (e) {
            if (e.target === this) {
                closeEditor(false);
            }
        });
        $(document).on('keydown', function (e) {
            if (e.keyCode !== 27) {
                return;
            }
            if ($conflict.is(':visible')) {
                $conflict.hide();
            } else if ($modal.is(':visible')) {
                closeEditor(false);
            }
        });
        $form.on('input change', 'input, select, textarea', function () {
            state.dirty = true;
        });
        $form.on('input change', '[name=tool_id]', function () {
            var m = detectModel(this.value);
            if (m) {
                $form.find('[name=model]').val(m);
            }
        });

        function openById(id) {
            $.get(CFG.urls.get, { id: id }).done(function (res) {
                openEditor(res.issue);
            }).fail(function (xhr) {
                if (xhr.status !== 401) {
                    window.alert(errorText(xhr));
                    load();
                }
            });
        }
        $rows.on('click', '.pi-row', function (e) {
            if ($(this).hasClass('pi-row-deleted') || $(e.target).closest('button, a').length) {
                return;
            }
            openById($(this).data('id'));
        });
        $('#pi-stale-load').on('click', function () {
            if (!state.dirty || window.confirm('載入最新內容會放棄你目前的修改，確定？')) {
                openById(state.editingId);
            }
        });

        function submit(overwrite) {
            if (state.uploading > 0) {
                showError('圖片還在上傳中，請稍候');
                return;
            }
            var v = formValues();
            if (!v.tool_id) { showError('機台必填'); return; }
            if (!v.issue) { showError('Issue 描述必填'); return; }
            if (!v.sponsor_id) { showError('請選擇 Sponsor'); return; }
            var data = {
                id: $form.find('[name=id]').val(),
                version: $form.find('[name=version]').val(),
                sponsor_id: v.sponsor_id,
                image_ids: v.images
            };
            $.each(FIELDS, function (i, k) { data[k] = v[k]; });
            if (overwrite && state.conflictIssue) {
                data.version = state.conflictIssue.version;
                data.overwrite = 1;
            }
            var $btn = $('#pi-save, #pi-conflict-overwrite').prop('disabled', true);
            $.post(CFG.urls.save, data).done(function (res) {
                state.rows[res.issue.id] = res.issue;
                state.conflictIssue = null;
                closeEditor(true);
                render();
            }).fail(function (xhr) {
                var r = xhr.responseJSON || {};
                if (xhr.status === 409 && r.reason === 'modified' && r.issue) {
                    showConflict(r);
                } else if (xhr.status === 409) {
                    $conflict.hide();
                    window.alert(r.error || '這筆 issue 已不存在');
                    if (state.editingId) {
                        delete state.rows[state.editingId];
                    }
                    closeEditor(true);
                    render();
                } else if (xhr.status !== 401) {
                    $conflict.hide();
                    showError(errorText(xhr));
                }
            }).always(function () {
                $btn.prop('disabled', false);
            });
        }

        /** 409：列出你的版本與最新版本不同的欄位，讓使用者選覆蓋 / 載入最新 / 取消 */
        function showConflict(r) {
            state.conflictIssue = r.issue;
            state.rows[r.issue.id] = r.issue;
            render();
            var mine = formValues(), latest = issueValues(r.issue), html = '';
            $.each(FIELDS.concat(['sponsor_id', 'images']), function (i, k) {
                if (mine[k] !== latest[k]) {
                    var changedByOther = state.original && state.original[k] !== latest[k];
                    html += '<tr' + (changedByOther ? ' class="pi-diff-hot"' : '') + '><td>' + esc(FIELD_LABEL[k]) + '</td>' +
                        '<td>' + nl2br(displayValue(k, mine[k], state.editing)) + '</td>' +
                        '<td>' + nl2br(displayValue(k, latest[k], r.issue)) + '</td></tr>';
                }
            });
            if (!html) {
                html = '<tr><td colspan="3" class="dim">&gt; 內容與最新版本相同（對方可能只改了你沒改的地方又改回來）</td></tr>';
            }
            $('#pi-conflict-msg').text('⚠ ' + r.error);
            $('#pi-conflict-rows').html(html);
            $conflict.show();
        }
        $('#pi-conflict-overwrite').on('click', function () { submit(true); });
        $('#pi-conflict-load').on('click', function () {
            var latest = state.conflictIssue;
            $conflict.hide();
            state.conflictIssue = null;
            if (latest) {
                openEditor(latest);
            }
        });
        $('#pi-conflict-cancel').on('click', function () { $conflict.hide(); });

        $form.on('submit', function (e) {
            e.preventDefault();
            submit(false);
        });

        $('#pi-delete').on('click', function () {
            var id = +$form.find('[name=id]').val();
            if (!window.confirm('確定刪除 ISSUE #' + id + '？')) {
                return;
            }
            $.post(CFG.urls['delete'], { id: id, version: $form.find('[name=version]').val() }).done(function () {
                delete state.rows[id];
                closeEditor(true);
                render();
            }).fail(function (xhr) {
                var r = xhr.responseJSON || {};
                if (xhr.status === 409 && r.reason === 'modified' && r.issue) {
                    window.alert(r.error + '。\n已載入最新內容，請確認後再決定是否刪除。');
                    state.rows[id] = r.issue;
                    render();
                    openEditor(r.issue);
                } else if (xhr.status === 409) {
                    window.alert(r.error);
                    delete state.rows[id];
                    closeEditor(true);
                    render();
                } else if (xhr.status !== 401) {
                    showError(errorText(xhr));
                }
            });
        });

        $rows.on('click', '.pi-restore', function () {
            var id = $(this).data('id'), r = state.rows[id];
            if (!r || !window.confirm('還原 ISSUE #' + id + '？')) {
                return;
            }
            $.post(CFG.urls.restore, { id: id, version: r.version }).done(function (res) {
                state.rows[id] = res.issue;
                render();
            }).fail(function (xhr) {
                if (xhr.status !== 401) {
                    window.alert(errorText(xhr));
                    load();
                }
            });
        });

        // ---------------- 圖片：貼上 / 拖曳 / 選檔 ----------------
        function renderImages() {
            var html = '';
            $.each(state.images, function (i, img) {
                html += '<div class="pi-thumb" data-index="' + i + '">' +
                    '<a href="' + esc(img.url) + '" target="_blank" rel="noopener noreferrer" title="' + esc(img.name) + '">' +
                    '<img src="' + esc(img.url) + '" alt="' + esc(img.name) + '"></a>' +
                    '<button type="button" class="pi-thumb-del" title="移除">×</button></div>';
            });
            if (state.uploading) {
                html += '<div class="pi-thumb pi-thumb-loading">UPLOADING ' + state.uploading + '...</div>';
            }
            $images.html(html);
        }

        function upload(file) {
            if (!/^image\/(png|jpeg|gif)$/.test(file.type)) {
                showError('只接受 PNG / JPG / GIF 圖片');
                return;
            }
            if (file.size > CFG.maxImageMb * 1048576) {
                showError('圖片超過 ' + CFG.maxImageMb + ' MB');
                return;
            }
            if (state.images.length + state.uploading >= CFG.maxImages) {
                showError('每筆 issue 最多 ' + CFG.maxImages + ' 張圖片');
                return;
            }
            var fd = new window.FormData();
            var d = new Date();
            var ext = file.type === 'image/jpeg' ? 'jpg' : file.type.split('/')[1];
            var name = file.name || ('paste_' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '_' +
                pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds()) + '.' + ext);
            if (file.name) {
                fd.append('image', file);
            } else {
                fd.append('image', file, name);
            }
            state.uploading++;
            renderImages();
            $.ajax({
                url: CFG.urls.upload,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false
            }).done(function (res) {
                state.images.push(res.image);
                state.dirty = true;
            }).fail(function (xhr) {
                if (xhr.status !== 401) {
                    showError(errorText(xhr, '圖片上傳失敗'));
                }
            }).always(function () {
                state.uploading--;
                renderImages();
            });
        }

        function filesFrom(list) {
            var out = [];
            for (var i = 0; list && i < list.length; i++) {
                out.push(list[i]);
            }
            return out;
        }

        // 整個編輯視窗都能貼上（含文字欄），只攔截圖片，文字照常貼上
        $(document).on('paste', function (e) {
            if (!$modal.is(':visible') || $conflict.is(':visible')) {
                return;
            }
            var cd = e.originalEvent && e.originalEvent.clipboardData;
            if (!cd || !cd.items) {
                return;
            }
            var found = false;
            for (var i = 0; i < cd.items.length; i++) {
                if (cd.items[i].kind === 'file' && /^image\//.test(cd.items[i].type)) {
                    var f = cd.items[i].getAsFile();
                    if (f) {
                        upload(f);
                        found = true;
                    }
                }
            }
            if (found) {
                e.preventDefault();
            }
        });

        var $paste = $('#pi-paste');
        $paste.on('dragover dragenter', function (e) {
            e.preventDefault();
            $paste.addClass('is-over');
        }).on('dragleave drop', function () {
            $paste.removeClass('is-over');
        }).on('drop', function (e) {
            e.preventDefault();
            $.each(filesFrom(e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files), function (i, f) { upload(f); });
        });
        // 拖曳到視窗其他地方時不要讓瀏覽器直接開啟圖片
        $modal.on('dragover drop', function (e) {
            if (!$(e.target).closest('#pi-paste').length) {
                e.preventDefault();
            }
        });
        $('#pi-file').on('change', function () {
            $.each(filesFrom(this.files), function (i, f) { upload(f); });
            $(this).val('');
        });
        $images.on('click', '.pi-thumb-del', function () {
            state.images.splice($(this).closest('.pi-thumb').data('index'), 1);
            state.dirty = true;
            renderImages();
        });

        // ---------------- 離開頁面 ----------------
        $(window).on('beforeunload', function () {
            if ($modal.is(':visible') && state.dirty) {
                return '內容尚未儲存';
            }
        });
        $(window).on('unload', function () {
            // 清掉自己的「編輯中」；沒有 sendBeacon 的瀏覽器就等 presence_seconds 自然消失
            if (state.editingId && window.navigator.sendBeacon && window.FormData) {
                var fd = new window.FormData();
                fd.append('_csrf', CFG.csrfToken);
                window.navigator.sendBeacon(CFG.urls.release, fd);
            }
        });

        // ---------------- IE11 警告 ----------------
        if (IS_IE) {
            $('#pi-paste-hint').text('> IE11 不支援貼上截圖，請把圖片拖曳到這裡，或');
            if (store('sessionStorage', 'aisle.pi.ieWarned') !== '1') {
                $('#pi-ie').show();
            }
            $('#pi-ie-ok').on('click', function () {
                store('sessionStorage', 'aisle.pi.ieWarned', '1');
                $('#pi-ie').hide();
            });
        }

        // ---------------- 啟動 ----------------
        readUrl();
        syncFilterInputs();
        render();
        load();
        setInterval(tick, CFG.pollSeconds * 1000);
    });
})(window.jQuery);
