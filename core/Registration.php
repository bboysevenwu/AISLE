<?php
/**
 * 使用者註冊：欄位驗證、依 config.ini 的 EE 名單判定身份、寫入資料庫
 *
 * 身份判定（只在註冊當下執行一次，結果寫入 users.role）：
 *   帳號列在 [ee_accounts] account[] → [register] listed_role   (EE)
 *   其他帳號                          → [register] unlisted_role (PE；空字串 = 不允許註冊)
 */
class Registration
{
    private static function config()
    {
        $perm = Config::permissions();
        return isset($perm['register']) ? $perm['register'] : array();
    }

    public static function isEnabled()
    {
        $c = self::config();
        return !empty($c['enabled']);
    }

    public static function requiresApproval()
    {
        $c = self::config();
        return !empty($c['require_approval']);
    }

    /** INI 中的角色設定必須存在且不可是 superuser（ADMIN 永遠無法經由註冊取得） */
    private static function validRole($role)
    {
        return $role !== '' && Acl::roleExists($role) && !Acl::isSuperuser($role);
    }

    /** [ee_accounts] 名單（已轉小寫） */
    public static function eeAccounts()
    {
        $perm = Config::permissions();
        $list = isset($perm['ee_accounts']['account']) ? (array) $perm['ee_accounts']['account'] : array();
        $result = array();
        foreach ($list as $account) {
            $account = strtolower(trim($account));
            if ($account !== '') {
                $result[$account] = true;
            }
        }
        return $result;
    }

    /**
     * 依 EE 名單判定身份
     * @return string 角色；'' 代表此帳號不允許註冊
     */
    public static function determineRole($account)
    {
        $c = self::config();
        $listed = self::eeAccounts();
        $role = isset($listed[strtolower($account)])
            ? array_get($c, 'listed_role', 'EE')
            : array_get($c, 'unlisted_role', 'PE');
        return self::validRole($role) ? $role : '';
    }

    /** 整理表單輸入 */
    public static function normalize($input)
    {
        return array(
            'account'      => trim((string) array_get($input, 'account', '')),
            'display_name' => trim((string) array_get($input, 'display_name', '')),
            'password'     => (string) array_get($input, 'password', ''),
            'confirm'      => (string) array_get($input, 'confirm', ''),
        );
    }

    /**
     * 驗證欄位
     * @return array 欄位名稱 => 錯誤訊息；空陣列代表通過
     */
    public static function validate($data)
    {
        $errors = array();

        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $data['account'])) {
            $errors['account'] = '帳號需為 3~64 字元的英數字、底線、點或減號';
        } elseif (self::accountExists($data['account'])) {
            $errors['account'] = '此帳號已被使用';
        } elseif (self::determineRole($data['account']) === '') {
            $errors['account'] = '此帳號不在可註冊名單中，請聯絡管理員';
        }

        if ($data['display_name'] === '' || !preg_match('/^.{1,100}$/u', $data['display_name'])) {
            $errors['display_name'] = '請輸入 1~100 字的顯示名稱';
        }

        if (!Password::isStrong($data['password'])) {
            $errors['password'] = '密碼至少 8 碼，且需包含英文與數字';
        } elseif (strcasecmp($data['password'], $data['account']) === 0) {
            $errors['password'] = '密碼不可與帳號相同';
        }
        if ($data['password'] !== $data['confirm']) {
            $errors['confirm'] = '兩次輸入的密碼不一致';
        }

        return $errors;
    }

    public static function accountExists($account)
    {
        return (bool) Database::fetchOne('SELECT id FROM users WHERE account = ?', array($account));
    }

    /**
     * 完整註冊流程
     * @return array array('ok' => bool, 'errors' => array, 'role' => string, 'pending' => bool)
     */
    public static function register($input)
    {
        if (!self::isEnabled()) {
            return array('ok' => false, 'errors' => array('_' => '目前未開放註冊'), 'role' => '', 'pending' => false);
        }

        $data = self::normalize($input);
        $errors = self::validate($data);
        if ($errors) {
            return array('ok' => false, 'errors' => $errors, 'role' => '', 'pending' => false);
        }

        $role = self::determineRole($data['account']);
        $pending = self::requiresApproval();

        try {
            Database::execute(
                'INSERT INTO users (account, password_hash, display_name, role, is_active, must_change_password, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?)',
                array(
                    $data['account'],
                    Password::hash($data['password']),
                    $data['display_name'],
                    $role,
                    $pending ? 0 : 1,
                    now(),
                )
            );
        } catch (PDOException $e) {
            // 23000 = 違反唯一鍵：兩個人同時註冊同一帳號
            if ($e->getCode() == 23000) {
                return array('ok' => false, 'errors' => array('account' => '此帳號已被使用'), 'role' => '', 'pending' => false);
            }
            throw $e;
        }

        $userId = (int) Database::lastInsertId();
        $listed = self::eeAccounts();
        Database::execute(
            'INSERT INTO audit_logs (user_id, module, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            array($userId, 'register', 'create', json_encode(array(
                'role'   => $role,
                'source' => isset($listed[strtolower($data['account'])]) ? 'ee_accounts' : 'unlisted_role',
            )), client_ip(), now())
        );

        return array('ok' => true, 'errors' => array(), 'role' => $role, 'pending' => $pending);
    }
}
