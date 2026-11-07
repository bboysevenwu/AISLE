<?php
/**
 * 全域小工具函式
 */

/** HTML 輸出跳脫，view 中一律用 e() 輸出變數 */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** 把 PHP 值安全地放進 <script> 內：var name = <?php echo js($name); ?>; */
function js($value)
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * 網站對外的路徑前綴（app.ini 的 base_url，去掉結尾的 /）
 * 經過 proxy 時要包含 proxy 的前綴，例如 "/D1C1/aisle"
 */
function base_url()
{
    return rtrim((string) Config::get('app', 'base_url', ''), '/');
}

/** 產生站內網址：url('ee_equipment', 'status', array('id' => 3)) */
function url($module, $action = 'index', $params = array())
{
    $query = array('m' => $module);
    if ($action !== 'index') {
        $query['a'] = $action;
    }
    $query = array_merge($query, $params);
    return base_url() . '/index.php?' . http_build_query($query);
}

/** public/ 底下獨立頁面的網址：page_url('login.php') */
function page_url($file, $params = array())
{
    $url = base_url() . '/' . ltrim($file, '/');
    return $params ? $url . '?' . http_build_query($params) : $url;
}

/** 靜態資源網址 */
function asset($path)
{
    return base_url() . '/assets/' . ltrim($path, '/');
}

/** 取得陣列值，取代 PHP 7 的 ?? 運算子 */
function array_get($array, $key, $default = null)
{
    return (is_array($array) && array_key_exists($key, $array)) ? $array[$key] : $default;
}

/** 取得用戶端 IP（若在反向代理後方，請依環境調整） */
function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/** 目前時間字串，寫入 DATETIME 欄位用 */
function now()
{
    return date('Y-m-d H:i:s');
}

/** 寫入稽核紀錄 */
function audit($module, $action, $detail = array())
{
    $user = Auth::user();
    Database::execute(
        'INSERT INTO audit_logs (user_id, module, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        array($user ? $user['id'] : null, $module, $action, json_encode($detail), client_ip(), now())
    );
}

/**
 * 目前的畫面風格：dark（駭客任務，預設）/ light（淺色底的駭客任務）
 * 使用者的選擇記在 cookie aisle_theme，每台電腦各自記憶；由 app.js 的切換按鈕寫入
 */
function current_theme()
{
    return (isset($_COOKIE['aisle_theme']) && $_COOKIE['aisle_theme'] === 'light') ? 'light' : 'dark';
}
