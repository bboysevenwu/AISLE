<?php
/**
 * 登入
 *   GET  顯示登入頁
 *   POST 驗證帳密；jQuery AJAX 送出回 JSON，未開 JavaScript 時走一般表單並導頁
 */
require dirname(__FILE__) . '/../core/bootstrap.php';

/** 登入後要去的頁面：首次登入或被重設密碼者先去改密碼 */
function after_login_url()
{
    $user = current_user();
    if ($user && $user['must_change_password']) {
        return url('profile');
    }
    return url(Acl::defaultPage());
}

/** 登入頁上方的提示訊息 */
function login_notice()
{
    if (isset($_GET['bye'])) {
        return '已安全登出';
    }
    if (isset($_GET['registered'])) {
        return $_GET['registered'] === 'pending' ? '註冊完成，帳號需管理員啟用後才能登入' : '註冊完成，請登入';
    }
    return '';
}

if (is_logged_in()) {
    header('Location: ' . after_login_url());
    exit;
}

$isAjax = App::isAjax();
$error = '';
$account = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $account = isset($_POST['account']) && is_string($_POST['account']) ? trim($_POST['account']) : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

        if (!Security::checkCsrf()) {
            $error = '頁面已過期，請重新整理後再試';
        } elseif ($account === '' || $password === '') {
            $error = '請輸入帳號與密碼';
        } else {
            $result = Auth::attempt($account, $password);
            if ($result['ok']) {
                if ($isAjax) {
                    json_response(array('ok' => true, 'redirect' => after_login_url()));
                } else {
                    header('Location: ' . after_login_url());
                }
                exit;
            }
            $error = $result['error'];
        }

        if ($isAjax) {
            // 以 200 + ok:false 回傳，401 保留給「Session 過期」
            json_response(array('ok' => false, 'error' => $error, 'csrf' => Security::csrfToken()));
            exit;
        }
    }
} catch (Exception $e) {
    error_log('[AISLE] login: ' . $e->getMessage());
    $error = '系統暫時無法登入，請稍後再試';
    if ($isAjax) {
        json_response(array('ok' => false, 'error' => $error), 500);
        exit;
    }
}

render_guest('auth/login', array(
    'error'       => $error,
    'notice'      => login_notice(),
    'account'     => $account,
    'canRegister' => Registration::isEnabled(),
), 'LOGIN');
