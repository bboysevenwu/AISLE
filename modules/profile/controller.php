<?php
/**
 * 個人設定：修改密碼（config.ini：EE、PE 都可見；首次登入被強制導向這裡）
 */
class ProfileController extends Controller
{
    public function indexAction()
    {
        $error = '';
        if ($this->isPost()) {
            $current = isset($_POST['current']) ? (string) $_POST['current'] : '';
            $new = isset($_POST['new']) ? (string) $_POST['new'] : '';
            $confirm = isset($_POST['confirm']) ? (string) $_POST['confirm'] : '';

            $row = Database::fetchOne('SELECT password_hash FROM users WHERE id = ?', array($this->user['id']));
            if (!$row || !Password::verify($current, $row['password_hash'])) {
                $error = '目前密碼錯誤';
            } elseif ($new !== $confirm) {
                $error = '兩次輸入的新密碼不一致';
            } elseif (!Password::isStrong($new)) {
                $error = '新密碼至少 8 碼，且需包含英文與數字';
            } elseif ($new === $current) {
                $error = '新密碼不可與目前密碼相同';
            } else {
                Database::execute(
                    'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
                    array(Password::hash($new), $this->user['id'])
                );
                audit($this->module, 'change_password');
                Auth::passwordChanged();
                $this->flash('密碼已更新');
                $this->redirect(url('profile'));
            }
        }

        $this->render('index', array(
            'error'      => $error,
            'mustChange' => $this->user['must_change_password'],
        ));
    }
}
