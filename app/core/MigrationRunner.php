<?php
/**
 * ============================================================
 * MigrationRunner.php v1.0.0 — 数据库迁移任务状态管理（后台运行）
 * ============================================================
 * 说明：
 * 1. 迁移以独立后台进程执行（tools/cli/db_migrate_run.php），
 *    Web 请求只负责启动/轮询/取消，刷新页面不中断迁移。
 * 2. 任务状态与进度写入 config.db（migration.state JSON），
 *    前端轮询 migration_status 展示进度条。
 * 3. 迁移期间 config.db 置 migrating 标志，全站请求（public/index.php
 *    顶部）拦截并输出锁定页（进度 + 重新登录 + 管理员取消）。
 * 4. 取消：置 cancel_requested；后台脚本在完成当前表后停止；
 *    config.db 主库指针仅在迁移成功后才更新，取消/失败回退原库。
 * ============================================================ */

class MigrationRunner {

    /** 状态键 */
    const KEY = 'migration.state';

    /** 默认空状态 */
    private static function emptyState() {
        return array(
            'status' => 'idle',          // idle/running/done/cancelled/failed
            'from' => '',
            'to' => '',
            'to_params' => array(),
            'total_tables' => 0,
            'done_tables' => 0,
            'total_rows' => 0,
            'done_rows' => 0,
            'current_table' => '',
            'started_at' => '',
            'finished_at' => '',
            'cancel_requested' => false,
            'error' => '',
            'token' => '',               // 管理员取消令牌
        );
    }

    /** 读取当前状态（无任务返回 idle 空态） */
    public static function state() {
        $raw = ConfigStore::get(self::KEY, '');
        if ($raw === '') return self::emptyState();
        $s = json_decode((string)$raw, true);
        return is_array($s) ? array_merge(self::emptyState(), $s) : self::emptyState();
    }

    /** 写入状态 */
    public static function save($s) {
        ConfigStore::set(self::KEY, json_encode($s, JSON_UNESCAPED_UNICODE));
        ConfigStore::resetCache();
    }

    /** 是否正在迁移（Web 全站锁定判断） */
    public static function isMigrating() {
        return self::state()['status'] === 'running';
    }

    /** 是否需全站锁定（迁移进行中 或 迁移完成待确认切换） */
    public static function isLocked() {
        return in_array(self::state()['status'], array('running', 'done'), true);
    }

    /**
     * 启动后台迁移任务
     * @param string $from      源驱动
     * @param string $to        目标驱动
     * @param array  $toParams  目标连接参数
     * @return array {ok, msg, token}
     */
    public static function start($from, $to, $toParams) {
        if (self::isMigrating()) {
            return array('ok' => false, 'msg' => '已有迁移任务正在进行中，请等待完成或取消后再试');
        }
        // 管理员取消令牌（锁定页识别管理员显示取消按钮）
        $token = bin2hex(random_bytes(16));
        $s = self::emptyState();
        $s['status'] = 'running';
        $s['from'] = $from;
        $s['to'] = $to;
        $s['to_params'] = $toParams;
        $s['started_at'] = now_str();
        $s['token'] = $token;
        self::save($s);
        // 强制清除全部用户会话（迁移期间避免任何残留读写）
        self::clearAllSessions();

        // 后台启动 CLI 迁移脚本（nohup 分离，不等待）
        $script = APP_ROOT . '/tools/cli/db_migrate_run.php';
        if (!is_file($script)) {
            self::fail('迁移脚本缺失：' . $script);
            return array('ok' => false, 'msg' => '迁移脚本缺失，无法启动');
        }
        $runner = self::phpBinary();
        $args = array_merge(array($runner, 'php-cli', $script, escapeshellarg($token)));
        $cmd = 'nohup ' . implode(' ', $args) . ' > /dev/null 2>&1 &';
        $ok = @pclose(@popen($cmd, 'r'));
        if ($ok === false) {
            // 后台启动失败：直接前台执行（仍可用，但请求会阻塞）
            self::save($s);
            return array('ok' => true, 'token' => $token, 'foreground' => true, 'msg' => '已启动迁移（前台模式）');
        }
        return array('ok' => true, 'token' => $token, 'msg' => '迁移已启动，全站将进入锁定维护');
    }

    /** 取消迁移（管理员令牌校验） */
    public static function cancel($token) {
        $s = self::state();
        if ($s['status'] !== 'running') {
            return array('ok' => false, 'msg' => '当前没有进行中的迁移');
        }
        if ($s['token'] === '' || !hash_equals((string)$s['token'], (string)$token)) {
            return array('ok' => false, 'msg' => '取消令牌无效，请刷新后重试');
        }
        $s['cancel_requested'] = true;
        self::save($s);
        return array('ok' => true, 'msg' => '已请求取消迁移，将在当前表完成后回退原库');
    }

    /** 迁移完成（成功）：仅置 done，主库指针保持原库（由管理员确认后再切换） */
    public static function success($s) {
        $s['status'] = 'done';
        $s['finished_at'] = now_str();
        $s['token'] = '';
        self::save($s);
    }

