<?php
/**
 * 使用者管理（config.ini：ADMIN）
 *   index  列表 + 新增
 *   toggle 啟用 / 停用
 *   reset  重設密碼（產生暫時密碼，下次登入強制修改）
 */
class AdminUsersController extends Controller
{
    public function indexAction()
    {
        $error = '';
        $form = array('account' => '', 'display_name' => '', 'email' => '', 'role' => 'EE');

        if ($this->isPost()) {
            foreach ($form as $k => $v) {
                $form[$k] = $this->input($k);
            }
            $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

            if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $form['account'])) {
                $error = '帳號需為 3~64 字元的英數字、底線、點或減號';
            } elseif (!Acl::roleExists($form['role'])) {
                $error = '身份不存在';
            } elseif (!Password::isStrong($password)) {
                $error = '密碼至少 8 碼，且需包含英文與數字';
            } elseif (Database::fetchOne('SELECT id FROM users WHERE account = ?', array($form['account']))) {
                $error = '帳號已存在';
            } else {
                Database::execute(
                    'INSERT INTO users (account, password_hash, display_name, email, role, must_change_password, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, ?)',
                    array(
                        $form['account'],
                        Password::hash($password),
                        $form['display_name'],
                        $form['email'] !== '' ? $form['email'] : null,
                        $form['role'],
                        now(),
                    )
                );
                audit($this->module, 'create', array('account' => $form['account'], 'role' => $form['role']));
                $this->flash('已建立使用者 ' . $form['account']);
                $this->redirect(url('admin_users'));
            }
        }

        $users = Database::fetchAll(
            'SELECT id, account, display_name, role, is_active, locked_until, last_login_at FROM users ORDER BY id'
        );
        $this->render('index', array(
            'users' => $users,
            'roles' => Acl::roles(),
            'form'  => $form,
            'error' => $error,
        ));
    }

    public function toggleAction()
    {
        $id = (int) $this->input('id');
        if ($this->isPost() && $id !== $this->user['id']) {
            Database::execute('UPDATE users SET is_active = 1 - is_active WHERE id = ?', array($id));
            audit($this->module, 'toggle', array('id' => $id));
            $this->flash('已更新帳號狀態');
        }
        $this->redirect(url('admin_users'));
    }

    public function resetAction()
    {
        $id = (int) $this->input('id');
        if ($this->isPost() && $id > 0) {
            $temp = 'Tmp' . bin2hex(Security::randomBytes(4)) . mt_rand(10, 99);
            Database::execute(
                'UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?',
                array(Password::hash($temp), $id)
            );
            audit($this->module, 'reset_password', array('id' => $id));
            $this->flash('暫時密碼：' . $temp . '（請以安全管道交給使用者）');
        }
        $this->redirect(url('admin_users'));
    }
}
