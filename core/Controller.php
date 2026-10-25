<?php
/**
 * 所有模組 Controller 的基底類別
 *
 * 模組類別命名：資料夾 ee_equipment -> EeEquipmentController
 * $this->module 是 config.ini 的頁面代號，$this->dir 是模組資料夾（route = "module:<資料夾>"）
 * 動作方法命名：?a=status -> statusAction()
 */
abstract class Controller
{
    protected $module;
    protected $action;
    protected $user;
    protected $dir;

    public function __construct($module, $action, $user, $dir = null)
    {
        $this->module = $module;
        $this->action = $action;
        $this->user = $user;
        $this->dir = $dir === null ? $module : $dir;
    }

    /** 以 layout 包住模組 view 輸出 */
    protected function render($view, $vars = array())
    {
        $vars['user'] = $this->user;
        $vars['module'] = $this->module;
        $content = View::fetch(AISLE_MODULES . '/' . $this->dir . '/views/' . $view . '.php', $vars);

        $meta = Acl::page($this->module);
        echo View::fetch(AISLE_TEMPLATES . '/layout.php', array(
            'content' => $content,
            'title'   => isset($vars['title']) ? $vars['title'] : ($meta ? $meta['title'] : ''),
            'user'    => $this->user,
            'menu'    => $this->user ? Acl::menuFor($this->user['role']) : array(),
            'current' => $this->module,
            'flash'   => $this->takeFlash(),
        ));
    }

    protected function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }

    /** 目前使用者是否可執行本模組的某個動作（view 裡用來隱藏按鈕） */
    protected function can($action)
    {
        return $this->user && Acl::canAccess($this->user['role'], $this->module, $action);
    }

    protected function isPost()
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    protected function input($key, $default = '')
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
    }

    protected function query($key, $default = '')
    {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
    }

    protected function flash($message, $type = 'ok')
    {
        $_SESSION['_flash'] = array('message' => $message, 'type' => $type);
    }

    private function takeFlash()
    {
        if (empty($_SESSION['_flash'])) {
            return null;
        }
        $flash = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $flash;
    }
}
