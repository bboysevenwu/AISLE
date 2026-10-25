<?php
/**
 * 登入、登出、Session 使用者
 */
class Auth
{
    /**
     * 嘗試登入
     * @return array array('ok' => bool, 'error' => string)
     */
    public static function attempt($account, $password)
    {
        $maxFailed = (int) Config::get('security', 'max_failed_login', 5);
        $lockMinutes = (int) Config::get('security', 'lock_minutes', 15);

        $user = Database::fetchOne('SELECT * FROM users WHERE account = ?', array($account));

        if (!$user) {
            // 帳號不存在也跑一次 bcrypt，避免從回應時間推測帳號是否存在
            Password::verify($password, '$2y$10$14rWdTIY9oZc4gJmmfm3.eOrHFf02VHMNdNald7tXi7xkzUKqFJI6');
            self::log(null, $account, false, 'no_user');
            return array('ok' => false, 'error' => 'ACCESS DENIED：帳號或密碼錯誤');
        }
        if (!$user['is_active']) {
            self::log($user['id'], $account, false, 'inactive');
            return array('ok' => false, 'error' => 'ACCESS DENIED：帳號尚未啟用或已停用，請聯絡管理員');
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            self::log($user['id'], $account, false, 'locked');
            return array('ok' => false, 'error' => 'ACCESS DENIED：帳號暫時鎖定，請稍後再試');
        }
        if (!Password::verify($password, $user['password_hash'])) {
            $failed = (int) $user['failed_attempts'] + 1;
            $lockedUntil = $failed >= $maxFailed ? date('Y-m-d H:i:s', time() + $lockMinutes * 60) : null;
            Database::execute(
                'UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?',
                array($lockedUntil ? 0 : $failed, $lockedUntil, $user['id'])
            );
            self::log($user['id'], $account, false, 'bad_password');
            return array('ok' => false, 'error' => 'ACCESS DENIED：帳號或密碼錯誤');
        }
        if (!Acl::roleExists($user['role'])) {
            self::log($user['id'], $account, false, 'bad_role');
            return array('ok' => false, 'error' => 'ACCESS DENIED：角色未設定，請聯絡管理員');
        }

        // 登入成功
        $hash = $user['password_hash'];
        if (Password::needsRehash($hash)) {
            $hash = Password::hash($password);
        }
        Database::execute(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ?, password_hash = ? WHERE id = ?',
            array(now(), client_ip(), $hash, $user['id'])
        );
        self::log($user['id'], $account, true, 'ok');

        session_regenerate_id(true);
        $_SESSION['user'] = array(
            'id'                   => (int) $user['id'],
            'account'              => $user['account'],
            'display_name'         => $user['display_name'] !== '' ? $user['display_name'] : $user['account'],
            'role'                 => $user['role'],
            'must_change_password' => (bool) $user['must_change_password'],
        );
        $_SESSION['last_seen'] = time();
        return array('ok' => true, 'error' => '');
    }

    /** 取得目前登入者；未登入或閒置過久回傳 null */
    public static function user()
    {
        if (empty($_SESSION['user'])) {
            return null;
        }
        $idle = (int) Config::get('security', 'session_idle', 1800);
        if ($idle > 0 && isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
            self::logout();
            return null;
        }
        $_SESSION['last_seen'] = time();
        return $_SESSION['user'];
    }

    public static function check()
    {
        return self::user() !== null;
    }

    public static function passwordChanged()
    {
        if (!empty($_SESSION['user'])) {
            $_SESSION['user']['must_change_password'] = false;
        }
    }

    public static function logout()
    {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function log($userId, $account, $success, $reason)
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
        Database::execute(
            'INSERT INTO login_logs (user_id, account, success, reason, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            array($userId, substr($account, 0, 64), $success ? 1 : 0, $reason, client_ip(), $ua, now())
        );
    }
}
