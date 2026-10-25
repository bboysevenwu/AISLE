<?php
/**
 * 前端控制器路由：index.php?m=<頁面代號>&a=<動作>
 * 頁面代號、權限、程式位置全部來自 config/config.ini，新增頁面不需修改這裡
 */
class App
{
    public static function run()
    {
        if (!defined('AISLE_ROUTER')) {
            define('AISLE_ROUTER', true);
        }
        require_once AISLE_CORE . '/auth_check.php';

        try {
            self::dispatch();
        } catch (Exception $e) {
            error_log('[AISLE] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $message = Config::get('app', 'debug', 0) ? $e->getMessage() : '系統發生錯誤，請聯絡管理員';
            self::error(500, $message);
        }
    }

    private static function dispatch()
    {
        $page = isset($_GET['m']) ? (string) $_GET['m'] : '';
        $action = isset($_GET['a']) ? (string) $_GET['a'] : 'index';

        if ($page === '') {
            if (!Auth::check()) {
                header('Location: ' . page_url('login.php'));
                exit;
            }
            $page = Acl::defaultPage();
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $page) || !preg_match('/^[a-z][a-zA-Z0-9_]{0,63}$/', $action)) {
            return self::error(404, '找不到頁面');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Security::checkCsrf()) {
            return self::error(400, '表單已過期，請重新整理後再送出');
        }

        // 權限檢查（與獨立頁面共用同一套邏輯）
        $user = auth_check($page, $action);
        $info = Acl::page($page);

        if ($info['type'] === 'direct') {
            header('Location: ' . Acl::pageUrl($page));
            exit;
        }
        if ($info['type'] === 'file') {
            return self::renderFile($info, $user);
        }
        return self::runModule($info, $action, $user);
    }

    /** route = "module:<資料夾>"：載入 Controller 並執行 <動作>Action() */
    private static function runModule($info, $action, $user)
    {
        $dir = $info['target'];
        $file = AISLE_MODULES . '/' . $dir . '/controller.php';
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $dir) || !is_file($file)) {
            return self::error(404, '模組不存在：' . $dir);
        }
        require_once $file;

        $class = self::className($dir);
        if (!class_exists($class, false)) {
            return self::error(404, '模組類別不存在：' . $class);
        }
        // Controller 以頁面代號為 module，view 從模組資料夾讀取
        $controller = new $class($info['key'], $action, $user, $dir);
        $method = $action . 'Action';
        if (!is_callable(array($controller, $method))) {
            return self::error(404, '動作不存在：' . $action);
        }
        $controller->$method();
    }

    /** route = "pages/xxx.php"：在權限檢查後載入一般 PHP 頁面並套用版面 */
    private static function renderFile($info, $user)
    {
        $file = AISLE_ROOT . '/' . $info['target'];
        if (!is_file($file)) {
            return self::error(404, '頁面檔案不存在：' . $info['target']);
        }
        $content = View::fetch($file, array('user' => $user, 'page' => $info));
        echo View::fetch(AISLE_TEMPLATES . '/layout.php', array(
            'content' => $content,
            'title'   => $info['title'],
            'user'    => $user,
            'menu'    => Acl::menuFor($user['role']),
            'current' => $info['key'],
            'flash'   => null,
        ));
    }

    /** ee_equipment -> EeEquipmentController */
    public static function className($module)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $module))) . 'Controller';
    }

    public static function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    public static function error($code, $message)
    {
        http_response_code($code);
        if (self::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
            return;
        }
        $user = Auth::user();
        echo View::fetch(AISLE_TEMPLATES . '/layout.php', array(
            'content' => View::fetch(AISLE_TEMPLATES . '/error.php', array('code' => $code, 'message' => $message)),
            'title'   => 'ERROR ' . $code,
            'user'    => $user,
            'menu'    => $user ? Acl::menuFor($user['role']) : array(),
            'current' => '',
            'flash'   => null,
        ));
    }
}
