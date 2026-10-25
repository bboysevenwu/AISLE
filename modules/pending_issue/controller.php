<?php
/**
 * AISLE Pending Issue Summary（config.ini：EE、PE）
 * 紀錄各機台 (ToolID) 發生的 issue、狀態、sponsor、處理者、remark，可貼上圖片
 *
 *   index   頁面（列表、搜尋、編輯視窗都由 jQuery 操作下列 JSON 動作）
 *   list    GET  搜尋 + 各分類 / 狀態統計
 *   get     GET  單筆 issue（含圖片、正在編輯的人）
 *   save    POST 新增 / 修改；以 version 做樂觀鎖，別人先改過時回 409 不覆蓋
 *   delete  POST 軟刪除（同樣檢查 version）
 *   upload  POST 上傳一張圖片（貼上 / 拖曳 / 選檔），存到 storage/uploads/
 *   image   GET  讀取圖片（storage 不對外開放，經由這裡檢查權限後輸出）
 *   poll    GET  每 10 秒輪詢：資料是否有變動、誰正在編輯哪一筆
 */
class PendingIssueController extends Controller
{
    /** 分類代號 => 顯示名稱（代號寫入資料庫，上線後不要修改代號） */
    private static $categories = array(
        'hl'          => '產品HL',
        'tool_select' => '選機表',
        'aging_parts' => 'Aging Parts',
        'other'       => '其他',
    );

    private static $statuses = array(
        'open'    => 'OPEN',
        'pending' => 'PENDING',
        'close'   => 'CLOSE',
    );

    const MAX_IMAGE_BYTES = 5242880; // 單張圖片上限 5 MB
    const MAX_IMAGES = 20;           // 每筆 issue 圖片上限
    const MAX_ROWS = 1000;           // 列表一次最多回傳筆數
    const PRESENCE_SECONDS = 30;     // 超過幾秒沒輪詢視為已離開編輯

    public function indexAction()
    {
        $this->render('index', array(
            'categories' => self::$categories,
            'statuses'   => self::$statuses,
            'config'     => array(
                'urls' => array(
                    'list'   => url($this->module, 'list'),
                    'get'    => url($this->module, 'get'),
                    'save'   => url($this->module, 'save'),
                    'delete' => url($this->module, 'delete'),
                    'upload' => url($this->module, 'upload'),
                    'image'  => url($this->module, 'image'),
                    'poll'   => url($this->module, 'poll'),
                ),
                'categories'  => self::$categories,
                'statuses'    => self::$statuses,
                'canDelete'   => $this->can('delete'),
                'maxImageMb'  => self::MAX_IMAGE_BYTES / 1048576,
                'maxImages'   => self::MAX_IMAGES,
                'pollSeconds' => 10,
                // 頁面閒置超過這個秒數就停止輪詢，讓 session 能照常逾時登出
                'idleSeconds' => (int) Config::get('security', 'session_idle', 1800),
            ),
            'suggest' => $this->suggestions(),
        ));
    }

    public function listAction()
    {
        $where = array('i.deleted_at IS NULL');
        $params = array();

        $q = $this->query('q');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(i.tool_id LIKE ? OR i.title LIKE ? OR i.description LIKE ? OR i.remark LIKE ? OR i.sponsor LIKE ? OR i.owner LIKE ?)';
            $params = array_merge($params, array($like, $like, $like, $like, $like, $like));
        }
        $tool = $this->query('tool');
        if ($tool !== '') {
            $where[] = 'i.tool_id LIKE ?';
            $params[] = '%' . addcslashes($tool, '%_\\') . '%';
        }
        $category = $this->query('category');
        if (isset(self::$categories[$category])) {
            $where[] = 'i.category = ?';
            $params[] = $category;
        }
        $statuses = array();
        foreach (explode(',', $this->query('status')) as $s) {
            if (isset(self::$statuses[$s])) {
                $statuses[] = $s;
            }
        }
        if ($statuses) {
            $where[] = 'i.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            $params = array_merge($params, $statuses);
        }
        $from = $this->query('from');
        if ($this->validDate($from)) {
            $where[] = 'i.occurred_on >= ?';
            $params[] = $from;
        }
        $to = $this->query('to');
        if ($this->validDate($to)) {
            $where[] = 'i.occurred_on <= ?';
            $params[] = $to;
        }

