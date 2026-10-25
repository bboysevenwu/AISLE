<?php
/**
 * 登出：清除 Session 並導回登入頁
 * 只接受帶 CSRF token 的請求（POST 表單或 ?_csrf=），避免被其他網站的連結強制登出
 */
require dirname(__FILE__) . '/../core/bootstrap.php';

$token = isset($_GET['_csrf']) ? (string) $_GET['_csrf'] : '';
$valid = Security::checkCsrf() || ($token !== '' && Security::equals(Security::csrfToken(), $token));

if ($valid) {
    Auth::logout();
    header('Location: ' . page_url('login.php', array('bye' => 1)));
} else {
    // token 不符：不登出，回到原本的頁面
    header('Location: ' . (is_logged_in() ? url(Acl::defaultPage()) : page_url('login.php')));
}
exit;
