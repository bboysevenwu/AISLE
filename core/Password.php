<?php
/**
 * 密碼工具：包裝 password_hash() / password_verify()
 *
 * PHP 5.4 沒有這組函式，由 core/password_compat.php 補上（同樣是 $2y$ bcrypt），
 * 因此這裡可以直接用標準 API；成本參數統一讀 app.ini 的 [security] bcrypt_cost。
 */
class Password
{
    const DEFAULT_COST = 10;

    private static function cost($cost)
    {
        $cost = $cost === null ? (int) Config::get('security', 'bcrypt_cost', self::DEFAULT_COST) : (int) $cost;
        return ($cost < 4 || $cost > 31) ? self::DEFAULT_COST : $cost;
    }

    public static function hash($password, $cost = null)
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, array('cost' => self::cost($cost)));
        if (!is_string($hash) || strlen($hash) !== 60) {
            throw new RuntimeException('bcrypt 雜湊失敗，請確認 PHP >= 5.3.7');
        }
        return $hash;
    }

    public static function verify($password, $hash)
    {
        return password_verify($password, $hash);
    }

    /** 成本調高後，登入成功時自動以新成本重新雜湊 */
    public static function needsRehash($hash, $cost = null)
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, array('cost' => self::cost($cost)));
    }

    /** 密碼強度：至少 8 碼，含英文與數字 */
    public static function isStrong($password)
    {
        return strlen($password) >= 8
            && preg_match('/[A-Za-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }
}