        $rows = Database::fetchAll(
            'SELECT i.id, i.tool_id, i.category, i.title, i.status, i.sponsor, i.owner, i.remark,
                    i.occurred_on, i.closed_at, i.version, i.updated_at,
                    u.display_name AS updated_by_name,
                    (SELECT COUNT(*) FROM pending_issue_images m WHERE m.issue_id = i.id) AS image_count
             FROM pending_issues i
             LEFT JOIN users u ON u.id = i.updated_by
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY FIELD(i.status, 'open', 'pending', 'close'), i.occurred_on DESC, i.id DESC
             LIMIT " . (self::MAX_ROWS + 1),
            $params
        );
        $truncated = count($rows) > self::MAX_ROWS;
        if ($truncated) {
            array_pop($rows);
        }
        foreach ($rows as $k => $r) {
            $rows[$k] = $this->castRow($r);
        }

        $this->json(array(
            'ok'        => true,
            'rows'      => $rows,
            'truncated' => $truncated,
            'summary'   => $this->summary(),
            'stamp'     => $this->stamp(),
        ));
    }

    public function getAction()
    {
        $issue = $this->findIssue((int) $this->query('id'));
        if (!$issue) {
            return $this->json(array('ok' => false, 'error' => '找不到這筆 issue，可能已被刪除'), 404);
        }
        $this->json(array('ok' => true, 'issue' => $issue));
    }

    public function saveAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $id = (int) $this->input('id');
        $version = (int) $this->input('version');
        $data = array(
            'tool_id'     => strtoupper($this->input('tool_id')),
            'category'    => $this->input('category'),
            'title'       => $this->input('title'),
            'description' => $this->input('description'),
            'status'      => $this->input('status', 'open'),
            'sponsor'     => $this->input('sponsor'),
            'owner'       => $this->input('owner'),
            'remark'      => $this->input('remark'),
            'occurred_on' => $this->input('occurred_on'),
        );
        $error = $this->validate($data);
        if ($error !== '') {
            return $this->json(array('ok' => false, 'error' => $error), 422);
        }
        $imageIds = $this->idList($this->input('image_ids'));
        if (count($imageIds) > self::MAX_IMAGES) {
            return $this->json(array('ok' => false, 'error' => '每筆 issue 最多 ' . self::MAX_IMAGES . ' 張圖片'), 422);
        }

        $isNew = $id === 0;
        $now = now();
        $uid = $this->user['id'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                Database::execute(
                    'INSERT INTO pending_issues (tool_id, category, title, description, status, sponsor, owner, remark,
                        occurred_on, closed_at, version, created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
                    array(
                        $data['tool_id'], $data['category'], $data['title'], $data['description'], $data['status'],
                        $data['sponsor'], $data['owner'], $data['remark'], $data['occurred_on'],
                        $data['status'] === 'close' ? $now : null,
                        $uid, $uid, $now, $now,
                    )
                );
                $id = (int) Database::lastInsertId();
                $removed = array();
            } else {
                $affected = Database::execute(
                    "UPDATE pending_issues SET tool_id = ?, category = ?, title = ?, description = ?, status = ?,
                        sponsor = ?, owner = ?, remark = ?, occurred_on = ?,
                        closed_at = CASE WHEN ? = 'close' THEN IFNULL(closed_at, ?) ELSE NULL END,
                        version = version + 1, updated_by = ?, updated_at = ?
                     WHERE id = ? AND version = ? AND deleted_at IS NULL",
                    array(
                        $data['tool_id'], $data['category'], $data['title'], $data['description'], $data['status'],
                        $data['sponsor'], $data['owner'], $data['remark'], $data['occurred_on'],
                        $data['status'], $now, $uid, $now, $id, $version,
                    )
                );
                if ($affected === 0) {
                    $pdo->rollBack();
                    return $this->conflict($id);
                }
                $removed = $this->detachImages($id, $imageIds);
            }
            $this->attachImages($id, $imageIds);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        // 檔案在交易成功後才刪，避免回滾後資料還在但檔案已不見
        foreach ($removed as $path) {
            $this->unlinkUpload($path);
        }

        audit($this->module, $isNew ? 'create' : 'update', array('id' => $id, 'tool_id' => $data['tool_id'], 'status' => $data['status']));
        $this->json(array('ok' => true, 'issue' => $this->findIssue($id)));
    }

    public function deleteAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $id = (int) $this->input('id');
        $now = now();
        $affected = Database::execute(
            'UPDATE pending_issues SET deleted_at = ?, deleted_by = ?, updated_at = ?, version = version + 1
             WHERE id = ? AND version = ? AND deleted_at IS NULL',
            array($now, $this->user['id'], $now, $id, (int) $this->input('version'))
        );
        if ($affected === 0) {
            return $this->conflict($id);
        }
        Database::execute('DELETE FROM pending_issue_presence WHERE issue_id = ?', array($id));
        audit($this->module, 'delete', array('id' => $id));
        $this->json(array('ok' => true));
    }

    public function uploadAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $file = isset($_FILES['image']) ? $_FILES['image'] : null;
        if (!$file || !is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return $this->json(array('ok' => false, 'error' => '沒有收到圖片'), 400);
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > self::MAX_IMAGE_BYTES) {
            return $this->json(array('ok' => false, 'error' => '圖片超過 ' . (self::MAX_IMAGE_BYTES / 1048576) . ' MB'), 413);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $this->json(array('ok' => false, 'error' => '上傳失敗（錯誤碼 ' . (int) $file['error'] . '）'), 400);
        }
        $info = @getimagesize($file['tmp_name']);
        $types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif');
        if ($info === false || !isset($types[$info[2]])) {
            return $this->json(array('ok' => false, 'error' => '只接受 PNG / JPG / GIF 圖片'), 415);
        }

        $this->cleanupOrphans();

        $dir = 'pending_issue/' . date('Ym');
        $abs = AISLE_STORAGE . '/uploads/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0775, true)) {
            throw new RuntimeException('無法建立上傳資料夾：storage/uploads/' . $dir);
        }
        $path = $dir . '/' . date('YmdHis') . '_' . bin2hex(Security::randomBytes(8)) . '.' . $types[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], AISLE_STORAGE . '/uploads/' . $path)) {
            throw new RuntimeException('無法寫入上傳檔案，請確認 storage/uploads 可寫入');
        }
        @chmod(AISLE_STORAGE . '/uploads/' . $path, 0664);

        $name = preg_replace('/[\x00-\x1f\/\\\\]/', '', (string) $file['name']);
        Database::execute(
            'INSERT INTO pending_issue_images (issue_id, file_path, original_name, mime, size, width, height, uploaded_by, created_at)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)',
            array($path, $this->usub($name, 150), $info['mime'], (int) $file['size'],
                min((int) $info[0], 65535), min((int) $info[1], 65535), $this->user['id'], now())
        );
        $id = (int) Database::lastInsertId();
        $this->json(array('ok' => true, 'image' => array(
            'id'   => $id,
            'url'  => url($this->module, 'image', array('id' => $id)),
            'name' => $name,
        )));
    }

    public function imageAction()
    {
        $img = Database::fetchOne('SELECT file_path, mime, original_name FROM pending_issue_images WHERE id = ?', array((int) $this->query('id')));
        $file = $img ? AISLE_STORAGE . '/uploads/' . $img['file_path'] : '';
        if (!$img || !is_file($file)) {
            http_response_code(404);
            echo 'image not found';
            return;
        }
        // 圖片內容不會變（檔名含亂數），可讓瀏覽器快取
        header('Content-Type: ' . $img['mime']);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . rawurlencode($img['original_name'] !== '' ? $img['original_name'] : basename($file)) . '"');
        readfile($file);
    }

    public function pollAction()
    {
        $uid = $this->user['id'];
        $editing = (int) $this->query('editing');
        $now = now();
        Database::execute('DELETE FROM pending_issue_presence WHERE user_id = ? AND issue_id <> ?', array($uid, $editing));
        if ($editing > 0) {
            Database::execute('REPLACE INTO pending_issue_presence (issue_id, user_id, seen_at) VALUES (?, ?, ?)', array($editing, $uid, $now));
        }
        $since = date('Y-m-d H:i:s', time() - self::PRESENCE_SECONDS);
        Database::execute('DELETE FROM pending_issue_presence WHERE seen_at < ?', array(date('Y-m-d H:i:s', time() - 600)));

        $editors = array();
        foreach (Database::fetchAll(
            'SELECT p.issue_id, u.display_name, u.account FROM pending_issue_presence p
             JOIN users u ON u.id = p.user_id
             WHERE p.seen_at >= ? AND p.user_id <> ? ORDER BY p.seen_at',
            array($since, $uid)
        ) as $r) {
            $editors[$r['issue_id']][] = $r['display_name'] !== '' ? $r['display_name'] : $r['account'];
        }
        $online = Database::fetchOne('SELECT COUNT(DISTINCT user_id) AS n FROM pending_issue_presence WHERE seen_at >= ?', array($since));

        $this->json(array(
            'ok'      => true,
            'stamp'   => $this->stamp(),
            'editors' => (object) $editors,
            'editing' => (int) $online['n'],
        ));
    }

    // ------------------------------------------------------------------

    private function validate(&$d)
    {
        if (!preg_match('/^[A-Z0-9][A-Z0-9_.\-]{0,63}$/', $d['tool_id'])) {
            return 'ToolID 必填，只能使用英數字、底線、點、減號（最多 64 字）';
        }
        if (!isset(self::$categories[$d['category']])) {
            return '請選擇分類';
        }
        if ($d['title'] === '' || $this->ulen($d['title']) > 200) {
            return 'Issue 標題必填，最多 200 字';
        }
        if (!isset(self::$statuses[$d['status']])) {
            return '狀態不正確';
        }
        if (!$this->validDate($d['occurred_on'])) {
            return '發生日期格式需為 YYYY-MM-DD';
        }
        foreach (array('sponsor' => 'Sponsor', 'owner' => '處理者') as $k => $label) {
            if ($this->ulen($d[$k]) > 100) {
                return $label . ' 最多 100 字';
            }
        }
        foreach (array('description', 'remark') as $k) {
            if (strlen($d[$k]) > 60000) {
                return '內容過長';
            }
        }
        return '';
    }

    /** UTF-8 字數（不依賴 mbstring 擴充） */
    private function ulen($s)
    {
        return (int) preg_match_all('/./us', $s, $m);
    }

    private function usub($s, $length)
    {
        return preg_match('/^.{0,' . (int) $length . '}/us', $s, $m) ? $m[0] : '';
    }

    private function validDate($s)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function idList($csv)
    {
        $ids = array();
        foreach (explode(',', $csv) as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $ids[$v] = $v;
            }
        }
        return array_values($ids);
    }

    /** 把自己剛上傳、尚未綁定的圖片綁到這筆 issue */
    private function attachImages($issueId, $ids)
    {
        if (!$ids) {
            return;
        }
        $params = array_merge(array($issueId, $this->user['id']), $ids);
        Database::execute(
            'UPDATE pending_issue_images SET issue_id = ?
             WHERE issue_id IS NULL AND uploaded_by = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $params
        );
    }

    /** 移除這筆 issue 上不在保留清單內的圖片，回傳要刪的檔案路徑 */
    private function detachImages($issueId, $keepIds)
    {
        $paths = array();
        foreach (Database::fetchAll('SELECT id, file_path FROM pending_issue_images WHERE issue_id = ?', array($issueId)) as $img) {
            if (!in_array((int) $img['id'], $keepIds, true)) {
                Database::execute('DELETE FROM pending_issue_images WHERE id = ?', array($img['id']));
                $paths[] = $img['file_path'];
            }
        }
        return $paths;
    }

    /** 清除超過一天仍未綁定 issue 的暫存圖片（貼上後沒按儲存） */
    private function cleanupOrphans()
    {
        $old = Database::fetchAll(
            'SELECT id, file_path FROM pending_issue_images WHERE issue_id IS NULL AND created_at < ? LIMIT 50',
            array(date('Y-m-d H:i:s', time() - 86400))
        );
        foreach ($old as $img) {
            Database::execute('DELETE FROM pending_issue_images WHERE id = ?', array($img['id']));
            $this->unlinkUpload($img['file_path']);
        }
    }

    private function unlinkUpload($path)
    {
        $file = AISLE_STORAGE . '/uploads/' . $path;
        if (strpos($path, '..') === false && is_file($file)) {
            @unlink($file);
        }
    }

    /** 存檔或刪除時 version 不符：回傳目前資料讓前端提示 */
    private function conflict($id)
    {
        $current = $this->findIssue($id);
        if (!$current) {
            return $this->json(array('ok' => false, 'gone' => true, 'error' => '這筆 issue 已被其他人刪除'), 410);
        }
        return $this->json(array(
            'ok'       => false,
            'conflict' => true,
            'error'    => $current['updated_by_name'] . ' 已在 ' . $current['updated_at'] . ' 修改過這筆 issue',
            'issue'    => $current,
        ), 409);
    }

    private function findIssue($id)
    {
        $issue = Database::fetchOne(
            'SELECT i.*, cu.display_name AS created_by_name, uu.display_name AS updated_by_name
             FROM pending_issues i
             LEFT JOIN users cu ON cu.id = i.created_by
             LEFT JOIN users uu ON uu.id = i.updated_by
             WHERE i.id = ? AND i.deleted_at IS NULL',
            array($id)
        );
        if (!$issue) {
            return null;
        }
        unset($issue['deleted_at'], $issue['deleted_by']);
        $issue = $this->castRow($issue);
        $issue['images'] = array();
        foreach (Database::fetchAll(
            'SELECT id, original_name, width, height FROM pending_issue_images WHERE issue_id = ? ORDER BY id',
            array($id)
        ) as $img) {
            $issue['images'][] = array(
                'id'   => (int) $img['id'],
                'name' => $img['original_name'],
                'url'  => url($this->module, 'image', array('id' => $img['id'])),
            );
        }
        return $issue;
    }

    private function castRow($r)
    {
        foreach (array('id', 'version', 'image_count', 'created_by', 'updated_by') as $k) {
            if (isset($r[$k])) {
                $r[$k] = (int) $r[$k];
            }
        }
        foreach (array('updated_by_name', 'created_by_name') as $k) {
            if (array_key_exists($k, $r) && $r[$k] === null) {
                $r[$k] = '-';
            }
        }
        return $r;
    }

    /** 分類 x 狀態 的件數（不受搜尋條件影響，作為總覽） */
    private function summary()
    {
        $out = array();
        foreach (self::$categories as $c => $label) {
            $out[$c] = array('open' => 0, 'pending' => 0, 'close' => 0);
        }
        foreach (Database::fetchAll(
            'SELECT category, status, COUNT(*) AS n FROM pending_issues WHERE deleted_at IS NULL GROUP BY category, status'
        ) as $r) {
            if (isset($out[$r['category']][$r['status']])) {
                $out[$r['category']][$r['status']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** 資料指紋：任何新增、修改、刪除都會改變，前端輪詢比對後才重新載入列表 */
    private function stamp()
    {
        $r = Database::fetchOne('SELECT COUNT(*) AS n, MAX(updated_at) AS t, SUM(version) AS v FROM pending_issues');
        return md5($r['n'] . '|' . $r['t'] . '|' . $r['v']);
    }

    /** 輸入框的建議清單：既有 ToolID、sponsor / 處理者、系統使用者名稱 */
    private function suggestions()
    {
        $tools = array();
        foreach (Database::fetchAll('SELECT DISTINCT tool_id FROM pending_issues WHERE deleted_at IS NULL ORDER BY tool_id LIMIT 500') as $r) {
            $tools[] = $r['tool_id'];
        }
        $people = array();
        foreach (Database::fetchAll("SELECT display_name AS n FROM users WHERE is_active = 1 AND display_name <> ''
                                     UNION SELECT sponsor FROM pending_issues WHERE sponsor <> ''
                                     UNION SELECT owner FROM pending_issues WHERE owner <> '' LIMIT 500") as $r) {
            $people[$r['n']] = true;
        }
        $people = array_keys($people);
        sort($people);
        return array('tools' => $tools, 'people' => $people);
    }
}
