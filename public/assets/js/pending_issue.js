/*
 * AISLE Pending Issue Summary (jQuery 1.12)
 * 列表 / 搜尋 / 新增編輯 / 圖片貼上 / 多人同步（輪詢 + 樂觀鎖）
 * 設定由 view 放在 window.PENDING_ISSUE
 */
(function ($) {
    if (!$ || !window.PENDING_ISSUE) {
        return;
    }
    var CFG = window.PENDING_ISSUE;

    $(function () {
        var esc = AISLE.escape;
        var $rows = $('#pi-rows');
        var $modal = $('#pi-modal');
        var $form = $('#pi-form');
        var $error = $('#pi-error');
        var $images = $('#pi-images');

        var state = {
            stamp: '',
            editingId: 0,       // 編輯視窗開啟中的 issue id（0 = 新增或沒開）
            images: [],         // 編輯中的圖片 [{id,url,name}]
            uploading: 0,
            dirty: false,
            editors: {},        // issue_id => [名字]
            lastActivity: new Date().getTime(),
            paused: false,
            timer: null
        };

        // ---------------- 工具 ----------------
        function pad(n) { return n < 10 ? '0' + n : String(n); }
        function today() {
            var d = new Date();
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        }
        function nl2br(s) { return esc(s).replace(/\r?\n/g, '<br>'); }
        function statusTag(s) {
            return '<span class="pi-tag pi-tag-' + esc(s) + '">' + esc(CFG.statuses[s] || s) + '</span>';
        }
        function errorText(xhr, fallback) {
            var r = xhr && xhr.responseJSON;
            return (r && r.error) || fallback || '連線失敗，請稍後再試';
        }

        // ---------------- 列表 ----------------
        function filterParams() {
            var p = {}, st = [];
            $.each($('#pi-filter').serializeArray(), function (i, f) {
                if (f.name === 'status') {
                    st.push(f.value);
                } else if (f.value !== '') {
                    p[f.name] = f.value;
                }
            });
            p.status = st.join(',');
            return p;
        }

        function load() {
            return $.get(CFG.urls.list, filterParams()).done(function (res) {
                state.stamp = res.stamp;
                renderRows(res.rows, res.truncated);
                renderSummary(res.summary);
            }).fail(function (xhr) {
                $rows.html('<tr><td colspan="10" class="text-red">&gt; ' + esc(errorText(xhr)) + '</td></tr>');
            });
        }

        function renderRows(rows, truncated) {
            if (!rows.length) {
                $rows.html('<tr><td colspan="10" class="dim">&gt; 沒有符合條件的 issue</td></tr>');
                $('#pi-count').text('(0)');
                return;
            }
            var html = '';
            $.each(rows, function (i, r) {
                html += '<tr class="pi-row pi-row-' + esc(r.status) + '" data-id="' + r.id + '">' +
                    '<td class="dim">' + r.id + '</td>' +
                    '<td class="pi-tool">' + esc(r.tool_id) + '</td>' +
                    '<td>' + esc(CFG.categories[r.category] || r.category) + '</td>' +
                    '<td class="pi-title">' + esc(r.title) +
                        (r.image_count ? ' <span class="pi-imgcount" title="圖片">[IMG×' + r.image_count + ']</span>' : '') +
                        '<span class="pi-editing" data-editing="' + r.id + '"></span></td>' +
                    '<td>' + statusTag(r.status) + '</td>' +
                    '<td>' + esc(r.sponsor) + '</td>' +
                    '<td>' + esc(r.owner) + '</td>' +
                    '<td class="pi-remark">' + nl2br(r.remark || '') + '</td>' +
                    '<td class="pi-nowrap">' + esc(r.occurred_on) + '</td>' +
                    '<td class="dim pi-nowrap" title="' + esc(r.updated_at) + '">' + esc(r.updated_by_name) +
                        '<br>' + esc(String(r.updated_at).substring(5, 16)) + '</td>' +
                    '</tr>';
            });
            $rows.html(html);
            $('#pi-count').text('(' + rows.length + (truncated ? '+，請縮小搜尋範圍' : '') + ')');
            renderEditors();
        }

        function renderSummary(sum) {
            $('#pi-summary .pi-sum-card').each(function () {
                var $c = $(this), s = sum[$c.data('category')] || {};
                $c.find('[data-count]').each(function () {
                    $(this).text(s[$(this).data('count')] || 0);
                });
            });
        }

        function renderEditors() {
            $rows.find('[data-editing]').each(function () {
                var names = state.editors[$(this).data('editing')];
                $(this).html(names ? ' <span class="pi-who">✎ ' + esc(names.join(', ')) + ' 編輯中</span>' : '');
            });
            var others = state.editingId ? state.editors[state.editingId] : null;
            if (others) {
                $('#pi-editors').html('&gt; ' + esc(others.join(', ')) + ' 也正在編輯這筆，儲存時若對方先存檔會提示衝突').show();
            } else {
                $('#pi-editors').hide();
            }
        }

        // ---------------- 輪詢：資料變動、誰在編輯 ----------------
        function poll() {
            if (state.paused) {
                return;
            }
            if (new Date().getTime() - state.lastActivity > CFG.idleSeconds * 1000) {
                // 閒置太久就停止，讓 session 能正常逾時；有動作時再恢復
                state.paused = true;
                $('#pi-live').addClass('is-paused').text('● PAUSED');
                return;
            }
            $.get(CFG.urls.poll, { editing: state.editingId }).done(function (res) {
                state.editors = res.editors || {};
                $('#pi-live').removeClass('is-error').text('● LIVE' + (res.editing ? ' · ' + res.editing + ' 人編輯中' : ''));
                if (res.stamp !== state.stamp) {
                    load();
                } else {
                    renderEditors();
                }
            }).fail(function () {
                $('#pi-live').addClass('is-error').text('● OFFLINE');
            });
        }

        function startPolling() {
            if (state.timer) {
                clearInterval(state.timer);
            }
            state.timer = setInterval(poll, CFG.pollSeconds * 1000);
        }

        $(document).on('mousemove keydown click', function () {
            state.lastActivity = new Date().getTime();
            if (state.paused) {
                state.paused = false;
                $('#pi-live').removeClass('is-paused').text('● LIVE');
                poll();
            }
        });

        // ---------------- 搜尋 ----------------
        var searchTimer = null;
        $('#pi-filter').on('submit', function (e) {
            e.preventDefault();
            load();
        }).on('change', 'select, input[type=checkbox]', function () {
            load();
        }).on('input', 'input[type=text]', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 400);
        });
        $('#pi-reset').on('click', function () {
            var f = $('#pi-filter')[0];
            f.reset();
            load();
        });
        $('#pi-summary').on('click', '.pi-sum-card', function (e) {
            e.preventDefault();
            var c = $(this).data('category');
            var $sel = $('#f-category');
            $sel.val($sel.val() === c ? '' : c);
            $('#pi-summary .pi-sum-card').removeClass('active');
            if ($sel.val()) {
                $(this).addClass('active');
            }
            load();
        });

        // ---------------- 編輯視窗 ----------------
        function openEditor(issue) {
            var f = $form[0];
            f.reset();
            $error.hide();
            state.dirty = false;
            state.images = [];
            if (issue) {
                state.editingId = issue.id;
                $('#pi-modal-title').text('> ISSUE #' + issue.id);
                $.each(['id', 'version', 'tool_id', 'category', 'status', 'occurred_on', 'sponsor', 'owner', 'title', 'description', 'remark'], function (i, k) {
                    $form.find('[name=' + k + ']').val(issue[k] === null ? '' : issue[k]);
                });
                state.images = issue.images.slice(0);
                $('#pi-meta').text('建立：' + issue.created_by_name + ' ' + issue.created_at +
                    ' ／ 最後修改：' + issue.updated_by_name + ' ' + issue.updated_at +
                    (issue.closed_at ? ' ／ 結案：' + issue.closed_at : '') + ' ／ v' + issue.version);
                $('#pi-delete').toggle(!!CFG.canDelete);
            } else {
                state.editingId = 0;
                $('#pi-modal-title').text('> NEW ISSUE');
                $form.find('[name=id]').val(0);
                $form.find('[name=version]').val(0);
                $form.find('[name=occurred_on]').val(today());
                $form.find('[name=status]').val('open');
                var cat = $('#f-category').val();
                if (cat) {
                    $form.find('[name=category]').val(cat);
                }
                $('#pi-meta').text('');
                $('#pi-delete').hide();
            }
            renderImages();
            renderEditors();
            $modal.show();
            $('body').addClass('pi-modal-open');
            $form.find('[name=tool_id]').focus();
            poll(); // 立刻告訴別人我開始編輯
        }

        function closeEditor(force) {
            if (!force && state.dirty && !window.confirm('內容尚未儲存，確定要關閉？')) {
                return;
            }
            $modal.hide();
            $('body').removeClass('pi-modal-open');
            state.editingId = 0;
            state.dirty = false;
            poll();
        }

        function showError(msg) {
            $error.html('&gt; ' + esc(msg)).show();
            $modal.find('.pi-dialog').scrollTop(0);
        }

        $('#pi-new').on('click', function () { openEditor(null); });
        $('#pi-cancel, #pi-cancel-x').on('click', function () { closeEditor(false); });
        $modal.on('mousedown', function (e) {
            if (e.target === this) {
                closeEditor(false);
            }
        });
        $(document).on('keydown', function (e) {
            if (e.keyCode === 27 && $modal.is(':visible')) {
                closeEditor(false);
            }
        });
        $form.on('input change', 'input, select, textarea', function () {
            state.dirty = true;
        });

        $rows.on('click', '.pi-row', function () {
            var id = $(this).data('id');
            $.get(CFG.urls.get, { id: id }).done(function (res) {
                openEditor(res.issue);
            }).fail(function (xhr) {
                window.alert(errorText(xhr));
                load();
            });
        });

        function submit(overrideVersion) {
            if (state.uploading > 0) {
                showError('圖片還在上傳中，請稍候');
                return;
            }
            var data = {};
            $.each($form.serializeArray(), function (i, f) { data[f.name] = f.value; });
            if (overrideVersion) {
                data.version = overrideVersion;
            }
            data.image_ids = $.map(state.images, function (img) { return img.id; }).join(',');
            var $btn = $('#pi-save').prop('disabled', true);
            $.post(CFG.urls.save, data).done(function () {
                closeEditor(true);
                load();
            }).fail(function (xhr) {
                var r = xhr.responseJSON || {};
                if (xhr.status === 409 && r.conflict) {
                    var msg = r.error + '。\n\n按「確定」用你目前的內容覆蓋對方的修改；\n按「取消」放棄你的修改並載入最新內容。';
                    if (window.confirm(msg)) {
                        submit(r.issue.version);
                    } else {
                        openEditor(r.issue);
                    }
                } else if (xhr.status === 410) {
                    window.alert(r.error);
                    closeEditor(true);
                    load();
                } else {
                    showError(errorText(xhr));
                }
            }).always(function () {
                $btn.prop('disabled', false);
            });
        }

        $form.on('submit', function (e) {
            e.preventDefault();
            submit(0);
        });

        $('#pi-delete').on('click', function () {
            var id = $form.find('[name=id]').val();
            if (!window.confirm('確定刪除 ISSUE #' + id + '？')) {
                return;
            }
            $.post(CFG.urls['delete'], { id: id, version: $form.find('[name=version]').val() }).done(function () {
                closeEditor(true);
                load();
            }).fail(function (xhr) {
                var r = xhr.responseJSON || {};
                if (xhr.status === 409 && r.conflict) {
                    window.alert(r.error + '，已載入最新內容，請確認後再刪除');
                    openEditor(r.issue);
                } else {
                    showError(errorText(xhr));
                }
            });
        });

        // ---------------- 圖片：貼上 / 拖曳 / 選檔 ----------------
        function renderImages() {
            var html = '';
            $.each(state.images, function (i, img) {
                html += '<div class="pi-thumb" data-index="' + i + '">' +
                    '<a href="' + esc(img.url) + '" target="_blank" rel="noopener"><img src="' + esc(img.url) + '" alt="' + esc(img.name) + '"></a>' +
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
            var fd = new FormData();
            var ext = file.type === 'image/jpeg' ? 'jpg' : file.type.split('/')[1];
            fd.append('image', file, file.name || ('paste_' + new Date().getTime() + '.' + ext));
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
                showError(errorText(xhr, '圖片上傳失敗'));
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

        // 整個編輯視窗都能貼上（含說明欄），只攔截圖片，文字照常貼上
        $modal.on('paste', function (e) {
            var cd = e.originalEvent.clipboardData;
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
        $('#pi-file').on('change', function () {
            $.each(filesFrom(this.files), function (i, f) { upload(f); });
            $(this).val('');
        });
        $images.on('click', '.pi-thumb-del', function () {
            state.images.splice($(this).closest('.pi-thumb').data('index'), 1);
            state.dirty = true;
            renderImages();
        });

        // 離開頁面前提醒未儲存
        $(window).on('beforeunload', function () {
            if ($modal.is(':visible') && state.dirty) {
                return '內容尚未儲存';
            }
        });

        load();
        startPolling();
    });
})(window.jQuery);
