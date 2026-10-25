<?php
/**
 * 註冊
 *   GET                       顯示註冊頁
 *   GET  ?check_account=xxx   (AJAX) 即時檢查帳號是否可用
 *   POST                      建立帳號；jQuery AJAX 送出回 JSON，未開 JavaScript 時走一般表單
 * 身份依 config/config.ini 的 [ee_accounts] 名單判定並寫入資料庫，見 core/Registration.php
 */
require dirname(__FILE__) . '/../core/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . url(Acl::defaultPage()));
    exit;
}

$isAjax = App::isAjax();

if (!Registration::isEnabled()) {
    if ($isAjax) {
        json_response(array('ok' => false, 'errors' => array('_' => '目前未開放註冊')), 403);
        exit;
    }
    App::error(403, '目前未開放註冊，請聯絡管理員');
    exit;
}

$errors = array();
$form = Registration::normalize(array());

try {
    // 即時檢查帳號
    if ($isAjax && isset($_GET['check_account'])) {
        $account = trim((string) $_GET['check_account']);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $account)) {
            json_response(array('ok' => false, 'message' => '格式不符'));
        } else {
            if (Registration::accountExists($account)) {
                json_response(array('ok' => false, 'message' => '已被使用'));
            } elseif (Registration::determineRole($account) === '') {
                json_response(array('ok' => false, 'message' => '不在可註冊名單中'));
            } else {
                json_response(array('ok' => true, 'message' => '可以使用'));
            }
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form = Registration::normalize($_POST);
        if (!Security::checkCsrf()) {
            $result = array('ok' => false, 'errors' => array('_' => '頁面已過期，請重新整理後再試'));
        } else {
            $result = Registration::register($_POST);
        }

        if ($result['ok']) {
            $roles = Acl::roles();
            $message = '註冊完成，你的身份為 ' . $result['role']
                . (isset($roles[$result['role']]) ? '（' . $roles[$result['role']] . '）' : '')
                . ($result['pending'] ? '。帳號需管理員啟用後才能登入。' : '。');
            if ($isAjax) {
                json_response(array('ok' => true, 'message' => $message, 'role' => $result['role'], 'redirect' => page_url('login.php', array('registered' => $result['pending'] ? 'pending' : 1))));
            } else {
                header('Location: ' . page_url('login.php', array('registered' => $result['pending'] ? 'pending' : 1)));
            }
            exit;
        }

        $errors = $result['errors'];
        if ($isAjax) {
            json_response(array('ok' => false, 'errors' => $errors));
            exit;
        }
    }
} catch (Exception $e) {
    error_log('[AISLE] register: ' . $e->getMessage());
    $errors = array('_' => '系統暫時無法註冊，請稍後再試');
    if ($isAjax) {
        json_response(array('ok' => false, 'errors' => $errors), 500);
        exit;
    }
}

render_guest('auth/register', array(
    'errors'   => $errors,
    'form'     => $form,
    'approval' => Registration::requiresApproval(),
), 'REGISTER');
