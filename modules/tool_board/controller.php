<?php
/**
 * 機台借機與 PM 排程看板（Tool Reservation & Maintenance Management Board）
 *
 * 共用設定 config/tool_board.ini；機型專屬 config/tool_board/<機型>.ini（機台、可編輯成員、WIP 處理方式）
 * 進頁面的權限在 config/config.ini；寫入動作另外檢查：ADMIN 全部，EE 只限列在該機型 [editors] 的帳號
 *
 *   index    頁面（看板、右鍵選單、表單都由 tool_board.js 呼叫下列 JSON 動作）
 *   board    GET  該機型：機台、時間視窗內的 Action、每日可用率、PM 射程、設定
 *   poll     GET  輪詢：游標之後有變動的 Action、可用率、線上 / 編輯中、PM 檔是否更新
 *   save     POST 新增 / 表單修改（version 樂觀鎖，別人先改過回 409；同台重疊回 422）
 *   move     POST 拖曳改起訖（同上）
 *   delete   POST 軟刪除（version 樂觀鎖）
 *   history  GET  單筆異動紀錄
 *   release  POST 離開頁面時清除自己的線上 / 編輯中
 */
require_once dirname(__FILE__) . '/BoardConfig.php';
require_once dirname(__FILE__) . '/Availability.php';
require_once dirname(__FILE__) . '/PmSource.php';

class ToolBoardController extends Controller
{
    const POLL_OVERLAP = 5;        // 輪詢游標往前重疊的秒數（前端用 version 去重）
    const MAX_SPAN_DAYS = 90;      // 單一 Action 本體最長天數
    const MAX_TAIL_HOURS = 999;    // Monitor / Pilot 時數上限

    // ==================================================================
    // 頁面
    // ==================================================================

    public function indexAction()
    {
        $error = '';
        $models = array();
        $shared = null;
        try {
            $shared = ToolBoardConfig::shared();
            foreach ($shared['models'] as $m => $label) {
                $editable = false;
                $modelError = '';
                try {
                    $editable = $this->canEdit($m);
                } catch (ToolBoardConfigException $e) {
                    $modelError = $e->getMessage();
                }
                $models[] = array('key' => $m, 'label' => $label, 'canEdit' => $editable, 'error' => $modelError);
            }
            $dups = ToolBoardConfig::duplicateTools();
            if ($dups) {
                $error = '機台重複列在多個機型：' . implode('；', $dups);
            }
        } catch (ToolBoardConfigException $e) {
            $error = $e->getMessage();
        }

        $urls = array();
        foreach (array('board', 'poll', 'save', 'move', 'delete', 'history', 'release') as $a) {
            $urls[$a] = url($this->module, $a);
        }
        $this->render('index', array(
            'error'  => $error,
            'models' => $models,
            'config' => array(
                'urls'        => $urls,
                'models'      => $models,
                'model'       => strtoupper($this->query('model')),
                'me'          => array('id' => (int) $this->user['id'], 'name' => $this->user['display_name']),
                'pollSeconds' => $shared ? $shared['poll_seconds'] : 5,
                'idleSeconds' => $shared ? $shared['idle_seconds'] : 600,
                'isAdmin'     => Acl::isSuperuser($this->user['role']),
            ),
        ));
    }

    // ==================================================================
    // 讀取
    // ==================================================================

