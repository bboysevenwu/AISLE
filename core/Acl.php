<?php
/**
 * 身份權限與頁面路由：全部來自 config/config.ini
 *   [roles]        身份清單
 *   [access]       身份 → 可用頁面清單（"*" = 全部）
 *   [page.<代號>]  頁面標題、路由、選單設定、動作層級限制
 *   [group.<代號>] 選單分類（標題、排序、圖示、預設展開），頁面以 group = "<代號>" 歸類
 * 這個類別只負責「讀設定、回答問題」，新增頁面不需要修改它。
 */
class Acl
{
    private static $settings = array();
    private static $roles = array();
    private static $access = array();
    private static $pages = array();
    private static $groups = array();

    public static function init($ini)
    {
        self::$settings = array_get($ini, 'settings', array());
        self::$roles = array_get($ini, 'roles', array());

        self::$access = array();
        $access = array_get($ini, 'access', array());
        foreach (self::$roles as $role => $label) {
            self::$access[$role] = self::splitList(array_get($access, $role, ''));
        }

        self::$groups = array();
        foreach ($ini as $section => $values) {
            if (strpos($section, 'group.') !== 0) {
                continue;
            }
            $key = substr($section, 6);
            self::$groups[$key] = array(
                'key'   => $key,
                'title' => array_get($values, 'title', $key),
                'order' => (int) array_get($values, 'order', 50),
                'icon'  => array_get($values, 'icon', '+'),
                'open'  => (bool) array_get($values, 'open', false),
            );
        }

        self::$pages = array();
        foreach ($ini as $section => $values) {
            if (strpos($section, 'page.') !== 0) {
                continue;
            }
            $key = substr($section, 5);
            $route = trim(array_get($values, 'route', ''));
            if ($route === '') {
                $route = 'module:' . $key;
            }
            $page = array(
                'key'     => $key,
                'title'   => array_get($values, 'title', $key),
                'order'   => (int) array_get($values, 'order', 50),
                'menu'    => (bool) array_get($values, 'menu', true),
                'icon'    => array_get($values, 'icon', '>'),
                'group'   => trim((string) array_get($values, 'group', '')),
                'route'   => $route,
                'actions' => array(),
            );
            if (strpos($route, 'module:') === 0) {
                $page['type'] = 'module';
                $page['target'] = trim(substr($route, 7));
            } else {
                $page['target'] = ltrim($route, '/');
                $page['type'] = strpos($page['target'], 'public/') === 0 ? 'direct' : 'file';
            }
            foreach ($values as $k => $v) {
                if (strpos($k, 'action.') === 0) {
                    $page['actions'][substr($k, 7)] = self::splitList($v);
                }
            }
            self::$pages[$key] = $page;
        }
    }

    private static function splitList($value)
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
    }

    public static function setting($key, $default = null)
    {
        return array_get(self::$settings, $key, $default);
    }

    public static function defaultPage()
    {
        return self::setting('default_page', 'dashboard');
    }

    public static function roleExists($role)
    {
        return isset(self::$roles[$role]);
    }

    public static function roles()
    {
        return self::$roles;
    }

    /** [access] 設為 "*" 的身份 */
    public static function isSuperuser($role)
    {
        return isset(self::$access[$role]) && in_array('*', self::$access[$role], true);
    }

    public static function pageExists($page)
    {
        return isset(self::$pages[$page]);
    }

    public static function page($page)
    {
        return array_get(self::$pages, $page);
    }

    /** 該頁面的網址：獨立頁面直接連到檔案，其他經由 index.php */
    public static function pageUrl($page)
    {
        $p = self::page($page);
        if ($p && $p['type'] === 'direct') {
            return page_url(substr($p['target'], strlen('public/')));
        }
        return url($page);
    }

    /** 由實際執行的檔案路徑找出頁面代號（給 public/ 底下的獨立頁面用） */
    public static function pageForScript($file)
    {
        $file = realpath($file);
        if ($file === false) {
            return null;
        }
        foreach (self::$pages as $key => $p) {
            if ($p['type'] === 'direct' && realpath(AISLE_ROOT . '/' . $p['target']) === $file) {
                return $key;
            }
        }
        return null;
    }

    /**
     * 角色是否符合指定的身份清單
     * $spec 可以是 "EE,PE" 或 array('EE', 'PE')；超級身份一律符合
     */
    public static function roleMatches($role, $spec)
    {
        if (!self::roleExists($role)) {
            return false;
        }
        if (self::isSuperuser($role)) {
            return true;
        }
        $allowed = array();
        foreach ((array) $spec as $item) {
            $allowed = array_merge($allowed, self::splitList($item));
        }
        return in_array($role, $allowed, true);
    }

    /** 核心判斷：身份能否使用某頁面的某動作 */
    public static function canAccess($role, $page, $action = 'index')
    {
        if (!self::pageExists($page) || !self::roleExists($role)) {
            return false;
        }
        if (self::isSuperuser($role)) {
            return true;
        }
        if (!in_array($page, self::$access[$role], true)) {
            return false;
        }
        $p = self::$pages[$page];
        if (isset($p['actions'][$action]) && !in_array($role, $p['actions'][$action], true)) {
            return false;
        }
        return true;
    }

    /** 回傳該身份可見的選單（已排序，含 url） */
    public static function menuFor($role)
    {
        $menu = array();
        foreach (self::$pages as $key => $p) {
            if ($p['menu'] && self::canAccess($role, $key)) {
                $p['url'] = self::pageUrl($key);
                $menu[] = $p;
            }
        }
        usort($menu, function ($a, $b) {
            return $a['order'] - $b['order'];
        });
        return $menu;
    }

    /**
     * 把 menuFor() 的平面清單整理成兩層選單（給 layout 用）
     * 回傳的每一項是頁面，或是 is_group = true 的分類（children 為頁面清單）。
     * 分類依 [group.xxx] 的 order 和其他頁面一起排序；該身份在分類裡沒有任何頁面時不會出現。
     * 頁面寫了 group 但沒有對應的 [group.xxx] 時，以代號當標題、以第一個頁面的 order 排序。
     */
    public static function menuTree($menu, $current = '')
    {
        $tree = array();
        $groups = array();
        foreach ($menu as $p) {
            $g = isset($p['group']) ? $p['group'] : '';
            if ($g === '') {
                $p['is_group'] = false;
                $tree[] = $p;
                continue;
            }
            if (!isset($groups[$g])) {
                $groups[$g] = isset(self::$groups[$g]) ? self::$groups[$g] : array(
                    'key' => $g, 'title' => $g, 'order' => $p['order'], 'icon' => '+', 'open' => false,
                );
                $groups[$g]['is_group'] = true;
                $groups[$g]['active'] = false;
                $groups[$g]['children'] = array();
            }
            $groups[$g]['children'][] = $p;
            if ($p['key'] === $current) {
                $groups[$g]['active'] = true;
            }
        }
        foreach ($groups as $g) {
            $tree[] = $g;
        }
        usort($tree, function ($a, $b) {
            return $a['order'] - $b['order'];
        });
        return $tree;
    }
}
