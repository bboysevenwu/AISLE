<?php
/**
 * 亂數、常數時間比較、CSRF
 * PHP 5.4 沒有 random_bytes() / hash_equals()，在此自行實作
 */
class Security
{
    /** 取得密碼學安全亂數 */
    public static function randomBytes($length)
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($bytes !== false && $strong) {
                return $bytes;
            }
        }
        if (function_exists('mcrypt_create_iv')) {
            $bytes = mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);
            if ($bytes !== false && strlen($bytes) === $length) {
                return $bytes;
            }
        }
        if (is_readable('/dev/urandom')) {
            $fp = fopen('/dev/urandom', 'rb');
            $bytes = fread($fp, $length);
            fclose($fp);
            if (strlen($bytes) === $length) {
                return $bytes;
            }
        }
        throw new RuntimeException('找不到安全的亂數來源 (openssl / mcrypt / urandom)');
    }

    /** 常數時間字串比較，避免 timing attack */
    public static function equals($known, $user)
    {
        $known = (string) $known;
        $user = (string) $user;
        if (strlen($known) !== strlen($user)) {
            return false;
        }
        $diff = 0;
        $len = strlen($known);
        for ($i = 0; $i < $len; $i++) {
            $diff |= ord($known[$i]) ^ ord($user[$i]);
        }
        return $diff === 0;
    }

    public static function csrfToken()
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(self::randomBytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** 驗證 POST 欄位 _csrf 或 jQuery 送出的 X-CSRF-Token 標頭 */
    public static function checkCsrf()
    {
        $sent = '';
        if (isset($_POST['_csrf'])) {
            $sent = $_POST['_csrf'];
        } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $sent = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        return $sent !== '' && self::equals(self::csrfToken(), $sent);
    }
}