    public function boardAction()
    {
        $model = $this->modelParam();
        if ($model === null) {
            return;
        }
        try {
            $cfg = ToolBoardConfig::forModel($model);
            $shared = ToolBoardConfig::shared();
        } catch (ToolBoardConfigException $e) {
            return $this->json(array('ok' => false, 'error' => $e->getMessage()), 500);
        }
        $win = $this->window();
        $actions = $this->windowActions($model, $win);
        $pm = ToolBoardPm::forModel($shared['pm_path'], $cfg['tools']);
        if (!Acl::isSuperuser($this->user['role'])) {
            $pm['reasons'] = array();   // 略過原因只給 ADMIN 看
        }

        $categories = array();
        foreach ($cfg['categories'] as $code => $c) {
            $c['code'] = $code;
            $categories[] = $c;
        }
        $wip = array();
        foreach ($cfg['wip'] as $code => $w) {
            $w['code'] = $code;
            $wip[] = $w;
        }

        $this->json(array(
            'ok'           => true,
            'model'        => $model,
            'label'        => $cfg['label'],
            'today'        => $win['today'],
            'firstDay'     => $win['first'],
            'days'         => $win['days'],
            'daysBefore'   => $shared['days_before'],
            'cellWidth'    => $shared['cell_width'],
            'startTime'    => $shared['default_start_time'],
            'tools'        => $cfg['tools'],
            'categories'   => $categories,
            'wip'          => $wip,
            'pmCategory'   => $shared['pm_category'],
            'availability' => $this->availability($cfg, $actions, $win),
            'actions'      => array_values($actions),
            'pm'           => $pm,
            'canEdit'      => $this->canEdit($model),
            'cursor'       => $this->cursor(),
            'serverTime'   => date('Y-m-d H:i:s'),
        ));
    }

    public function pollAction()
    {
        $model = $this->modelParam();
        if ($model === null) {
            return;
        }
        try {
            $cfg = ToolBoardConfig::forModel($model);
            $shared = ToolBoardConfig::shared();
        } catch (ToolBoardConfigException $e) {
            return $this->json(array('ok' => false, 'error' => $e->getMessage()), 500);
        }
        $uid = (int) $this->user['id'];
        $now = now();
        $win = $this->window();

        // 自己的線上 / 編輯中
        $editing = (int) $this->query('editing');
        $mode = $this->query('mode');
        if (!in_array($mode, array('edit', 'drag'), true) || $editing <= 0) {
            $mode = 'view';
            $editing = 0;
        }
        Database::execute(
            'REPLACE INTO tool_board_presence (user_id, model, action_id, mode, seen_at) VALUES (?, ?, ?, ?, ?)',
            array($uid, $model, $editing > 0 ? $editing : null, $mode, $now)
        );

        // 游標之後有變動的 Action（含刪除）
        $changed = array();
        $deleted = array();
        $since = $this->query('since');
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            foreach (Database::fetchAll(
                self::rowSelect() . ' WHERE a.model = ? AND a.updated_at >= ? ORDER BY a.id',
                array($model, $since)
            ) as $r) {
                $r = $this->castRow($r);
                if ($r['deleted']) {
                    $deleted[] = $r['id'];
                } else {
                    $changed[] = $r;
                }
            }
        }

        $res = array(
            'ok'      => true,
            'today'   => $win['today'],
            'cursor'  => $this->cursor(),
            'changed' => $changed,
            'deleted' => $deleted,
            'canEdit' => $this->canEdit($model),
        );
        if ($changed || $deleted || $this->query('avail') === '1') {
            $res['availability'] = $this->availability($cfg, $this->windowActions($model, $win), $win);
        }
        $presence = $this->presence($model, $shared['presence_seconds']);
        $res['online'] = $presence['online'];
        $res['editors'] = (object) $presence['editors'];

