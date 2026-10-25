<div class="login-box">
    <div class="logo glitch" data-text="AISLE">AISLE</div>
    <div class="tagline" id="tagline"></div>

    <div class="panel terminal">
        <div class="panel-head">&gt; AUTHENTICATION REQUIRED</div>
        <div class="panel-body">
            <?php if ($notice !== ''): ?>
                <div class="alert">&gt; <?php echo e($notice); ?></div>
            <?php endif; ?>
            <div class="alert alert-error" id="form-error"<?php echo $error === '' ? ' style="display:none"' : ''; ?>>&gt; <span><?php echo e($error); ?></span></div>

            <form method="post" action="<?php echo e(page_url('login.php')); ?>" id="login-form" class="js-validate" autocomplete="off" novalidate>
                <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">

                <label for="account">USER ID</label>
                <input type="text" id="account" name="account" value="<?php echo e($account); ?>" maxlength="64"
                       data-rule="required" data-label="帳號" autofocus>
                <div class="field-error" data-for="account"></div>

                <label for="password">PASSWORD</label>
                <input type="password" id="password" name="password" data-rule="required" data-label="密碼">
                <div class="field-error" data-for="password"></div>

                <button type="submit" class="btn">[ JACK IN ]</button>
            </form>

            <?php if ($canRegister): ?>
                <p class="dim form-foot">&gt; 還沒有帳號？<a href="<?php echo e(page_url('register.php')); ?>">[ 註冊 ]</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="<?php echo e(asset('js/auth.js')); ?>"></script>
<script>
$(function () {
    AISLE.typewriter($('#tagline'), ['Wake up, operator... the AISLE has you.'], 45);
    AISLE.auth.bindLogin($('#login-form'));
});
</script>
