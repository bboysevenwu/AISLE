<?php
/** 伺服器端錯誤訊息（未開 JavaScript 或 AJAX 以外的情況） */
$err = function ($field) use ($errors) {
    return isset($errors[$field]) ? e($errors[$field]) : '';
};
?>
<div class="login-box register-box">
    <div class="logo glitch" data-text="AISLE">AISLE</div>
    <div class="tagline">// NEW OPERATOR ENROLLMENT</div>

    <div class="panel terminal">
        <div class="panel-head">&gt; REGISTER</div>
        <div class="panel-body">
            <div class="alert" id="form-success" style="display:none">&gt; <span></span></div>
            <div class="alert alert-error" id="form-error"<?php echo isset($errors['_']) ? '' : ' style="display:none"'; ?>>&gt; <span><?php echo $err('_'); ?></span></div>

            <form method="post" action="<?php echo e(page_url('register.php')); ?>" id="register-form" class="js-validate" autocomplete="off" novalidate>
                <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">

                <label for="account">ACCOUNT <span class="hint" id="account-status"></span></label>
                <input type="text" id="account" name="account" value="<?php echo e($form['account']); ?>" maxlength="64"
                       data-rule="required account" data-label="帳號" data-check-url="<?php echo e(page_url('register.php')); ?>" autofocus>
                <div class="field-error" data-for="account"><?php echo $err('account'); ?></div>

                <label for="display_name">DISPLAY NAME</label>
                <input type="text" id="display_name" name="display_name" value="<?php echo e($form['display_name']); ?>" maxlength="100"
                       data-rule="required" data-label="顯示名稱">
                <div class="field-error" data-for="display_name"><?php echo $err('display_name'); ?></div>

                <label for="password">PASSWORD <span class="hint" id="strength"></span></label>
                <input type="password" id="password" name="password" data-rule="required password" data-label="密碼">
                <div class="meter"><span id="strength-bar"></span></div>
                <div class="field-error" data-for="password"><?php echo $err('password'); ?></div>

                <label for="confirm">CONFIRM PASSWORD</label>
                <input type="password" id="confirm" name="confirm" data-rule="required match:password" data-label="確認密碼">
                <div class="field-error" data-for="confirm"><?php echo $err('confirm'); ?></div>

                <p class="dim">&gt; 身份 (EE / PE) 由系統依帳號名單自動判定<?php echo $approval ? '，註冊後需管理員啟用' : ''; ?>。</p>
                <button type="submit" class="btn">[ ENROLL ]</button>
            </form>

            <p class="dim form-foot">&gt; 已有帳號？<a href="<?php echo e(page_url('login.php')); ?>">[ 登入 ]</a></p>
        </div>
    </div>
</div>
<script src="<?php echo e(asset('js/auth.js')); ?>"></script>
<script>
$(function () {
    AISLE.auth.bindRegister($('#register-form'));
});
</script>
