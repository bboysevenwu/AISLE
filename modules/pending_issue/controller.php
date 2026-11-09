<?php
/**
 * Pending Issue Control Table（config.ini：EE 與 ADMIN）
 * 機型 / 分類 / 狀態 / Sponsor 等設定在 config/pending_issue.ini
 *
 *   index    頁面（表格、篩選、編輯視窗都由 pending_issue.js 呼叫下列 JSON 動作）
 *   list     GET  全部未刪除的 issue（trash=1 且有 action.trash 權限時含已刪除），篩選在前端做
 *   get      GET  單筆完整資料（含圖片、狀態歷程）
 *   save     POST 新增 / 修改；以 version 做樂觀鎖，別人先改過時回 409，不覆蓋
 *   delete   POST 軟刪除（同樣檢查 version）
 *   restore  POST 還原軟刪除（action.restore，預設只有 ADMIN）
 *   upload   POST 上傳一張圖片（貼上 / 拖曳 / 選檔），存到 storage/uploads/pending_issue/<年月>/
 *   image    GET  輸出圖片（storage 無法從網址直接讀取，一律經過這裡檢查權限）
 *   poll     GET  輪詢：游標之後有變動的資料、誰正在編輯哪一筆
 *   release  POST 關閉編輯視窗時清除自己的「編輯中」
 *   export   GET  依目前篩選匯出 CSV（pending_issue.ini [export] enabled = Off 時 404）
 */
class PendingIssueController extends Controller
{
    const POLL_OVERLAP = 5;   // 輪詢游標往前重疊的秒數，避免交易較晚提交的修改被漏掉（前端用 version 去重）

    private static $cfg = null;

    // ==================================================================
    // 頁面
    // ==================================================================

    public function indexAction()
    {
        $cfg = self::cfg();
        $urls = array();
        foreach (array('list', 'get', 'save', 'delete', 'restore', 'upload', 'image', 'poll', 'release', 'export') as $a) {
            $urls[$a] = url($this->module, $a);
        }
        $this->render('index', array(
            'cfg'    => $cfg,
            'canTrash' => $this->can('trash'),
            'export' => $cfg['export'],
            'config' => array(
                'urls'            => $urls,
                'models'          => $cfg['models'],
                'categories'      => $cfg['categories'],
                'statuses'        => $cfg['statuses'],
                'sponsors'        => $this->sponsorList(),
                'me'              => array('id' => (int) $this->user['id'], 'name' => $this->user['display_name']),
                'canTrash'        => $this->can('trash'),
                'canRestore'      => $this->can('restore'),
                'exportEnabled'   => $cfg['export'],
                'maxImageMb'      => $cfg['max_mb'],
                'maxImages'       => $cfg['max_per_item'],
                'pollSeconds'     => $cfg['poll_seconds'],
                'idleSeconds'     => $cfg['idle_seconds'],
                'csrfToken'       => Security::csrfToken(),
            ),
        ));
    }

    // ==================================================================
    // 讀取
    // ==================================================================

    public function listAction()
    {
        $trash = $this->query('trash') === '1' && $this->can('trash');
        $rows = Database::fetchAll(
            self::rowSelect() . ($trash ? '' : ' WHERE i.deleted_at IS NULL') . ' ORDER BY i.id DESC'
        );
        foreach ($rows as $k => $r) {
            $rows[$k] = $this->castRow($r);
        }
        $this->json(array(
            'ok'     => true,
            'rows'   => $rows,
            'cursor' => $this->cursor(),
            'trash'  => $trash,
        ));
    }

    public function getAction()
    {
        $issue = $this->findIssue((int) $this->query('id'), $this->can('trash'));
        if (!$issue) {
            return $this->json(array('ok' => false, 'error' => '找不到這筆 issue，可能已被刪除'), 404);
        }
        $this->json(array('ok' => true, 'issue' => $issue));
    }

