<?php
/**
 * 共用權限檢查：所有功能頁面都經過這裡，依 config/config.ini 判斷能否瀏覽
 *
 * 用法一：public/ 底下的獨立頁面（例如既有網站的舊頁面）在第一行加入
 *
 *     <?php require_once dirname(__FILE__) . '/../../core/auth_check.php';  // 依檔案位置調整 ../
 *
 *   會以「目前執行的檔案」對照 config.ini 各頁面的 route 找出頁面代號並檢查。
 *   通過後可直接使用 $AUTH_USER（登入者）與 $AUTH_PAGE（頁面設定）。
 *   沒有登記在 config.ini 的檔案一律拒絕，避免漏設權限的頁面被看到。
 *
 * 用法二：index.php 的路由（core/App.php）對 module 與 pages/ 頁面呼叫 auth_check()。
 *
 * 檢查順序：未登入 → 導向 login.php（AJAX 回 401）
 *           首次登入未改密碼 → 導向個人設定
 *           身份不在 [access] 清單或不符 action.* → 403
 */

if (!defined('AISLE_ROOT')) {
    require dirname(__FILE__) . '/bootstrap.php';
}

if (!function_exists('auth_check')) {
    /**
     * 檢查目前使用者能否使用某頁面的某動作；不通過時直接輸出並結束
     * @return array 登入者
     */
    function auth_check($page, $action = 'index')
    {
        $user = require_login();

        if (!Acl::pageExists($page)) {
            deny_access('此頁面未登記在 config.ini');
        }
        if ($user['must_change_password'] && $page !== 'profile') {
            header('Location: ' . url('profile'));
            exit;
        }
        if (!Acl::canAccess($user['role'], $page, $action)) {
            deny_access();
        }
        return $user;
    }

    /** 給獨立頁面用：由目前執行的檔案找出頁面代號後檢查 */
    function auth_check_current_script()
    {
        $script = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';
        $page = Acl::pageForScript($script);
        if ($page === null) {
            require_login();
            deny_access('此頁面未登記在 config.ini，無法存取');
        }

        $action = isset($_GET['a']) && is_string($_GET['a']) && preg_match('/^[a-z][a-zA-Z0-9_]{0,63}$/', $_GET['a'])
            ? $_GET['a'] : 'index';
        $user = auth_check($page, $action);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Acl::setting('direct_page_csrf', true) && !Security::checkCsrf()) {
            App::error(400, '表單已過期，請重新整理後再送出');
            exit;
        }
        return array($page, $user);
    }
}

// 被獨立頁面 require 時自動執行；由 index.php 路由載入時（AISLE_ROUTER）只提供函式
if (!defined('AISLE_ROUTER')) {
    list($AUTH_PAGE_KEY, $AUTH_USER) = auth_check_current_script();
    $AUTH_PAGE = Acl::page($AUTH_PAGE_KEY);
}
