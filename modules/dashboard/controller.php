<?php
/**
 * 主控台（config.ini：EE、PE 都可見）
 * 依目前角色列出可用模組
 */
class DashboardController extends Controller
{
    public function indexAction()
    {
        $menu = array();
        foreach (Acl::menuFor($this->user['role']) as $item) {
            if ($item['key'] !== 'dashboard') {
                $menu[] = $item;
            }
        }
        $roles = Acl::roles();

        $this->render('index', array(
            'modules'   => $menu,
            'roleLabel' => isset($roles[$this->user['role']]) ? $roles[$this->user['role']] : $this->user['role'],
        ));
    }
}