        // PM 檔有變動才回傳整份
        if (ToolBoardPm::signature($shared['pm_path']) !== $this->query('pm_sig')) {
            $pm = ToolBoardPm::forModel($shared['pm_path'], $cfg['tools']);
            if (!Acl::isSuperuser($this->user['role'])) {
                $pm['reasons'] = array();
            }
            $res['pm'] = $pm;
        }
        $this->json($res);
    }

    public function historyAction()
    {
        $id = (int) $this->query('id');
        $row = Database::fetchOne('SELECT model FROM tool_board_actions WHERE id = ?', array($id));
        if (!$row) {
            return $this->json(array('ok' => false, 'error' => '找不到這筆 Action'), 404);
        }
        $logs = array();
        foreach (Database::fetchAll(
            'SELECT l.op, l.detail, l.created_at, COALESCE(NULLIF(u.display_name, \'\'), u.account) AS by_name
             FROM tool_board_action_log l LEFT JOIN users u ON u.id = l.user_id
             WHERE l.action_id = ? ORDER BY l.id DESC LIMIT 100',
            array($id)
        ) as $l) {
            $logs[] = array(
                'op'     => $l['op'],
                'at'     => $l['created_at'],
                'by'     => $l['by_name'] === null ? '-' : $l['by_name'],
                'detail' => json_decode($l['detail'], true),
            );
        }
        $this->json(array('ok' => true, 'logs' => $logs));
    }

    // ==================================================================
    // 寫入
    // ==================================================================

    public function saveAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $id = (int) $this->input('id');
        $data = array(
            'model'         => strtoupper($this->input('model')),
            'tool_id'       => strtoupper($this->input('tool_id')),
            'category'      => $this->input('category'),
            'wip_mode'      => $this->input('wip_mode'),
            'start_at'      => $this->normalizeDt($this->input('start_at')),
            'end_at'        => $this->normalizeDt($this->input('end_at')),
            'need_monitor'  => $this->input('need_monitor') === '1' ? 1 : 0,
            'monitor_hours' => $this->input('monitor_hours'),
            'need_pilot'    => $this->input('need_pilot') === '1' ? 1 : 0,
            'pilot_hours'   => $this->input('pilot_hours'),
            'remark'        => $this->input('remark'),
            'pm_type'       => $this->input('pm_type'),
        );
        return $this->write($id, $data, $id > 0 ? 'update' : 'create');
    }

    public function moveAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $id = (int) $this->input('id');
        if ($id <= 0) {
            return $this->json(array('ok' => false, 'error' => '缺少 Action'), 422);
        }
        // 拖曳只改起訖，其他欄位沿用資料庫的值（在 write() 內鎖定後讀取）
        return $this->write($id, array(
            'start_at' => $this->normalizeDt($this->input('start_at')),
            'end_at'   => $this->normalizeDt($this->input('end_at')),
        ), 'move');
    }

    public function deleteAction()
    {
        if (!$this->isPost()) {
            return $this->json(array('ok' => false, 'error' => 'POST only'), 405);
        }
        $id = (int) $this->input('id');
        $version = (int) $this->input('version');
        $row = Database::fetchOne('SELECT model, tool_id FROM tool_board_actions WHERE id = ?', array($id));
        if (!$row) {
            return $this->conflict($id);
        }
        if (!$this->canEdit($row['model'])) {
            return $this->json(array('ok' => false, 'error' => '你沒有編輯這個機型的權限'), 403);
        }
        $win = $this->window();
        $now = now();
        $uid = (int) $this->user['id'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $old = Database::fetchOne('SELECT * FROM tool_board_actions WHERE id = ? FOR UPDATE', array($id));
            if (!$old || $old['deleted_at'] !== null || (int) $old['version'] !== $version) {
                $pdo->rollBack();
                return $this->conflict($id);
            }
            if ($old['occupied_end'] <= $win['start']) {
                $pdo->rollBack();
                return $this->json(array('ok' => false, 'error' => '這筆已超過可修改的期間（今天往前 ' . $win['before'] . ' 天）'), 422);
            }
            Database::execute(
                'UPDATE tool_board_actions SET deleted_at = ?, deleted_by = ?, updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?',
                array($now, $uid, $uid, $now, $id)
            );
            $this->log($id, 'delete', array('before' => $this->snapshot($old)), $uid, $now);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Database::execute('UPDATE tool_board_presence SET action_id = NULL, mode = \'view\' WHERE action_id = ?', array($id));
        audit($this->module, 'delete', array('id' => $id, 'tool_id' => $old['tool_id']));
        $this->json(array('ok' => true, 'id' => $id));
    }

    public function releaseAction()
    {
        Database::execute('DELETE FROM tool_board_presence WHERE user_id = ?', array($this->user['id']));
        if (App::isAjax()) {
            $this->json(array('ok' => true));
        }
    }

    // ==================================================================
    // 內部：寫入流程
    // ==================================================================

    /**
     * 新增 / 修改 / 拖曳共用：權限 → 鎖機台 → 鎖這一筆比對 version → 驗證 → 重疊檢查 → 寫入
     * @param string $op create / update / move
     */
    private function write($id, $data, $op)
    {
        $version = (int) $this->input('version');
        $overwrite = $this->input('overwrite') === '1';

        // 先確認機型與機台（修改 / 拖曳以資料庫為準，機台不能換）
        if ($id > 0) {
            $base = Database::fetchOne('SELECT model, tool_id FROM tool_board_actions WHERE id = ?', array($id));
            if (!$base) {
                return $this->conflict($id);
            }
            $model = $base['model'];
            $tool = $base['tool_id'];
        } else {
            $model = $data['model'];
            $tool = $data['tool_id'];
        }
        try {
            $cfg = ToolBoardConfig::forModel($model);
        } catch (ToolBoardConfigException $e) {
            return $this->json(array('ok' => false, 'error' => $e->getMessage()), 422);
        }
        if (!$this->canEdit($model)) {
            return $this->json(array('ok' => false, 'error' => '你沒有編輯 ' . $cfg['label'] . ' 的權限'), 403);
        }
        if (!in_array($tool, $cfg['tools'], true)) {
            return $this->json(array('ok' => false, 'error' => '機台 ' . $tool . ' 不在 ' . $cfg['label'] . ' 的機台清單'), 422);
        }

        $win = $this->window();
        $now = now();
        $uid = (int) $this->user['id'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // 1. 鎖機台：同一台的寫入排隊，重疊檢查才不會兩個人同時通過
            Database::execute('INSERT IGNORE INTO tool_board_locks (model, tool_id) VALUES (?, ?)', array($model, $tool));
            Database::fetchOne('SELECT tool_id FROM tool_board_locks WHERE model = ? AND tool_id = ? FOR UPDATE', array($model, $tool));

            // 2. 鎖這一筆比對 version
            $old = null;
            if ($id > 0) {
                $old = Database::fetchOne('SELECT * FROM tool_board_actions WHERE id = ? FOR UPDATE', array($id));
                if (!$old || $old['deleted_at'] !== null || (int) $old['version'] !== $version) {
                    $pdo->rollBack();
                    return $this->conflict($id);
                }
                if ($op === 'move') {
                    // 拖曳只改時間，其他欄位用資料庫目前的值
                    $data = array_merge(array(
                        'model' => $old['model'], 'tool_id' => $old['tool_id'], 'category' => $old['category'],
                        'wip_mode' => $old['wip_mode'], 'need_monitor' => (int) $old['need_monitor'],
                        'monitor_hours' => $old['monitor_hours'], 'need_pilot' => (int) $old['need_pilot'],
                        'pilot_hours' => $old['pilot_hours'], 'remark' => $old['remark'], 'pm_type' => $old['pm_type'],
                    ), $data);
                }
                $data['model'] = $old['model'];
                $data['tool_id'] = $old['tool_id'];
            }

            // 3. 驗證
            $error = $this->validate($data, $old, $cfg, $win, $op === 'move');
            if ($error !== '') {
                $pdo->rollBack();
                return $this->json(array('ok' => false, 'error' => $error), 422);
            }

            // 4. 同台重疊（含 Monitor / Pilot 尾巴）
            $hit = Database::fetchOne(
                self::rowSelect() . ' WHERE a.model = ? AND a.tool_id = ? AND a.deleted_at IS NULL AND a.id <> ?
                   AND a.start_at < ? AND a.occupied_end > ? ORDER BY a.start_at LIMIT 1',
                array($model, $tool, $id, $data['occupied_end'], $data['start_at'])
            );
            if ($hit) {
                $pdo->rollBack();
                $hit = $this->castRow($hit);
                $cat = isset($cfg['categories'][$hit['category']]) ? $cfg['categories'][$hit['category']]['label'] : $hit['category'];
                return $this->json(array(
                    'ok'      => false,
                    'overlap' => true,
                    'error'   => '與 ' . $tool . ' 的「' . $cat . '」（' . substr($hit['start_at'], 5, 11) . ' ～ '
                        . substr($hit['occupied_end'], 5, 11) . '，含 Monitor / Pilot）時間重疊',
                    'action'  => $hit,
                ), 422);
            }

            // 5. 寫入
            $values = array(
                $data['category'], $data['wip_mode'], $data['start_at'], $data['end_at'],
                $data['need_monitor'], $data['monitor_hours'], $data['need_pilot'], $data['pilot_hours'],
                $data['occupied_end'], $data['remark'], $data['pm_type'],
            );
            if (!$old) {
                Database::execute(
                    'INSERT INTO tool_board_actions (category, wip_mode, start_at, end_at, need_monitor, monitor_hours,
                        need_pilot, pilot_hours, occupied_end, remark, pm_type, model, tool_id, version,
                        created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
                    array_merge($values, array($model, $tool, $uid, $uid, $now, $now))
                );
                $id = (int) Database::lastInsertId();
                $this->log($id, 'create', array('after' => $this->snapshot($data)), $uid, $now);
            } else {
                Database::execute(
                    'UPDATE tool_board_actions SET category = ?, wip_mode = ?, start_at = ?, end_at = ?, need_monitor = ?,
                        monitor_hours = ?, need_pilot = ?, pilot_hours = ?, occupied_end = ?, remark = ?, pm_type = ?,
                        version = version + 1, updated_by = ?, updated_at = ?
                     WHERE id = ?',
                    array_merge($values, array($uid, $now, $id))
                );
                $this->log($id, $op, array(
                    'before'    => $this->snapshot($old),
                    'after'     => $this->snapshot($data),
                    'overwrite' => $overwrite,
                ), $uid, $now);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        audit($this->module, $overwrite ? $op . '_overwrite' : $op, array(
            'id' => $id, 'model' => $model, 'tool_id' => $tool, 'start_at' => $data['start_at'], 'end_at' => $data['end_at'],
        ));
        $this->json(array('ok' => true, 'action' => $this->findAction($id)));
    }

    /** 欄位檢查與正規化（會補上 occupied_end）；$timeOnly = 拖曳 */
    private function validate(&$d, $old, $cfg, $win, $timeOnly = false)
    {
        // 拖曳只改時間：類別 / WIP 沿用原值，即使代碼已從設定拿掉也允許
        if (!$timeOnly && !isset($cfg['categories'][$d['category']])) {
            return '請選擇 Action 類別';
        }
        if (!$timeOnly && !isset($cfg['wip'][$d['wip_mode']])) {
            // 修改時沿用已從設定拿掉的舊代碼也不行，必須改選現有選項
            return '請勾選 WIP 處理方式（' . $cfg['label'] . ' 的選項）';
        }
        if ($d['start_at'] === null || $d['end_at'] === null) {
            return '開始 / 結束時間格式需為 YYYY-MM-DD HH:MM';
        }
        $s = strtotime($d['start_at']);
        $e = strtotime($d['end_at']);
        if ($e <= $s) {
            return '結束時間必須晚於開始時間';
        }
        if ($e - $s > self::MAX_SPAN_DAYS * 86400) {
            return '單一 Action 最長 ' . self::MAX_SPAN_DAYS . ' 天';
        }
        // 開始時間不得早於視窗起點（今天往前 N 天）；修改時開始時間沒變則不限制
        $startUnchanged = $old && $old['start_at'] === $d['start_at'];
        if (!$startUnchanged && $d['start_at'] < $win['start']) {
            return '開始時間不能早於 ' . substr($win['start'], 0, 10) . '（今天往前 ' . $win['before'] . ' 天）';
        }
        if ($d['start_at'] >= $win['end']) {
            return '開始時間超出看板範圍（最晚 ' . date('Y-m-d', strtotime($win['end']) - 86400) . '）';
        }
        if ($old && $old['occupied_end'] <= $win['start']) {
            return '這筆已超過可修改的期間';
        }

        foreach (array('monitor', 'pilot') as $k) {
            if ($d['need_' . $k]) {
                $h = $d[$k . '_hours'];
                if (!is_numeric($h) || (float) $h < 0.5 || (float) $h > self::MAX_TAIL_HOURS) {
                    return ($k === 'monitor' ? 'Monitor' : 'Pilot') . ' 時數需為 0.5 ～ ' . self::MAX_TAIL_HOURS;
                }
                $d[$k . '_hours'] = round((float) $h, 1);
            } else {
                $d[$k . '_hours'] = null;
            }
        }
        $d['remark'] = $this->usub((string) $d['remark'], 500);
        $d['pm_type'] = trim((string) $d['pm_type']) === '' ? null : $this->usub(trim((string) $d['pm_type']), 64);

        $tail = ($d['need_monitor'] ? $d['monitor_hours'] : 0) + ($d['need_pilot'] ? $d['pilot_hours'] : 0);
        $d['occupied_end'] = date('Y-m-d H:i:s', $e + (int) round($tail * 3600));
        return '';
    }

    // ==================================================================
    // 內部：查詢
    // ==================================================================

    /** 時間視窗：今天往前 days_before 天 00:00 ～ 往後 days_after 天的隔天 00:00（不含） */
    private function window()
    {
        $s = ToolBoardConfig::shared();
        $today = date('Y-m-d');
        $first = date('Y-m-d', strtotime($today . ' -' . $s['days_before'] . ' day'));
        $days = $s['days_before'] + 1 + $s['days_after'];
        return array(
            'today'  => $today,
            'first'  => $first,
            'days'   => $days,
            'before' => $s['days_before'],
            'start'  => $first . ' 00:00:00',
            'end'    => date('Y-m-d', strtotime($first . ' +' . $days . ' day')) . ' 00:00:00',
        );
    }

    /** 時間視窗內（含尾巴延伸進來的）未刪除 Action：id => row */
    private function windowActions($model, $win)
    {
        $out = array();
        foreach (Database::fetchAll(
            self::rowSelect() . ' WHERE a.model = ? AND a.deleted_at IS NULL AND a.start_at < ? AND a.occupied_end > ?
             ORDER BY a.tool_id, a.start_at',
            array($model, $win['end'], $win['start'])
        ) as $r) {
            $r = $this->castRow($r);
            $out[$r['id']] = $r;
        }
        return $out;
    }

    private function availability($cfg, $actions, $win)
    {
        $a = ToolBoardAvailability::compute($cfg, $actions, $win['first'], $win['days']);
        $a['warn'] = $cfg['availability']['warn_below'];
        $a['alert'] = $cfg['availability']['alert_below'];
        $a['tools'] = count($cfg['tools']);
        return $a;
    }

    private static function rowSelect()
    {
        return 'SELECT a.id, a.model, a.tool_id, a.category, a.wip_mode, a.start_at, a.end_at, a.need_monitor, a.monitor_hours,
                    a.need_pilot, a.pilot_hours, a.occupied_end, a.remark, a.pm_type, a.version,
                    a.created_at, a.updated_at, a.deleted_at,
                    COALESCE(NULLIF(cu.display_name, \'\'), cu.account) AS created_by_name,
                    COALESCE(NULLIF(uu.display_name, \'\'), uu.account) AS updated_by_name,
                    COALESCE(NULLIF(du.display_name, \'\'), du.account) AS deleted_by_name
             FROM tool_board_actions a
             LEFT JOIN users cu ON cu.id = a.created_by
             LEFT JOIN users uu ON uu.id = a.updated_by
             LEFT JOIN users du ON du.id = a.deleted_by';
    }

    private function castRow($r)
    {
        $r['id'] = (int) $r['id'];
        $r['version'] = (int) $r['version'];
        $r['need_monitor'] = (bool) $r['need_monitor'];
        $r['need_pilot'] = (bool) $r['need_pilot'];
        $r['monitor_hours'] = $r['monitor_hours'] === null ? null : (float) $r['monitor_hours'];
        $r['pilot_hours'] = $r['pilot_hours'] === null ? null : (float) $r['pilot_hours'];
        $r['deleted'] = $r['deleted_at'] !== null;
        foreach (array('created_by_name', 'updated_by_name', 'deleted_by_name') as $k) {
            if ($r[$k] === null) {
                $r[$k] = '-';
            }
        }
        if ($r['pm_type'] === null) {
            $r['pm_type'] = '';
        }
        return $r;
    }

    private function findAction($id)
    {
        $r = Database::fetchOne(self::rowSelect() . ' WHERE a.id = ?', array($id));
        return $r ? $this->castRow($r) : null;
    }

    /** version 不符或已被刪除：回 409 並附上最新資料 */
    private function conflict($id)
    {
        $current = $this->findAction($id);
        if (!$current) {
            return $this->json(array('ok' => false, 'conflict' => true, 'reason' => 'gone', 'error' => '找不到這筆 Action'), 409);
        }
        if ($current['deleted']) {
            return $this->json(array(
                'ok' => false, 'conflict' => true, 'reason' => 'deleted', 'action' => $current,
                'error' => '這筆 Action 已被 ' . $current['deleted_by_name'] . ' 於 ' . substr($current['deleted_at'], 5, 11) . ' 刪除',
            ), 409);
        }
        return $this->json(array(
            'ok'       => false,
            'conflict' => true,
            'reason'   => 'modified',
            'error'    => '這筆 Action 剛被 ' . $current['updated_by_name'] . ' 於 ' . substr($current['updated_at'], 5, 11) . ' 修改',
            'action'   => $current,
        ), 409);
    }

    /** 同機型線上的人、誰正在編輯 / 拖曳哪一筆（不含自己） */
    private function presence($model, $seconds)
    {
        $online = array();
        $editors = array();
        foreach (Database::fetchAll(
            'SELECT p.action_id, p.mode, COALESCE(NULLIF(u.display_name, \'\'), u.account) AS name
             FROM tool_board_presence p JOIN users u ON u.id = p.user_id
             WHERE p.model = ? AND p.seen_at >= ? AND p.user_id <> ? ORDER BY p.seen_at',
            array($model, date('Y-m-d H:i:s', time() - $seconds), $this->user['id'])
        ) as $r) {
            $online[] = $r['name'];
            if ($r['action_id'] !== null) {
                $editors[$r['action_id']][] = array('name' => $r['name'], 'mode' => $r['mode']);
            }
        }
        return array('online' => $online, 'editors' => $editors);
    }

    // ==================================================================
    // 內部：小工具
    // ==================================================================

    /** 可否編輯該機型：ADMIN 全部；EE 須列在機型檔 [editors] */
    private function canEdit($model)
    {
        $role = $this->user['role'];
        if (Acl::isSuperuser($role)) {
            return true;
        }
        if ($role !== 'EE' || !Acl::canAccess($role, $this->module, 'save')) {
            return false;
        }
        $cfg = ToolBoardConfig::forModel($model);
        return isset($cfg['editors'][strtolower($this->user['account'])]);
    }

    /** ?model=：必須是設定內的機型，錯誤時直接回 JSON 並回傳 null */
    private function modelParam()
    {
        $model = strtoupper($this->query('model'));
        try {
            $models = ToolBoardConfig::models();
        } catch (ToolBoardConfigException $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 500);
            return null;
        }
        if (!isset($models[$model])) {
            $this->json(array('ok' => false, 'error' => '沒有這個機型：' . $model), 404);
            return null;
        }
        return $model;
    }

    /** 'YYYY-MM-DD HH:MM[:SS]'（或 T 分隔）→ 'Y-m-d H:i:00'；格式錯誤回傳 null */
    private function normalizeDt($s)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/', trim($s), $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
            return null;
        }
        return sprintf('%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5]);
    }

    /** 異動紀錄裡保留的欄位 */
    private function snapshot($r)
    {
        $out = array();
        foreach (array('category', 'wip_mode', 'start_at', 'end_at', 'need_monitor', 'monitor_hours',
            'need_pilot', 'pilot_hours', 'remark', 'pm_type') as $k) {
            $out[$k] = isset($r[$k]) ? $r[$k] : null;
        }
        $out['need_monitor'] = (bool) $out['need_monitor'];
        $out['need_pilot'] = (bool) $out['need_pilot'];
        foreach (array('monitor_hours', 'pilot_hours') as $k) {
            $out[$k] = $out[$k] === null ? null : (float) $out[$k];
        }
        return $out;
    }

    private function log($actionId, $op, $detail, $uid, $now)
    {
        Database::execute(
            'INSERT INTO tool_board_action_log (action_id, user_id, op, detail, created_at) VALUES (?, ?, ?, ?, ?)',
            array($actionId, $uid, $op, json_encode($detail), $now)
        );
    }

    private function cursor()
    {
        return date('Y-m-d H:i:s', time() - self::POLL_OVERLAP);
    }

    private function usub($s, $length)
    {
        return preg_match('/^.{0,' . (int) $length . '}/us', $s, $m) ? $m[0] : '';
    }
}