    /** 确认切换主库到迁移目标（迁移成功后由管理员确认） */
    public static function switchMain() {
        $s = self::state();
        if ($s['status'] !== 'done') {
            return array('ok' => false, 'msg' => '没有待确认的迁移结果');
        }
        $to = $s['to'];
        $params = $s['to_params'];
        ConfigStore::set('db.driver', $to);
        if ($to === 'mysql' || $to === 'pgsql') {
            foreach (array('host', 'port', 'dbname', 'user', 'pass') as $k) {
                if (isset($params[$k])) {
                    ConfigStore::set('db.' . $to . '.' . $k, $params[$k]);
                }
            }
            ConfigStore::set('db.sqlite.path', '');
        } else {
            ConfigStore::set('db.sqlite.path', isset($params['path']) ? $params['path'] : '');
        }
        self::confirm();   // 清除迁移状态
        return array('ok' => true, 'msg' => '主数据库已切换到 ' . strtoupper($to));
    }

    /** 迁移失败/取消：状态置 failed/cancelled，主库指针不变（回退原库） */
    public static function fail($s, $err) {
        $s['status'] = 'failed';
        $s['error'] = $err;
        $s['finished_at'] = now_str();
        $s['token'] = '';
        self::save($s);
    }
    public static function cancelled($s) {
        $s['status'] = 'cancelled';
        $s['finished_at'] = now_str();
        $s['token'] = '';
        self::save($s);
    }

    /** 确认并清除迁移状态（管理员/成功后） */
    public static function confirm() {
        ConfigStore::set(self::KEY, '');
        ConfigStore::resetCache();
    }

    /** 清除全部用户会话（迁移/切换数据库时强制所有人重新登录，避免残留读写） */
    public static function clearAllSessions() {
        $dir = DATA_DIR . '/session';
        if (!is_dir($dir)) return 0;
        $n = 0;
        foreach (glob($dir . '/sess_*') ?: array() as $f) {
            if (@unlink($f)) $n++;
        }
        return $n;
    }

    /**
     * 直接切换主库（不迁移数据，目标库须已有完整数据）
     * 校验目标库 users 表非空 → 强制清除全部会话 → 更新 config.db 指针
     * @return array {ok, msg}
     */
    public static function switchDirect($toDriver, $toParams) {
        if (self::isLocked()) {
            return array('ok' => false, 'msg' => '当前有迁移/切换任务进行中，请等待完成');
        }
        // 校验目标库连接 + 已有数据（users 表非空）
        try {
            $dst = self::connectForCheck($toDriver, $toParams);
            $hasUsers = false;
            if ($toDriver === 'sqlite') {
                $hasUsers = (int)$dst->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
            } else {
                try {
                    $hasUsers = (int)$dst->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
                } catch (Exception $ex) { $hasUsers = false; }
            }
            if (!$hasUsers) {
                return array('ok' => false, 'msg' => '目标数据库未检测到已安装数据（users 表为空），请先迁移或确认数据库完整');
            }
        } catch (Exception $ex) {
            return array('ok' => false, 'msg' => '目标数据库连接失败：' . $ex->getMessage());
        }
        // 切换：清会话 → 更新指针
        self::clearAllSessions();
        ConfigStore::set('db.driver', $toDriver);
        if ($toDriver === 'mysql' || $toDriver === 'pgsql') {
            foreach (array('host', 'port', 'dbname', 'user', 'pass') as $k) {
                if (isset($toParams[$k])) {
                    ConfigStore::set('db.' . $toDriver . '.' . $k, $toParams[$k]);
                }
            }
            ConfigStore::set('db.sqlite.path', '');
        } else {
            ConfigStore::set('db.sqlite.path', isset($toParams['path']) ? $toParams['path'] : '');
        }
        ConfigStore::resetCache();
        return array('ok' => true, 'msg' => '主库已切换到 ' . strtoupper($toDriver) . '，已强制清除全部会话，请重新登录');
    }

    /** 目标连接（切换校验用，独立连接） */
    private static function connectForCheck($driver, $p) {
        if ($driver === 'mysql') {
            $dsn = 'mysql:host=' . $p['host'] . ';port=' . $p['port'] . ';dbname=' . $p['dbname'] . ';charset=utf8mb4';
            return new PDO($dsn, $p['user'], $p['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_CONNECT_TIMEOUT => 5));
        }
        if ($driver === 'pgsql') {
            $dsn = 'pgsql:host=' . $p['host'] . ';port=' . $p['port'] . ';dbname=' . $p['dbname'];
            return new PDO($dsn, $p['user'], $p['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_CONNECT_TIMEOUT => 5));
        }
        $path = isset($p['path']) && $p['path'] !== '' ? $p['path'] : DATA_DIR . '/db/clinic_main.db';
        return new PDO('sqlite:' . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    }

    /** PHP 运行器（frankenphp php-cli 优先，回退系统 php） */
    private static function phpBinary() {
        foreach (array('~/.local/bin/frankenphp', '/usr/local/bin/frankenphp', '/opt/homebrew/bin/frankenphp') as $p) {
            $p = str_replace('~', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '', $p);
            if (is_file($p)) return $p;
        }
        return 'frankenphp';   // PATH 回退
    }
}