/*
 * 登入 / 註冊：jQuery 前端驗證與非同步送出
 * 前端驗證只為了即時回饋，伺服器端 (core/Registration.php、Auth.php) 仍會完整驗證
 *
 * 欄位以 data-rule 宣告規則（空白分隔）：
 *   required        必填
 *   account         3~64 字元英數字、底線、點、減號
 *   email           Email 格式
 *   password        至少 8 碼且含英文與數字
 *   match:<欄位>    必須與另一欄位相同
 */
(function ($) {
    var RE_ACCOUNT = /^[A-Za-z0-9_.\-]{3,64}$/;
    var RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function validateField($input) {
        var rules = ($input.data('rule') || '').split(/\s+/);
        var label = $input.data('label') || $input.attr('name');
        var val = $.trim($input.val());
        var raw = $input.val();
        var $form = $input.closest('form');

        for (var i = 0; i < rules.length; i++) {
            var rule = rules[i];
            if (rule === 'required' && val === '') {
                return '請輸入' + label;
            }
            if (val === '') {
                continue;
            }
            if (rule === 'account' && !RE_ACCOUNT.test(val)) {
                return '帳號需為 3~64 字元的英數字、底線、點或減號';
            }
            if (rule === 'email' && !RE_EMAIL.test(val)) {
                return 'Email 格式不正確';
            }
            if (rule === 'password' && (raw.length < 8 || !/[A-Za-z]/.test(raw) || !/[0-9]/.test(raw))) {
                return '密碼至少 8 碼，且需包含英文與數字';
            }
            if (rule.indexOf('match:') === 0) {
                var other = $form.find('[name="' + rule.substring(6) + '"]').val();
                if (raw !== other) {
                    return '兩次輸入的密碼不一致';
                }
            }
        }
        return '';
    }

    function setFieldError($form, name, message) {
        var $input = $form.find('[name="' + name + '"]');
        $form.find('.field-error[data-for="' + name + '"]').text(message ? '> ' + message : '');
        $input.toggleClass('is-invalid', !!message).toggleClass('is-valid', !message && $.trim($input.val()) !== '');
    }

    function showFormError($form, message) {
        var $box = $form.closest('.panel-body').find('#form-error');
        if (message) {
            $box.find('span').text(message);
            $box.show();
        } else {
            $box.hide();
        }
    }

    /** 驗證整張表單，回傳是否通過 */
    function validateForm($form) {
        var ok = true;
        var $first = null;
        $form.find('[data-rule]').each(function () {
            var $input = $(this);
            var msg = validateField($input);
            setFieldError($form, $input.attr('name'), msg);
            if (msg) {
                ok = false;
                $first = $first || $input;
            }
        });
        if ($first) {
            $first.focus();
        }
        return ok;
    }

    function setBusy($form, busy, text) {
        var $btn = $form.find('button[type=submit]');
        if (busy) {
            $btn.data('label', $btn.text()).text(text).prop('disabled', true);
        } else {
            $btn.text($btn.data('label') || $btn.text()).prop('disabled', false);
        }
    }

    /** 更新表單與 meta 的 CSRF token（登入失敗時伺服器會回傳最新值） */
    function refreshCsrf($form, token) {
        if (!token) {
            return;
        }
        $form.find('[name=_csrf]').val(token);
        $('meta[name="csrf-token"]').attr('content', token);
        $.ajaxSetup({ headers: { 'X-CSRF-Token': token } });
    }

    function liveValidate($form) {
        $form.on('blur', '[data-rule]', function () {
            setFieldError($form, this.name, validateField($(this)));
        });
        $form.on('input', '.is-invalid', function () {
            setFieldError($form, this.name, validateField($(this)));
        });
    }

    var auth = {
        bindLogin: function ($form) {
            liveValidate($form);
            $form.on('submit', function (e) {
                e.preventDefault();
                showFormError($form, '');
                // 隱藏「已登出」「註冊完成」等舊提示
                $form.closest('.panel-body').find('.alert').not('#form-error').hide();
                if (!validateForm($form)) {
                    return;
                }
                setBusy($form, true, '[ CONNECTING... ]');
                $.ajax({ url: $form.attr('action'), type: 'POST', data: $form.serialize() })
                    .done(function (res) {
                        if (res.ok) {
                            $form.find('button[type=submit]').text('[ ACCESS GRANTED ]');
                            window.location.href = res.redirect;
                            return;
                        }
                        refreshCsrf($form, res.csrf);
                        showFormError($form, res.error);
                        $form.find('[name=password]').val('').focus();
                        setBusy($form, false);
                    })
                    .fail(function (xhr) {
                        var res = xhr.responseJSON || {};
                        showFormError($form, res.error || '連線失敗，請稍後再試');
                        setBusy($form, false);
                    });
            });
        },

        bindRegister: function ($form) {
            liveValidate($form);

            // 帳號即時檢查（停止輸入 400ms 後查詢）
            var timer = null;
            var lastChecked = '';
            var $status = $('#account-status');
            $form.find('[name=account]').on('input', function () {
                var $input = $(this);
                var val = $.trim($input.val());
                clearTimeout(timer);
                $status.text('').removeClass('text-red');
                if (!RE_ACCOUNT.test(val) || val === lastChecked) {
                    return;
                }
                timer = setTimeout(function () {
                    lastChecked = val;
                    $.get($input.data('check-url'), { check_account: val }).done(function (res) {
                        if ($.trim($input.val()) !== val || $form.data('done')) {
                            return; // 使用者已繼續輸入或已註冊完成，忽略舊結果
                        }
                        $status.text('[' + res.message + ']').toggleClass('text-red', !res.ok);
                        if (!res.ok) {
                            setFieldError($form, 'account', '此帳號' + res.message);
                        }
                    });
                }, 400);
            });

            // 密碼強度條
            $form.find('[name=password]').on('input', function () {
                var v = $(this).val();
                var score = 0;
                if (v.length >= 8) { score++; }
                if (v.length >= 12) { score++; }
                if (/[A-Za-z]/.test(v) && /[0-9]/.test(v)) { score++; }
                if (/[^A-Za-z0-9]/.test(v)) { score++; }
                var labels = ['WEAK', 'WEAK', 'FAIR', 'GOOD', 'STRONG'];
                $('#strength').text(v ? '[' + labels[score] + ']' : '');
                $('#strength-bar').css('width', (score * 25) + '%').toggleClass('weak', score < 2);
            });

            $form.on('submit', function (e) {
                e.preventDefault();
                showFormError($form, '');
                if (!validateForm($form)) {
                    return;
                }
                setBusy($form, true, '[ UPLOADING... ]');
                $.ajax({ url: $form.attr('action'), type: 'POST', data: $form.serialize() })
                    .done(function (res) {
                        if (res.ok) {
                            clearTimeout(timer);
                            $form.data('done', true);
                            $status.text('');
                            var $ok = $('#form-success');
                            $ok.find('span').text(res.message);
                            $ok.show();
                            $form.find('input, select, button').prop('disabled', true);
                            setTimeout(function () { window.location.href = res.redirect; }, 2500);
                            return;
                        }
                        $.each(res.errors || {}, function (field, msg) {
                            if (field === '_') {
                                showFormError($form, msg);
                            } else {
                                setFieldError($form, field, msg);
                            }
                        });
                        setBusy($form, false);
                    })
                    .fail(function (xhr) {
                        var res = xhr.responseJSON || {};
                        showFormError($form, (res.errors && res.errors._) || '連線失敗，請稍後再試');
                        setBusy($form, false);
                    });
            });
        }
    };

    window.AISLE = window.AISLE || {};
    window.AISLE.auth = auth;
})(window.jQuery);
