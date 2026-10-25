<?php
/**
 * 共用權限檢查函式
 *
 * 登入成功後 Session 內容（由 Auth::attempt() 寫入）：
 *   $_SESSION['user'] = array(
 *       'id' => 1, 'account' => 'neo', 'display_name' => 'Neo',
 *       'role' => 'EE', 'must_change_password' => false,
 *   );
 *
 * 一般功能頁面請用 core/auth_check.php（依 config.ini 的 [access] 檢查）；
 * 以下函式用於頁面內更細的判斷。
 *
 * 用法：
 *   require_login();                      // 未登入 → 導向 login.php（AJAX 回 401）
 *   require_role('PE');                   // 只有 PE（ADMIN 一律通過）
 *   require_role('EE,PE');                // 多個身份用逗號
 *   if (has_role('EE,PE')) { ... }        // 只判斷不擋
 *   if (can_access('shared_report', 'export')) { ... }   // 依模組設定判斷
 */

/** 目前登入者陣列，未登入回傳 null */
function current_user()
{
    return Auth::user();
}

/** 目前登入者的身份，未登入回傳 '' */
function current_role()
{
    $user = Auth::user();
    return $user ? $user['role'] : '';
}

/** 是否已登入 */
function is_logged_in()
{
    return Auth::check();
}

/**
 * 目前使用者是否具有指定身份
 * @param string|array $roles 身份清單 "EE,PE" 或 array('EE','PE')
 */
function has_role($roles)
{
    $role = current_role();
    return $role !== '' && Acl::roleMatches($role, $roles);
}

/** 目前使用者能否使用某頁面 / 動作（依 config.ini 的 [access] 與 [page.xxx]） */
function can_access($module, $action = 'index')
{
    $role = current_role();
    return $role !== '' && Acl::canAccess($role, $module, $action);
}

/** 未登入則中止：一般請求導向登入頁，AJAX 回 401 JSON */
function require_login()
{
    if (is_logged_in()) {
        return current_user();
    }
    if (App::isAjax()) {
        json_response(array('ok' => false, 'error' => 'SESSION EXPIRED'), 401);
        exit;
    }
    header('Location: ' . page_url('login.php'));
    exit;
}

/** 未登入或身份不符則中止（403） */
function require_role($roles)
{
    $user = require_login();
    if (!has_role($roles)) {
        deny_access();
    }
    return $user;
}

/** 輸出 403 並中止 */
function deny_access($message = 'ACCESS DENIED：你的身份無權限使用此功能')
{
    App::error(403, $message);
    exit;
}

/** 輸出 JSON */
function json_response($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

/** 以訪客版面（無選單）輸出 templates/ 底下的頁面 */
function render_guest($template, $vars = array(), $title = '')
{
    echo View::fetch(AISLE_TEMPLATES . '/layout.php', array(
        'content' => View::fetch(AISLE_TEMPLATES . '/' . $template . '.php', $vars),
        'title'   => $title,
        'user'    => null,
        'menu'    => array(),
        'current' => '',
        'flash'   => null,
    ));
}

/** 表單用的 CSRF 隱藏欄位：<form method="post"><?php echo csrf_field(); ?> ... */
function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . e(Security::csrfToken()) . '">';
}

/**
 * 讓獨立頁面套用系統版面（選用）：
 *   layout_start();  ...頁面原本輸出的 HTML...  layout_end('標題');
 */
function layout_start()
{
    ob_start();
}

function layout_end($title = '')
{
    $content = ob_get_clean();
    $user = current_user();
    $page = isset($GLOBALS['AUTH_PAGE']) ? $GLOBALS['AUTH_PAGE'] : null;
    echo View::fetch(AISLE_TEMPLATES . '/layout.php', array(
        'content' => $content,
        'title'   => $title !== '' ? $title : ($page ? $page['title'] : ''),
        'user'    => $user,
        'menu'    => $user ? Acl::menuFor($user['role']) : array(),
        'current' => $page ? $page['key'] : '',
        'flash'   => null,
    ));
}
