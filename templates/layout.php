<?php
/**
 * 共用版面
 * 可用變數：$content $title $user $menu $current $flash
 */
?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?php echo e(Security::csrfToken()); ?>">
<meta name="login-url" content="<?php echo e(page_url('login.php')); ?>">
<title><?php echo e($title); ?> :: <?php echo e(Config::get('app', 'name', 'AISLE')); ?></title>
<link rel="stylesheet" href="<?php echo e(asset('css/matrix.css')); ?>">
<script src="<?php echo e(asset('vendor/jquery-1.12.4.min.js')); ?>"></script>
</head>
<body class="<?php echo $user ? 'has-user' : 'is-guest'; ?>">
<canvas id="matrix-rain" aria-hidden="true"></canvas>
<div class="crt-overlay" aria-hidden="true"></div>

<?php if ($user): ?>
<header class="topbar">
    <a class="brand" href="<?php echo e(url(Acl::defaultPage())); ?>">
        <?php echo e(Config::get('app', 'name', 'AISLE')); ?><span class="cursor">_</span>
    </a>
    <div class="topbar-status">
        <span class="hide-sm">OPERATOR:</span>
        <b><?php echo e($user['display_name']); ?></b>
        <span class="role-badge role-<?php echo e($user['role']); ?>"><?php echo e($user['role']); ?></span>
        <span id="clock" class="hide-sm"></span>
        <form method="post" action="<?php echo e(page_url('logout.php')); ?>" class="inline">
            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
            <button type="submit" class="btn btn-ghost btn-sm">[ 登出 ]</button>
        </form>
    </div>
</header>

<div class="shell">
    <nav class="sidebar">
        <div class="nav-title">// MODULES</div>
        <ul>
        <?php foreach ($menu as $item): ?>
            <li class="<?php echo $item['key'] === $current ? 'active' : ''; ?>">
                <a href="<?php echo e($item['url']); ?>">
                    <span class="nav-icon"><?php echo e($item['icon']); ?></span><?php echo e($item['title']); ?>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    </nav>

    <main class="main">
        <h1 class="page-title glitch" data-text="<?php echo e($title); ?>"><?php echo e($title); ?></h1>
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">&gt; <?php echo e($flash['message']); ?></div>
        <?php endif; ?>
        <?php echo $content; ?>
    </main>
</div>
<?php else: ?>
<main class="guest">
    <?php echo $content; ?>
</main>
<?php endif; ?>

<script src="<?php echo e(asset('js/matrix-rain.js')); ?>"></script>
<script src="<?php echo e(asset('js/app.js')); ?>"></script>
</body>
</html>