    public function pollAction()
    {
        $cfg = self::cfg();
        $uid = (int) $this->user['id'];
        $editing = (int) $this->query('editing');
        $now = now();

        // 自己的「編輯中」：只保留正在編輯的那一筆
        Database::execute('DELETE FROM pending_issue_presence WHERE user_id = ? AND issue_id <> ?', array($uid, $editing));
        if ($editing > 0) {
            Database::execute('REPLACE INTO pending_issue_presence (issue_id, user_id, seen_at) VALUES (?, ?, ?)', array($editing, $uid, $now));
        }
        Database::execute('DELETE FROM pending_issue_presence WHERE seen_at < ?', array(date('Y-m-d H:i:s', time() - 3600)));

        // 游標之後有變動的資料（含被刪除 / 還原的）
        $cursor = $this->cursor();
        $since = $this->query('since');
        $changed = array();
        $deleted = array();
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            $trash = $this->query('trash') === '1' && $this->can('trash');
            foreach (Database::fetchAll(self::rowSelect() . ' WHERE i.updated_at >= ? ORDER BY i.id', array($since)) as $r) {
                $r = $this->castRow($r);
                if ($r['deleted'] && !$trash) {
                    $deleted[] = $r['id'];
                } else {
                    $changed[] = $r;
                }
            }
        }

