<?php
/**
 * 極簡樣板：純 PHP 檔 + extract()
 */
class View
{
    public static function fetch($file, $vars = array())
    {
        if (!is_file($file)) {
            throw new RuntimeException('找不到樣板：' . str_replace(AISLE_ROOT, '', $file));
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return ob_get_clean();
    }
}
