<?php
/**
 * PDO 連線（單例）與查詢捷徑
 * 一律使用 prepared statement，不要把使用者輸入串進 SQL 字串
 */
class Database
{
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $c = Config::section('database');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                array_get($c, 'host', '127.0.0.1'),
                (int) array_get($c, 'port', 3306),
                array_get($c, 'name', 'aisle'),
                array_get($c, 'charset', 'utf8mb4')
            );
            self::$pdo = new PDO($dsn, array_get($c, 'user', ''), array_get($c, 'password', ''), array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        }
        return self::$pdo;
    }

    /** 執行查詢並回傳 PDOStatement */
    public static function query($sql, $params = array())
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchOne($sql, $params = array())
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll($sql, $params = array())
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** INSERT / UPDATE / DELETE，回傳影響筆數 */
    public static function execute($sql, $params = array())
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastInsertId()
    {
        return self::pdo()->lastInsertId();
    }
}