        $this->json(array(
            'ok'      => true,
            'cursor'  => $cursor,
            'changed' => $changed,
            'deleted' => $deleted,
            'editors' => (object) $this->editors($cfg['presence_seconds']),
        ));
    }

    // ==================================================================
    // 寫入
    // ==================================================================

    public function saveAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $cfg = self::cfg();
        $id = (int) $this->input('id');
        $version = (int) $this->input('version');
        $overwrite = $this->input('overwrite') === '1';
        $data = array(
            'tool_id'         => strtoupper($this->input('tool_id')),
            'model'           => strtoupper($this->input('model')),
            'category'        => $this->input('category'),
            'issue'           => $this->input('issue'),
            'initial_date'    => $this->input('initial_date'),
            'status'          => $this->input('status', 'open'),
            'sponsor_id'      => (int) $this->input('sponsor_id'),
            'checkpoint_date' => $this->input('checkpoint_date'),
            'remark'          => $this->input('remark'),
        );
        $imageIds = $this->idList($this->input('image_ids'));

        $now = now();
        $uid = (int) $this->user['id'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $old = null;
            if ($id > 0) {
                // 鎖住這一筆再比對 version，避免兩個人同時通過檢查
                $old = Database::fetchOne(
                    'SELECT id, version, status, sponsor_id, deleted_at FROM pending_issues WHERE id = ? FOR UPDATE',
                    array($id)
                );
                if (!$old || $old['deleted_at'] !== null || (int) $old['version'] !== $version) {
                    $pdo->rollBack();
                    return $this->conflict($id);
                }
            }

            $error = $this->validate($data, $old);
            if ($error === '' && count($imageIds) > $cfg['max_per_item']) {
                $error = '每筆 issue 最多 ' . $cfg['max_per_item'] . ' 張圖片';
            }
            if ($error !== '') {
                $pdo->rollBack();
                return $this->json(array('ok' => false, 'error' => $error), 422);
            }

            $checkpoint = $data['checkpoint_date'] !== '' ? $data['checkpoint_date'] : null;
            $removed = array();
            if (!$old) {
                Database::execute(
                    'INSERT INTO pending_issues (tool_id, model, category, issue, initial_date, status, status_changed_by,
                        status_changed_at, sponsor_id, checkpoint_date, remark, closed_at, version,
                        created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
                    array(
                        $data['tool_id'], $data['model'], $data['category'], $data['issue'], $data['initial_date'],
                        $data['status'], $uid, $now, $data['sponsor_id'], $checkpoint, $data['remark'],
                        $data['status'] === 'close' ? $now : null,
                        $uid, $uid, $now, $now,
                    )
                );
                $id = (int) Database::lastInsertId();
                $this->logStatus($id, null, $data['status'], $uid, $now);
            } else {
                $statusChanged = $old['status'] !== $data['status'];
                Database::execute(
                    'UPDATE pending_issues SET tool_id = ?, model = ?, category = ?, issue = ?, initial_date = ?, status = ?,
                        status_changed_by = ' . ($statusChanged ? '?' : 'status_changed_by') . ',
                        status_changed_at = ' . ($statusChanged ? '?' : 'status_changed_at') . ",
                        sponsor_id = ?, checkpoint_date = ?, remark = ?,
                        closed_at = CASE WHEN ? = 'close' THEN IFNULL(closed_at, ?) ELSE NULL END,
                        version = version + 1, updated_by = ?, updated_at = ?
                     WHERE id = ?",
                    array_merge(
                        array($data['tool_id'], $data['model'], $data['category'], $data['issue'], $data['initial_date'], $data['status']),
                        $statusChanged ? array($uid, $now) : array(),
                        array($data['sponsor_id'], $checkpoint, $data['remark'], $data['status'], $now, $uid, $now, $id)
                    )
                );
                if ($statusChanged) {
                    // from 一律用資料庫當下的狀態，覆蓋別人的修改時也不會漏記
                    $this->logStatus($id, $old['status'], $data['status'], $uid, $now);
                }
                $removed = $this->detachImages($id, $imageIds);
            }
            $this->attachImages($id, $imageIds);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        // 檔案在交易成功後才刪，避免回滾後資料還在但檔案已不見
        foreach ($removed as $path) {
            $this->unlinkUpload($path);
        }

        $action = !$old ? 'create' : ($overwrite ? 'overwrite' : 'update');
        audit($this->module, $action, array(
            'id' => $id, 'tool_id' => $data['tool_id'], 'status' => $data['status'],
            'from_status' => $old ? $old['status'] : null,
        ));
        $this->json(array('ok' => true, 'issue' => $this->findIssue($id, false)));
    }

    public function deleteAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $id = (int) $this->input('id');
        $now = now();
        $affected = Database::execute(
            'UPDATE pending_issues SET deleted_at = ?, deleted_by = ?, updated_by = ?, updated_at = ?, version = version + 1
             WHERE id = ? AND version = ? AND deleted_at IS NULL',
            array($now, $this->user['id'], $this->user['id'], $now, $id, (int) $this->input('version'))
        );
        if ($affected === 0) {
            return $this->conflict($id);
        }
        Database::execute('DELETE FROM pending_issue_presence WHERE issue_id = ?', array($id));
        audit($this->module, 'delete', array('id' => $id));
        $this->json(array('ok' => true));
    }

    public function restoreAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $id = (int) $this->input('id');
        $now = now();
        $affected = Database::execute(
            'UPDATE pending_issues SET deleted_at = NULL, deleted_by = NULL, updated_by = ?, updated_at = ?, version = version + 1
             WHERE id = ? AND version = ? AND deleted_at IS NOT NULL',
            array($this->user['id'], $now, $id, (int) $this->input('version'))
        );
        if ($affected === 0) {
            $row = $this->findIssue($id, true);
            return $this->json(array(
                'ok' => false, 'conflict' => true,
                'error' => $row ? '這筆資料剛被其他人變更，已重新載入' : '找不到這筆 issue',
                'issue' => $row,
            ), 409);
        }
        audit($this->module, 'restore', array('id' => $id));
        $this->json(array('ok' => true, 'issue' => $this->findIssue($id, true)));
    }

    public function releaseAction()
    {
        Database::execute('DELETE FROM pending_issue_presence WHERE user_id = ?', array($this->user['id']));
        if (App::isAjax()) {
            $this->json(array('ok' => true));
        }
    }

    // ==================================================================
    // 圖片
    // ==================================================================

    public function uploadAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $cfg = self::cfg();
        $maxBytes = (int) round($cfg['max_mb'] * 1048576);
        $file = isset($_FILES['image']) ? $_FILES['image'] : null;
        if (!$file || !is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return $this->json(array('ok' => false, 'error' => '沒有收到圖片（可能超過伺服器上傳上限）'), 400);
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > $maxBytes) {
            return $this->json(array('ok' => false, 'error' => '圖片超過 ' . $cfg['max_mb'] . ' MB'), 413);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $this->json(array('ok' => false, 'error' => '上傳失敗（錯誤碼 ' . (int) $file['error'] . '）'), 400);
        }
        // 用檔案內容判斷真實格式，不相信副檔名與瀏覽器給的 MIME
        $info = @getimagesize($file['tmp_name']);
        $types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif');
        if ($info === false || !isset($types[$info[2]])) {
            return $this->json(array('ok' => false, 'error' => '只接受 PNG / JPG / GIF 圖片'), 415);
        }

        $this->cleanupOrphans();

        $dir = 'pending_issue/' . date('Ym');
        $abs = AISLE_STORAGE . '/uploads/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0775, true)) {
            throw new RuntimeException('無法建立上傳資料夾：storage/uploads/' . $dir . '（請確認 storage/ 可讓 Web Server 寫入）');
        }
        $path = $dir . '/' . bin2hex(Security::randomBytes(16)) . '.' . $types[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], AISLE_STORAGE . '/uploads/' . $path)) {
            throw new RuntimeException('無法寫入上傳檔案，請確認 storage/uploads 可寫入');
        }
        @chmod(AISLE_STORAGE . '/uploads/' . $path, 0664);

        $name = preg_replace('/[\x00-\x1f"\/\\\\]/', '', (string) $file['name']);
        if ($name === '') {
            $name = 'image.' . $types[$info[2]];
        }
        Database::execute(
            'INSERT INTO pending_issue_images (issue_id, file_path, original_name, mime, size, width, height, uploaded_by, created_at)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)',
            array($path, $this->usub($name, 150), $info['mime'], (int) $file['size'],
                min((int) $info[0], 65535), min((int) $info[1], 65535), $this->user['id'], now())
        );
        $id = (int) Database::lastInsertId();
        $this->json(array('ok' => true, 'image' => $this->imageInfo(array(
            'id' => $id, 'original_name' => $name, 'width' => $info[0], 'height' => $info[1],
        ))));
    }

    public function imageAction()
    {
        $img = Database::fetchOne(
            'SELECT m.id, m.issue_id, m.file_path, m.mime, m.original_name, m.uploaded_by, i.deleted_at
             FROM pending_issue_images m
             LEFT JOIN pending_issues i ON i.id = m.issue_id
             WHERE m.id = ?',
            array((int) $this->query('id'))
        );
        $allowed = $img && (
            ($img['issue_id'] === null && (int) $img['uploaded_by'] === (int) $this->user['id'])   // 尚未存檔的暫存圖：只有上傳者
            || ($img['issue_id'] !== null && ($img['deleted_at'] === null || $this->can('trash')))  // 已刪除 issue 的圖：只有 ADMIN
        );
        $base = realpath(AISLE_STORAGE . '/uploads/pending_issue');
        $file = $allowed ? realpath(AISLE_STORAGE . '/uploads/' . $img['file_path']) : false;
        if (!$allowed || $file === false || $base === false || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'image not found';
            return;
        }

        // 檔名是亂數、內容不會變，可讓瀏覽器快取
        $etag = '"pi' . $img['id'] . '-' . filesize($file) . '"';
        header('Cache-Control: private, max-age=86400');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            return;
        }
        $name = $img['original_name'] !== '' ? $img['original_name'] : basename($file);
        header('Content-Type: ' . $img['mime']);
        header('Content-Length: ' . filesize($file));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name));
        readfile($file);
    }

    // ==================================================================
    // 匯出
    // ==================================================================

    public function exportAction()
    {
        $cfg = self::cfg();
        if (!$cfg['export']) {
            return App::error(404, '匯出功能未開啟');
        }
        $trash = $this->query('trash') === '1' && $this->can('trash');
        $where = array();
        $params = array();
        if (!$trash) {
            $where[] = 'i.deleted_at IS NULL';
        }
        $model = strtoupper($this->query('model'));
        if (isset($cfg['models'][$model])) {
            $where[] = 'i.model = ?';
            $params[] = $model;
        }
        foreach (array('status' => 'statuses', 'cat' => 'categories') as $param => $key) {
            $vals = array();
            foreach (explode(',', $this->query($param)) as $v) {
                if (isset($cfg[$key][$v])) {
                    $vals[] = $v;
                }
            }
            if ($vals) {
                $where[] = 'i.' . ($param === 'cat' ? 'category' : 'status') . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
                $params = array_merge($params, $vals);
            }
        }
        $tool = $this->query('tool');
        if ($tool !== '') {
            $where[] = 'i.tool_id LIKE ?';
            $params[] = '%' . addcslashes(strtoupper($tool), '%_\\') . '%';
        }
        $sponsor = (int) $this->query('sponsor');
        if ($sponsor > 0) {
            $where[] = 'i.sponsor_id = ?';
            $params[] = $sponsor;
        }
        $q = $this->query('q');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(i.issue LIKE ? OR i.remark LIKE ? OR i.tool_id LIKE ?)';
            $params = array_merge($params, array($like, $like, $like));
        }
        if ($this->query('overdue') === '1') {
            $where[] = "i.checkpoint_date < CURDATE() AND i.status <> 'close'";
        }

        $sorts = array(
            'id' => 'i.id', 'model' => 'i.model', 'tool_id' => 'i.tool_id', 'category' => 'i.category',
            'issue' => 'i.issue', 'initial_date' => 'i.initial_date', 'sponsor' => 'sponsor_name',
            'checkpoint_date' => 'i.checkpoint_date', 'remark' => 'i.remark', 'updated_at' => 'i.updated_at',
            'status' => "FIELD(i.status, 'open', 'monitor', 'close')",
        );
        $sort = $this->query('sort');
        $dir = $this->query('dir') === 'desc' ? 'DESC' : 'ASC';
        $order = isset($sorts[$sort])
            ? $sorts[$sort] . ' ' . $dir . ', i.id DESC'
            : "FIELD(i.status, 'open', 'monitor', 'close'), i.checkpoint_date IS NULL, i.checkpoint_date, i.id DESC";

        $rows = Database::fetchAll(
            self::rowSelect() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $order,
            $params
        );

        $label = isset($cfg['models'][$model]) ? $model : 'ALL';
        $filename = 'pending_issue_' . $label . '_' . date('Ymd_Hi') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM，Excel 直接開不會亂碼
        $head = array('ID', '機型', '機台', '分類', 'Issue', 'Initial date', 'Status', '最後改狀態者', '改狀態時間',
            'Sponsor', 'Check point', '逾期天數', 'Remark', '圖片張數', '建立者', '建立時間', '最後更新者', '最後更新時間');
        if ($trash) {
            $head[] = '已刪除';
        }
        fputcsv($out, $head);
        $today = strtotime(date('Y-m-d'));
        foreach ($rows as $r) {
            $overdue = '';
            if ($r['checkpoint_date'] !== null && $r['status'] !== 'close') {
                $days = (int) floor(($today - strtotime($r['checkpoint_date'])) / 86400);
                $overdue = $days > 0 ? $days : '';
            }
            $line = array(
                $r['id'],
                array_get($cfg['models'], $r['model'], $r['model']),
                $r['tool_id'],
                array_get($cfg['categories'], $r['category'], $r['category']),
                $r['issue'],
                $r['initial_date'],
                array_get($cfg['statuses'], $r['status'], $r['status']),
                $r['status_changed_by_name'],
                $r['status_changed_at'],
                $r['sponsor_name'],
                $r['checkpoint_date'],
                $overdue,
                $r['remark'],
                $r['image_count'],
                $r['created_by_name'],
                $r['created_at'],
                $r['updated_by_name'],
                $r['updated_at'],
            );
            if ($trash) {
                $line[] = $r['deleted_at'] !== null ? $r['deleted_at'] : '';
            }
            fputcsv($out, array_map(array($this, 'csvCell'), $line));
        }
        fclose($out);
        audit($this->module, 'export', array('rows' => count($rows), 'model' => $label));
    }

    /** 防 CSV 公式注入：= + - @ 開頭的內容前面加單引號 */
    public function csvCell($v)
    {
        $v = $v === null ? '' : (string) $v;
        if ($v !== '' && strpos('=+-@', $v[0]) !== false && !preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            $v = "'" . $v;
        }
        return $v;
    }

    // ==================================================================
    // 內部
    // ==================================================================

    /** 讀取 config/pending_issue.ini（每次請求一次） */
    private static function cfg()
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $file = AISLE_ROOT . '/config/pending_issue.ini';
        $ini = is_file($file) ? parse_ini_file($file, true) : array();
        if ($ini === false) {
            throw new RuntimeException('INI 解析失敗：config/pending_issue.ini');
        }
        $models = array();
        foreach (array_get($ini, 'models', array()) as $k => $v) {
            $models[strtoupper(trim($k))] = (string) $v;
        }
        $statuses = array();
        foreach (array('open' => 'Open', 'monitor' => 'Monitor', 'close' => 'Close') as $k => $default) {
            $statuses[$k] = (string) array_get(array_get($ini, 'status', array()), $k, $default);
        }
        $sync = array_get($ini, 'sync', array());
        $upload = array_get($ini, 'upload', array());
        $sessionIdle = (int) Config::get('security', 'session_idle', 1800);
        $idle = max(30, (int) array_get($sync, 'idle_seconds', 300));
        if ($sessionIdle > 0 && $idle >= $sessionIdle) {
            $idle = max(30, $sessionIdle - 60);
        }
        $roles = array_values(array_filter(array_map('trim', explode(',', (string) array_get(array_get($ini, 'sponsor', array()), 'roles', 'EE,ADMIN'))), 'strlen'));

        self::$cfg = array(
            'models'           => $models ? $models : array('PAN' => '1980 (PAN)', 'PAP' => '2000 (PAP)', 'POV' => 'YSM (POV)'),
            'categories'       => array_get($ini, 'categories', array()) ? array_get($ini, 'categories') : array(
                'hl' => 'PE/YED HL', 'tool_select' => '選機表', 'aging_parts' => 'Aging parts', 'others' => 'Others'),
            'statuses'         => $statuses,
            'sponsor_roles'    => $roles,
            'export'           => (bool) array_get(array_get($ini, 'export', array()), 'enabled', true),
            'max_mb'           => max(0.1, (float) array_get($upload, 'max_mb', 5)),
            'max_per_item'     => max(1, (int) array_get($upload, 'max_per_item', 20)),
            'poll_seconds'     => max(3, (int) array_get($sync, 'poll_seconds', 10)),
            'idle_seconds'     => $idle,
            'presence_seconds' => max(10, (int) array_get($sync, 'presence_seconds', 30)),
        );
        return self::$cfg;
    }

    /** 列表、輪詢、匯出共用的查詢 */
    private static function rowSelect()
    {
        return 'SELECT i.id, i.tool_id, i.model, i.category, i.issue, i.initial_date, i.status,
                    i.status_changed_at, i.sponsor_id, i.checkpoint_date, i.remark, i.closed_at, i.version,
                    i.created_at, i.updated_at, i.deleted_at,
                    COALESCE(NULLIF(sp.display_name, \'\'), sp.account) AS sponsor_name, sp.is_active AS sponsor_active,
                    COALESCE(NULLIF(sc.display_name, \'\'), sc.account) AS status_changed_by_name,
                    COALESCE(NULLIF(cu.display_name, \'\'), cu.account) AS created_by_name,
                    COALESCE(NULLIF(uu.display_name, \'\'), uu.account) AS updated_by_name,
                    COALESCE(NULLIF(du.display_name, \'\'), du.account) AS deleted_by_name,
                    (SELECT COUNT(*) FROM pending_issue_images m WHERE m.issue_id = i.id) AS image_count
             FROM pending_issues i
             LEFT JOIN users sp ON sp.id = i.sponsor_id
             LEFT JOIN users sc ON sc.id = i.status_changed_by
             LEFT JOIN users cu ON cu.id = i.created_by
             LEFT JOIN users uu ON uu.id = i.updated_by
             LEFT JOIN users du ON du.id = i.deleted_by';
    }

    private function castRow($r)
    {
        foreach (array('id', 'version', 'image_count', 'sponsor_id') as $k) {
            if (isset($r[$k])) {
                $r[$k] = (int) $r[$k];
            }
        }
        $r['sponsor_active'] = (bool) $r['sponsor_active'];
        $r['deleted'] = $r['deleted_at'] !== null;
        foreach (array('sponsor_name', 'status_changed_by_name', 'created_by_name', 'updated_by_name', 'deleted_by_name') as $k) {
            if ($r[$k] === null) {
                $r[$k] = '-';
            }
        }
        foreach (array('remark', 'checkpoint_date') as $k) {
            if ($r[$k] === null) {
                $r[$k] = '';
            }
        }
        return $r;
    }

    /** 單筆完整資料（含圖片、狀態歷程） */
    private function findIssue($id, $withDeleted)
    {
        $row = Database::fetchOne(self::rowSelect() . ' WHERE i.id = ?' . ($withDeleted ? '' : ' AND i.deleted_at IS NULL'), array($id));
        if (!$row) {
            return null;
        }
        $issue = $this->castRow($row);
        $issue['images'] = array();
        foreach (Database::fetchAll(
            'SELECT id, original_name, width, height FROM pending_issue_images WHERE issue_id = ? ORDER BY id',
            array($id)
        ) as $img) {
            $issue['images'][] = $this->imageInfo($img);
        }
        $issue['status_logs'] = array();
        foreach (Database::fetchAll(
            'SELECT l.from_status, l.to_status, l.changed_at, COALESCE(NULLIF(u.display_name, \'\'), u.account) AS by_name
             FROM pending_issue_status_logs l LEFT JOIN users u ON u.id = l.changed_by
             WHERE l.issue_id = ? ORDER BY l.id',
            array($id)
        ) as $log) {
            if ($log['by_name'] === null) {
                $log['by_name'] = '-';
            }
            $issue['status_logs'][] = $log;
        }
        return $issue;
    }

    private function imageInfo($img)
    {
        return array(
            'id'     => (int) $img['id'],
            'name'   => $img['original_name'],
            'width'  => (int) $img['width'],
            'height' => (int) $img['height'],
            'url'    => url($this->module, 'image', array('id' => (int) $img['id'])),
        );
    }

    /**
     * 欄位檢查，會正規化 $d（機型由機台代號自動判定）
     * @param array|null $old 修改時的原資料（Sponsor 沒改時，允許保留已停用的帳號）
     */
    private function validate(&$d, $old)
    {
        $cfg = self::cfg();
        if (!preg_match('/^[A-Z0-9][A-Z0-9_.\-]{0,63}$/', $d['tool_id'])) {
            return '機台代號必填，只能使用英數字、底線、點、減號（最多 64 字）';
        }
        $detected = $this->detectModel($d['tool_id']);
        if ($detected !== '') {
            $d['model'] = $detected;
        }
        if (!isset($cfg['models'][$d['model']])) {
            return '無法由機台代號判定機型，請選擇機型';
        }
        if (!isset($cfg['categories'][$d['category']])) {
            return '請選擇分類';
        }
        if ($d['issue'] === '') {
            return 'Issue 描述必填';
        }
        if (strlen($d['issue']) > 60000 || strlen($d['remark']) > 60000) {
            return '內容過長';
        }
        if (!isset($cfg['statuses'][$d['status']])) {
            return '狀態不正確';
        }
        if (!$this->validDate($d['initial_date'])) {
            return 'Initial date 格式需為 YYYY-MM-DD';
        }
        if ($d['checkpoint_date'] !== '' && !$this->validDate($d['checkpoint_date'])) {
            return 'Check point date 格式需為 YYYY-MM-DD';
        }
        $keepOld = $old && (int) $old['sponsor_id'] === $d['sponsor_id'];
        if (!$keepOld && !$this->isSponsorCandidate($d['sponsor_id'])) {
            return 'Sponsor 必須是 AISLE 使用者（請從清單中選擇）';
        }
        return '';
    }

    /** 機台代號包含機型代碼（不分大小寫）→ 該機型；較長的代碼優先 */
    private function detectModel($toolId)
    {
        $keys = array_keys(self::cfg()['models']);
        usort($keys, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($keys as $k) {
            if ($k !== '' && stripos($toolId, $k) !== false) {
                return $k;
            }
        }
        return '';
    }

    /** 可選為 Sponsor 的使用者：啟用中、身份在 [sponsor] roles */
    private function sponsorList()
    {
        $roles = self::cfg()['sponsor_roles'];
        if (!$roles) {
            return array();
        }
        $list = array();
        foreach (Database::fetchAll(
            'SELECT id, account, display_name FROM users
             WHERE is_active = 1 AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')
             ORDER BY display_name, account',
            $roles
        ) as $u) {
            $list[] = array(
                'id'      => (int) $u['id'],
                'account' => $u['account'],
                'name'    => $u['display_name'] !== '' ? $u['display_name'] : $u['account'],
            );
        }
        return $list;
    }

    private function isSponsorCandidate($id)
    {
        foreach ($this->sponsorList() as $u) {
            if ($u['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    private function logStatus($issueId, $from, $to, $uid, $now)
    {
        Database::execute(
            'INSERT INTO pending_issue_status_logs (issue_id, from_status, to_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?)',
            array($issueId, $from, $to, $uid, $now)
        );
    }

    /** 存檔 / 刪除時 version 不符或已被刪除：回 409 並附上最新資料，讓前端選擇覆蓋或載入最新 */
    private function conflict($id)
    {
        $current = $this->findIssue($id, true);
        if (!$current) {
            return $this->json(array('ok' => false, 'conflict' => true, 'reason' => 'gone', 'error' => '找不到這筆 issue'), 409);
        }
        if ($current['deleted']) {
            return $this->json(array(
                'ok' => false, 'conflict' => true, 'reason' => 'deleted',
                'error' => '這筆 issue 已被 ' . $current['deleted_by_name'] . ' 於 ' . $current['deleted_at'] . ' 刪除',
            ), 409);
        }
        return $this->json(array(
            'ok'       => false,
            'conflict' => true,
            'reason'   => 'modified',
            'error'    => '這筆 issue 在你編輯期間已被 ' . $current['updated_by_name'] . ' 於 ' . $current['updated_at'] . ' 修改',
            'issue'    => $current,
        ), 409);
    }

    /** 其他人正在編輯哪幾筆：issue_id => [名字] */
    private function editors($seconds)
    {
        $out = array();
        foreach (Database::fetchAll(
            'SELECT p.issue_id, COALESCE(NULLIF(u.display_name, \'\'), u.account) AS name
             FROM pending_issue_presence p JOIN users u ON u.id = p.user_id
             WHERE p.seen_at >= ? AND p.user_id <> ? ORDER BY p.seen_at',
            array(date('Y-m-d H:i:s', time() - $seconds), $this->user['id'])
        ) as $r) {
            $out[$r['issue_id']][] = $r['name'];
        }
        return $out;
    }

    /** 輪詢游標：伺服器時間往前重疊幾秒 */
    private function cursor()
    {
        return date('Y-m-d H:i:s', time() - self::POLL_OVERLAP);
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
        Database::execute(
            'UPDATE pending_issue_images SET issue_id = ?
             WHERE issue_id IS NULL AND uploaded_by = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge(array($issueId, $this->user['id']), $ids)
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
        if (strpos($path, '..') === false && strpos($path, 'pending_issue/') === 0 && is_file($file)) {
            @unlink($file);
        }
    }
}
