<?php
/**
 * password_hash() / password_verify() 相容層
 *
 * PHP 5.5 起才內建這組函式。這裡在函式不存在時，以 crypt() 的 $2y$ bcrypt
 * (PHP >= 5.3.7) 實作相同的 API 與相同的雜湊格式；PHP 5.5+ 會直接使用內建版本。
 * 因此程式碼一律照標準寫法呼叫 password_hash() / password_verify()，
 * 日後升級 PHP 時只要刪掉這個檔案，舊密碼仍可正常驗證。
 *
 * 依賴：Security::randomBytes()、Security::equals()
 */

if (!defined('PASSWORD_BCRYPT')) {
    define('PASSWORD_BCRYPT', 1);
    define('PASSWORD_DEFAULT', PASSWORD_BCRYPT);
}

if (!function_exists('password_hash')) {
    function password_hash($password, $algo, $options = array())
    {
        if ($algo !== PASSWORD_BCRYPT) {
            trigger_error('password_hash(): Unknown password hashing algorithm: ' . $algo, E_USER_WARNING);
            return null;
        }
        $cost = isset($options['cost']) ? (int) $options['cost'] : 10;
        if ($cost < 4 || $cost > 31) {
            trigger_error('password_hash(): Invalid bcrypt cost parameter specified: ' . $cost, E_USER_WARNING);
            return null;
        }

        // 22 字元 salt，bcrypt 使用自己的 base64 字母表
        $salt = strtr(
            rtrim(base64_encode(Security::randomBytes(16)), '='),
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
            './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
        );
        $hash = crypt((string) $password, sprintf('$2y$%02d$', $cost) . substr($salt, 0, 22));

        if (!is_string($hash) || strlen($hash) !== 60) {
            return false;
        }
        return $hash;
    }

    function password_verify($password, $hash)
    {
        $check = crypt((string) $password, (string) $hash);
        if (!is_string($check) || strlen($check) !== strlen($hash) || strlen($check) <= 13) {
            return false;
        }
        return Security::equals($hash, $check);
    }

    function password_needs_rehash($hash, $algo, $options = array())
    {
        $cost = isset($options['cost']) ? (int) $options['cost'] : 10;
        return $algo !== PASSWORD_BCRYPT || substr($hash, 0, 7) !== sprintf('$2y$%02d$', $cost);
    }

    function password_get_info($hash)
    {
        if (strlen($hash) === 60 && substr($hash, 0, 4) === '$2y$') {
            return array('algo' => PASSWORD_BCRYPT, 'algoName' => 'bcrypt', 'options' => array('cost' => (int) substr($hash, 4, 2)));
        }
        return array('algo' => 0, 'algoName' => 'unknown', 'options' => array());
    }
}
