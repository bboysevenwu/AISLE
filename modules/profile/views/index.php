<?php if ($mustChange): ?>
    <div class="alert alert-error">&gt; 首次登入或密碼已重設，請先修改密碼才能使用其他功能。</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error">&gt; <?php echo e($error); ?></div>
<?php endif; ?>

<div class="panel" style="max-width:480px">
    <div class="panel-head">&gt; CHANGE PASSWORD</div>
    <div class="panel-body">
        <p class="dim">
            帳號：<?php echo e($user['account']); ?>
            身份：<span class="role-badge role-<?php echo e($user['role']); ?>"><?php echo e($user['role']); ?></span>
        </p>
        <form method="post" action="<?php echo e(url('profile')); ?>" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
            <label for="current">CURRENT PASSWORD</label>
            <input type="password" id="current" name="current" required>
            <label for="new">NEW PASSWORD</label>
            <input type="password" id="new" name="new" required>
            <label for="confirm">CONFIRM</label>
            <input type="password" id="confirm" name="confirm" required>
            <br><br>
            <button type="submit" class="btn">[ 更新密碼 ]</button>
        </form>
    </div>
</div>
