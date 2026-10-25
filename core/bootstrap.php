<?php
/**
 * AISLE 啟動檔
 * 相容 PHP 5.4：不使用 type hint、??、finally、::class、password_hash 等 5.5+ 功能
 */

define('AISLE_ROOT', dirname(dirname(__FILE__)));
define('AISLE_CORE', AISLE_ROOT . '/core');
define('AISLE_MODULES', AISLE_ROOT . '/modules');
define('AISLE_TEMPLATES', AISLE_ROOT . '/templates');
define('AISLE_STORAGE', AISLE_ROOT . '/storage');

require AISLE_CORE . '/helpers.php';
require AISLE_CORE . '/Config.php';
require AISLE_CORE . '/Security.php';
require AISLE_CORE . '/password_compat.php';
require AISLE_CORE . '/Password.php';
require AISLE_CORE . '/Database.php';
require AISLE_CORE . '/Acl.php';
require AISLE_CORE . '/Auth.php';
require AISLE_CORE . '/Registration.php';
require AISLE_CORE . '/View.php';
require AISLE_CORE . '/Controller.php';
require AISLE_CORE . '/App.php';
require AISLE_CORE . '/guard.php';

$appIni = AISLE_ROOT . '/config/app.ini';
if (!is_file($appIni)) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('config/app.ini not found. Copy config/app.ini.example to config/app.ini first.');
}

Config::load($appIni, AISLE_ROOT . '/config/config.ini');
Acl::init(Config::permissions());

date_default_timezone_set(Config::get('app', 'timezone', 'Asia/Taipei'));

if (Config::get('app', 'debug', 0)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', AISLE_STORAGE . '/logs/php_error.log');
}

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    if (Config::get('security', 'cookie_secure', 0)) {
        ini_set('session.cookie_secure', '1');
    }
    session_name('AISLESESSID');
    session_start();
}
