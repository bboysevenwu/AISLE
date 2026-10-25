<?php if ($error !== ''): ?>
    <div class="alert alert-error">&gt; <?php echo e($error); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">&gt; OPERATORS</div>
    <div class="panel-body">
        <table class="table">
            <thead>
                <tr><th>ID</th><th>ACCOUNT</th><th>NAME</th><th>ROLE</th><th>STATUS</th><th>LAST LOGIN</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo (int) $u['id']; ?></td>
                    <td><?php echo e($u['account']); ?></td>
                    <td><?php echo e($u['display_name']); ?></td>
                    <td><span class="role-badge role-<?php echo e($u['role']); ?>"><?php echo e($u['role']); ?></span></td>
                    <td>
                        <?php if (!$u['is_active']): ?>
                            <span class="led led-down"></span>停用
                        <?php elseif ($u['locked_until'] !== null && strtotime($u['locked_until']) > time()): ?>
                            <span class="led led-idle"></span>鎖定中
                        <?php else: ?>
                            <span class="led led-run"></span>啟用
                        <?php endif; ?>
                    </td>
                    <td class="dim"><?php echo e($u['last_login_at'] ? $u['last_login_at'] : '-'); ?></td>
                    <td>
                        <?php if ((int) $u['id'] !== $user['id']): ?>
                        <form method="post" action="<?php echo e(url('admin_users', 'toggle')); ?>" class="inline">
                            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                            <button class="btn btn-sm"><?php echo $u['is_active'] ? '停用' : '啟用'; ?></button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo e(url('admin_users', 'reset')); ?>" class="inline">
                            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                            <button class="btn btn-sm btn-red" data-confirm="確定重設 <?php echo e($u['account']); ?> 的密碼？">重設密碼</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="max-width:480px">
    <div class="panel-head">&gt; NEW OPERATOR</div>
    <div class="panel-body">
        <form method="post" action="<?php echo e(url('admin_users')); ?>" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
            <label for="account">ACCOUNT</label>
            <input type="text" id="account" name="account" value="<?php echo e($form['account']); ?>" required>
            <label for="display_name">DISPLAY NAME</label>
            <input type="text" id="display_name" name="display_name" value="<?php echo e($form['display_name']); ?>">
            <label for="email">EMAIL</label>
            <input type="email" id="email" name="email" value="<?php echo e($form['email']); ?>">
            <label for="role">ROLE</label>
            <select id="role" name="role">
            <?php foreach ($roles as $key => $label): ?>
                <option value="<?php echo e($key); ?>"<?php echo $form['role'] === $key ? ' selected' : ''; ?>>
                    <?php echo e($key . ' - ' . $label); ?>
                </option>
            <?php endforeach; ?>
            </select>
            <label for="password">INITIAL PASSWORD</label>
            <input type="password" id="password" name="password" required>
            <p class="dim">&gt; 使用者首次登入會被要求修改密碼</p>
            <button type="submit" class="btn">[ 建立 ]</button>
        </form>
    </div>
</div>
