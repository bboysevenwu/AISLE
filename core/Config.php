<?php
/**
 * 讀取 INI 設定檔
 */
class Config
{
    private static $app = array();
    private static $permissions = array();

    public static function load($appIni, $permissionIni)
    {
        self::$app = self::parse($appIni);
        self::$permissions = self::parse($permissionIni);
    }

    private static function parse($file)
    {
        $data = parse_ini_file($file, true);
        if ($data === false) {
            throw new RuntimeException('INI 解析失敗：' . basename($file));
        }
        return $data;
    }

    public static function get($section, $key, $default = null)
    {
        if (isset(self::$app[$section]) && array_key_exists($key, self::$app[$section])) {
            return self::$app[$section][$key];
        }
        return $default;
    }

    public static function section($section)
    {
        return isset(self::$app[$section]) ? self::$app[$section] : array();
    }

    /** config/config.ini 的完整內容（身份、頁面、註冊設定） */
    public static function permissions()
    {
        return self::$permissions;
    }
}
