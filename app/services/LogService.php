<?php
/**
 * ============================================================
 * services/LogService.php — 统一系统日志服务（日志中心核心）
 * ============================================================
 * 说明：日志中心唯一读写入口，统一管理与落账三类日志：
 *  1. 服务器日志 server   —— 直接读取 PHP 日志文件（应用日志 data/logs/app.log
 *                             + 管理员配置的外部 PHP/Web 服务器 error_log 路径），
 *                             不入库，按行尾分页读取。
 *  2. 操作日志 operation —— 用户登录/退出、账号变更（改密/改资料/解锁）等，
 *                             写入 system_logs 表。
 *  3. 接口日志 interface —— FHIR/DICOM/HL7/LIS/HIS/医保支付/存证签名等
 *                             入向与出向调用，写入 system_logs 表。
 *
 * 日志管理配置（settings 表 log.* 键）：
 *  - log.enabled              总开关（0/1）
 *  - log.channel.operation    操作日志开关
 *  - log.channel.interface    接口日志开关
 *  - log.channel.server       服务器日志开关（仅影响前端是否可读）
 *  - log.max_rows             日志表最大行数（超出自动清理最旧记录）
 *  - log.retention_days       记录保留天数（超期自动清空）
 *  - log.level.*              各日志级别开关（normal/info/warning/error）
 *  - log.server.external_path 外部 PHP/Web 服务器日志文件路径
 *  - log.server.max_kb        日志文件读取上限（KB）
 *
 * 设计约定：写日志绝不影响业务主流程——全部包裹 try/catch 静默降级。
 * ============================================================ */
class LogService {

    /** 日志通道 */
    const CH_SERVER    = 'server';
    const CH_OPERATION = 'operation';
    const CH_INTERFACE = 'interface';

    /** 日志级别（正常/提示/警告/错误） */
    const LEVELS = array('normal', 'info', 'warning', 'error');

    /** 操作日志子分类 */
    const OP_LOGIN   = 'login';     // 登录日志（登录、退出）
    const OP_ACCOUNT = 'account';   // 账号变更（改密码、改个人信息等）

    /** 接口日志子分类（按外部系统划分） */
    const IF_CATEGORIES = array('fhir', 'dicom', 'hl7', 'lis', 'his', 'insurance', 'evid');

    /* ============================================================
     * 日志管理配置读取
     * ============================================================ */

    /** 默认配置 */
    public static function defaults() {
        return array(
            'log.enabled'              => '1',
            'log.channel.operation'    => '1',
            'log.channel.interface'    => '1',
            'log.channel.server'       => '1',
            'log.max_rows'             => '5000',
            'log.retention_days'       => '30',
            'log.server.external_path' => '',
            'log.server.max_kb'        => '1024',
        );
    }

    /** 读取配置（未设置的级别开关默认开启） */
    public static function cfg($key, $default = '') {
        $def = self::defaults();
        if ($key !== '' && isset($def[$key])) $default = $def[$key];
        return (string)setting($key, $default);
    }

    /** 日志总开关是否开启 */
    public static function enabled() {
        return self::cfg('log.enabled', '1') === '1';
    }

    /** 指定通道是否开启 */
    public static function channelEnabled($channel) {
        if (!self::enabled()) return false;
        return self::cfg('log.channel.' . $channel, '1') === '1';
    }

    /** 指定级别是否开启 */
    public static function levelEnabled($level) {
        $level = in_array($level, self::LEVELS, true) ? $level : 'info';
        return self::cfg('log.level.' . $level, '1') === '1';
    }

    /* ============================================================
     * 写入接口日志（system_logs）
     * ============================================================ */

    /**
     * 写入一条系统日志（内部统一入口）
     * @param array $row {
     *   channel, category, direction, action, level, summary, detail,
     *   payload, user_id, username, role, target, remote_ip, user_agent
     * }
     * @return bool 是否写入成功
     */
    public static function write($row) {
        try {
            $channel = isset($row['channel']) ? (string)$row['channel'] : self::CH_OPERATION;
            if (!self::channelEnabled($channel)) return false;
            $level = isset($row['level']) ? (string)$row['level'] : 'info';
            if (!in_array($level, self::LEVELS, true)) $level = 'info';
            if (!self::levelEnabled($level)) return false;

            // 自动补齐当前登录用户上下文（如调用方未显式提供）
            if ((!isset($row['user_id']) || !$row['user_id']) && class_exists('Auth') && Auth::check()) {
                $u = Auth::user();
                $row['user_id']  = (int)$u['id'];
                $row['username'] = isset($u['username']) ? $u['username'] : $u['name'];
                $row['role']     = isset($u['role']) ? $u['role'] : '';
                if ((!isset($row['detail']) || $row['detail'] === '') && isset($u['name'])) {
                    $row['detail'] = '操作人：' . $u['name'];
                }
            }
            if (!isset($row['remote_ip']) || $row['remote_ip'] === '') {
                $row['remote_ip'] = class_exists('LoginSecurity')
                    ? LoginSecurity::clientIp()
                    : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
            }
            if (!isset($row['user_agent']) || $row['user_agent'] === '') {
                $row['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
            }

            DatabaseManager::getMain()->prepare(
                'INSERT INTO system_logs(channel, category, direction, action, level, summary, detail, payload, user_id, username, role, target, remote_ip, user_agent, created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute(array(
                $channel,
                isset($row['category']) ? (string)$row['category'] : '',
                isset($row['direction']) ? (string)$row['direction'] : '',
                isset($row['action']) ? (string)$row['action'] : '',
                $level,
                isset($row['summary']) ? mb_substr((string)$row['summary'], 0, 500) : '',
                isset($row['detail']) ? (string)$row['detail'] : '',
                isset($row['payload']) ? mb_substr((string)$row['payload'], 0, 8000) : '',
                isset($row['user_id']) ? (int)$row['user_id'] : 0,
                isset($row['username']) ? (string)$row['username'] : '',
                isset($row['role']) ? (string)$row['role'] : '',
                isset($row['target']) ? (string)$row['target'] : '',
                (string)$row['remote_ip'],
                (string)$row['user_agent'],
                now_str(),
            ));
            self::maintain($channel, isset($row['category']) ? (string)$row['category'] : '');
            return true;
        } catch (Exception $ex) {
            if (defined('DEBUG') && DEBUG) error_log('[LogService] 写入失败：' . $ex->getMessage());
            return false;
        }
    }

    /** 操作日志便捷写入 */
    public static function operation($category, $action, $summary, $detail = '', $level = 'info', $target = '') {
        return self::write(array(
            'channel'  => self::CH_OPERATION,
            'category' => $category,
            'action'   => $action,
            'level'    => $level,
            'summary'  => $summary,
            'detail'   => $detail,
            'target'   => $target,
        ));
    }

    /** 接口日志便捷写入 */
    public static function interfaceLog($category, $direction, $endpoint, $ok, $summary = '', $payload = '', $detail = '') {
        $category = in_array($category, self::IF_CATEGORIES, true) ? $category : 'his';
        return self::write(array(
            'channel'   => self::CH_INTERFACE,
            'category'  => $category,
            'direction' => $direction === 'outbound' ? 'outbound' : 'inbound',
            'action'    => (string)$endpoint,
            'level'     => $ok ? 'info' : 'warning',
            'summary'   => $summary,
            'detail'    => $detail,
            'payload'   => $payload,
        ));
    }

    /* ============================================================
     * 容量与保留期维护（行数上限 + 超期清理）
     * ============================================================ */

    /** 行数上限 + 保留天数维护（按左侧各子分类独立限行） */
    private static function maintain($channel, $category) {
        // 每日一次：超期清理 + 服务器日志文件维护
        $today = date('Y-m-d');
        if (ConfigStore::get('log.last_purge', '') !== $today) {
            ConfigStore::set('log.last_purge', $today);
            self::purgeExpired();
        }
        self::maintainServerFiles();
        // 行数上限：按 channel+category 独立裁剪（每个子分类各自不超过上限，
        // 而非全站共享——例如接口日志大量写入不会挤掉操作日志）。
        $max = (int)self::cfg('log.max_rows', '5000');
        if ($max < 100) return;
        try {
            $pdo = DatabaseManager::getMain();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM system_logs WHERE channel=? AND category=?');
            $stmt->execute(array($channel, $category));
            $count = (int)$stmt->fetchColumn();
            if ($count <= $max) return;
            $excess = $count - $max;
            $cut = $pdo->query('SELECT id FROM system_logs WHERE channel=' . $pdo->quote($channel)
                . ' AND category=' . $pdo->quote($category)
                . ' ORDER BY id ASC LIMIT 1 OFFSET ' . (int)($excess - 1))->fetchColumn();
            if ($cut !== false) {
                $pdo->prepare('DELETE FROM system_logs WHERE channel=? AND category=? AND id <= ?')
                    ->execute(array($channel, $category, (int)$cut));
            }
        } catch (Exception $ex) {
            if (defined('DEBUG') && DEBUG) error_log('[LogService] 行数清理失败：' . $ex->getMessage());
        }
    }

    /* ============================================================
     * 服务器日志文件维护（应用日志遵循容量与保留设置，外部日志只读不动）
     * ============================================================ */

    /** 每日一次按容量（行数上限）与保留天数维护应用日志文件 */
    public static function maintainServerFiles() {
        $today = date('Y-m-d');
        if (ConfigStore::get('log.server_maintain_date', '') === $today) return;
        ConfigStore::set('log.server_maintain_date', $today);
        self::trimServerFile(self::appLogPath(), (int)self::cfg('log.max_rows', '5000'), (int)self::cfg('log.retention_days', '30'));
    }

    /**
     * 裁剪日志文件：保留天数内的行 + 末尾不超过 maxLines 行
     * @param string $path
     * @param int    $maxLines 0 = 不限
     * @param int    $days     0 = 不限
     */
    public static function trimServerFile($path, $maxLines, $days) {
        if ($path === '' || !is_file($path) || !is_writable($path)) return;
        $size = (int)@filesize($path);
        if ($size <= 0) return;
        $cap = 16 * 1024 * 1024;   // 单次最多处理 16MB（超出只取尾部）
        if ($size > $cap) {
            $fh = @fopen($path, 'rb');
            if (!$fh) return;
            fseek($fh, $size - $cap);
            $raw = fread($fh, $cap);
            fclose($fh);
            $truncated = true;
        } else {
            $raw = @file_get_contents($path);
            $truncated = false;
        }
        if ($raw === false || $raw === '') return;
        $raw = str_replace("\r\n", "\n", $raw);
        $lines = explode("\n", $raw);
        if ($truncated && count($lines)) array_shift($lines);   // 首行可能被截断
        while (count($lines) && trim($lines[count($lines) - 1]) === '') array_pop($lines);

        $drop = 0;
        // 保留天数：按每行时间解析，丢弃 cutoff 之前的最旧行（无法解析的行不用于判定）
        if ($days > 0) {
            $cutoff = time() - $days * 86400;
            for ($i = 0; $i < count($lines); $i++) {
                $ts = self::lineTimestamp($lines[$i]);
                if ($ts === null) continue;
                if ($ts >= $cutoff) { $drop = $i; break; }
                $drop = $i + 1;
            }
        }
        $keep = $drop > 0 ? array_slice($lines, $drop) : $lines;
        $changed = ($drop > 0);
        // 行数上限：保留末尾 maxLines 行
        if ($maxLines > 0 && count($keep) > $maxLines) {
            $keep = array_slice($keep, -$maxLines);
            $changed = true;
        }
        if (!$changed || !$keep) return;
        @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
    }

    /** 解析日志行首时间戳（[..] 或 Y-m-d H:i:s），失败返回 null */
    private static function lineTimestamp($line) {
        if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            $ts = strtotime($m[1]);
            return $ts ? $ts : null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $m)) {
            $ts = strtotime($m[1]);
            return $ts ? $ts : null;
        }
        return null;
    }

    /** 删除超过保留天数的日志 */
    public static function purgeExpired() {
        $days = (int)self::cfg('log.retention_days', '30');
        if ($days <= 0) return 0;
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        try {
            $stmt = DatabaseManager::getMain()->prepare('DELETE FROM system_logs WHERE created_at < ?');
            $stmt->execute(array($cutoff));
            return $stmt->rowCount();
        } catch (Exception $ex) {
            if (defined('DEBUG') && DEBUG) error_log('[LogService] 超期清理失败：' . $ex->getMessage());
            return 0;
        }
    }

    /* ============================================================
     * 日志查询（system_logs）
     * ============================================================ */

    /**
     * 分页查询系统日志（按 id 游标，支持前端「默认最新、上滑加载更旧」）
     * @param string $channel   通道（operation/interface）
     * @param array  $filter    { category, direction, level, kw, before_id }
     * @param int    $limit
     * @return array { list, has_more, max_id }
     */
    public static function query($channel, $filter = array(), $limit = 50) {
        $limit = min(200, max(1, (int)$limit));
        $where = 'channel = ?';
        $params = array($channel);
        if (!empty($filter['category'])) { $where .= ' AND category = ?'; $params[] = $filter['category']; }
        if (!empty($filter['direction'])) { $where .= ' AND direction = ?'; $params[] = $filter['direction']; }
        if (!empty($filter['level'])) { $where .= ' AND level = ?'; $params[] = $filter['level']; }
        if (!empty($filter['date'])) { $where .= ' AND created_at LIKE ?'; $params[] = $filter['date'] . '%'; }
        if (!empty($filter['kw'])) {
            $where .= ' AND (summary LIKE ? OR detail LIKE ? OR action LIKE ? OR username LIKE ? OR target LIKE ?)';
            $like = '%' . $filter['kw'] . '%';
            $params = array_merge($params, array($like, $like, $like, $like, $like));
        }
        if (!empty($filter['before_id'])) { $where .= ' AND id < ?'; $params[] = (int)$filter['before_id']; }
        try {
            $params[] = $limit + 1;
            $stmt = DatabaseManager::getMain()->prepare(
                'SELECT * FROM system_logs WHERE ' . $where . ' ORDER BY id DESC LIMIT ?'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {
            if (defined('DEBUG') && DEBUG) error_log('[LogService] 查询失败：' . $ex->getMessage());
            return array('list' => array(), 'has_more' => false, 'max_id' => 0);
        }
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $maxId = 0;
        if ($row0 = reset($rows)) $maxId = (int)$row0['id'];
        return array('list' => array_values($rows), 'has_more' => $hasMore, 'max_id' => $maxId);
    }

    /** 增量拉取比 after_id 更新的日志（实时刷新，正序返回最新段） */
    public static function latest($channel, $afterId = 0, $limit = 100, $filter = array()) {
        $limit = min(200, max(1, (int)$limit));
        $where = 'channel = ? AND id > ?';
        $params = array($channel, (int)$afterId);
        if (!empty($filter['category'])) { $where .= ' AND category = ?'; $params[] = $filter['category']; }
        if (!empty($filter['direction'])) { $where .= ' AND direction = ?'; $params[] = $filter['direction']; }
        if (!empty($filter['level'])) { $where .= ' AND level = ?'; $params[] = $filter['level']; }
        if (!empty($filter['date'])) { $where .= ' AND created_at LIKE ?'; $params[] = $filter['date'] . '%'; }
        if (!empty($filter['kw'])) {
            $where .= ' AND (summary LIKE ? OR detail LIKE ? OR action LIKE ? OR username LIKE ? OR target LIKE ?)';
            $like = '%' . $filter['kw'] . '%';
            $params = array_merge($params, array($like, $like, $like, $like, $like));
        }
        try {
            $params[] = $limit;
            $stmt = DatabaseManager::getMain()->prepare(
                'SELECT * FROM system_logs WHERE ' . $where . ' ORDER BY id ASC LIMIT ?'
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {
            if (defined('DEBUG') && DEBUG) error_log('[LogService] 增量查询失败：' . $ex->getMessage());
            return array();
        }
    }

    /** 清空系统日志（可按通道/分类定向清空；全部为空则清空整表） */
    public static function clear($channel = '', $category = '') {
        try {
            $pdo = DatabaseManager::getMain();
            if ($channel === '' && $category === '') {
                return (int)$pdo->exec('DELETE FROM system_logs');
            }
            $where = '1=1';
            $params = array();
            if ($channel !== '') { $where .= ' AND channel = ?'; $params[] = $channel; }
            if ($category !== '') { $where .= ' AND category = ?'; $params[] = $category; }
            $stmt = $pdo->prepare('DELETE FROM system_logs WHERE ' . $where);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (Exception $ex) {
            if (defined('DEBUG') && DEBUG) error_log('[LogService] 清空失败：' . $ex->getMessage());
            return 0;
        }
    }

    /** 各分类计数（左侧栏徽标） */
    public static function counts($channel) {
        $out = array();
        try {
            $stmt = DatabaseManager::getMain()->prepare('SELECT category, COUNT(*) AS cnt FROM system_logs WHERE channel = ? GROUP BY category');
            $stmt->execute(array($channel));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['category']] = (int)$r['cnt'];
            }
        } catch (Exception $ex) {
            if (defined('DEBUG') && DEBUG) error_log('[LogService] 计数失败：' . $ex->getMessage());
        }
        return $out;
    }

    /* ============================================================
     * 服务器日志（文件直读）
     * ============================================================ */

    /** 应用日志文件路径 */
    public static function appLogPath() {
        if (defined('LOG_DIR')) return LOG_DIR . '/app.log';
        if (defined('DATA_DIR')) return DATA_DIR . '/logs/app.log';
        return APP_ROOT . '/data/logs/app.log';
    }

    /**
     * 服务器日志来源列表
     * @return array [ { id, title, path, exists, size } ]
     */
    public static function serverSources() {
        $list = array();
        $app = self::appLogPath();
        $list[] = array(
            'id' => 'app', 'title' => '应用日志', 'path' => $app,
            'exists' => is_file($app), 'size' => is_file($app) ? (int)filesize($app) : 0,
            'lines' => self::countLines($app),
        );
        $ext = trim((string)self::cfg('log.server.external_path', ''));
        if ($ext !== '') {
            $list[] = array(
                'id' => 'external', 'title' => '服务器日志', 'path' => $ext,
                'exists' => is_file($ext), 'size' => is_file($ext) ? (int)filesize($ext) : 0,
                'lines' => self::countLines($ext),
            );
        }
        return $list;
    }

    /** 统计文件行数（分块流式读取，避免一次性载入大文件内存） */
    public static function countLines($path) {
        if ($path === '' || !is_file($path) || !is_readable($path)) return 0;
        $fh = @fopen($path, 'rb');
        if (!$fh) return 0;
        $count = 0;
        while (!feof($fh)) {
            $chunk = fread($fh, 262144);
            if ($chunk === false || $chunk === '') break;
            $count += substr_count($chunk, "\n");
        }
        fclose($fh);
        // 末行无换行符时补 1
        $size = (int)@filesize($path);
        if ($size > 0) {
            $last = @file_get_contents($path, false, null, max(0, $size - 1), 1);
            if ($last !== "\n") $count++;
        }
        return $count;
    }

    /** 解析服务器日志来源路径（id: app/external） */
    public static function serverPath($id) {
        if ($id === 'external') return trim((string)self::cfg('log.server.external_path', ''));
        return self::appLogPath();
    }

    /**
     * 读取服务器日志（从文件尾部分页）
     * @param string $sourceId app/external
     * @param int    $offset   已读取行数（0 = 最新一段）
     * @param int    $limit    本次读取行数
     * @param string $level    级别过滤（空=全部）
     * @param string $kw       关键字过滤
     * @param string $date     单日过滤（YYYY-MM-DD，空=全部）
     * @return array { list, has_more, path, exists }
     */
    public static function readServer($sourceId, $offset = 0, $limit = 200, $level = '', $kw = '', $date = '') {
        $path = self::serverPath($sourceId);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return array('list' => array(), 'has_more' => false, 'path' => $path, 'exists' => false);
        }
        $maxBytes = max(64, (int)self::cfg('log.server.max_kb', '1024')) * 1024;
        $lines = self::tailLines($path, $maxBytes);
        // 单日过滤：兼容 PHP error_log 的「d-M-Y」与常见「Y-m-d」两种时间格式
        $dateAlt = '';
        if ($date !== '') {
            $ts = strtotime($date);
            if ($ts) $dateAlt = date('d-M-Y', $ts);
        }
        // 过滤（按级别/关键字/日期）后按尾部偏移切片
        $filtered = array();
        foreach ($lines as $ln) {
            $parsed = self::parseServerLine($ln);
            if ($level !== '' && $parsed['level'] !== $level) continue;
            if ($kw !== '' && mb_stripos($ln, $kw) === false) continue;
            if ($date !== '' && strpos($ln, $date) === false && ($dateAlt === '' || strpos($ln, $dateAlt) === false)) continue;
            $filtered[] = $parsed;
        }
        $total = count($filtered);
        $offset = max(0, (int)$offset);
        $limit = min(500, max(1, (int)$limit));
        // 从尾部往前：跳过 offset 条，取 limit 条（返回保持时间正序：旧→新）
        $end = $total - $offset;                 // 不含
        $start = $end - $limit;
        $hasMore = $start > 0;
        if ($end <= 0) {
            return array('list' => array(), 'has_more' => false, 'path' => $path, 'exists' => true);
        }
        if ($start < 0) $start = 0;
        $slice = array_slice($filtered, $start, $end - $start);
        return array('list' => array_values($slice), 'has_more' => $hasMore, 'path' => $path, 'exists' => true);
    }

    /** 读取文件末尾 maxBytes 字节并按行切分（丢弃可能截断的首行） */
    private static function tailLines($path, $maxBytes) {
        $size = (int)@filesize($path);
        if ($size <= 0) return array();
        $fh = @fopen($path, 'rb');
        if (!$fh) return array();
        $read = min($size, $maxBytes);
        fseek($fh, $size - $read);
        $data = fread($fh, $read);
        fclose($fh);
        $data = str_replace("\r\n", "\n", (string)$data);
        $lines = explode("\n", $data);
        // 若文件比读取窗口大，说明首行可能不完整，丢弃
        if ($read < $size && count($lines) > 0) array_shift($lines);
        // 去掉末尾空行
        while (count($lines) > 0 && trim($lines[count($lines) - 1]) === '') array_pop($lines);
        return $lines;
    }

    /** 解析单行服务器日志（时间 / 级别 / 文本） */
    public static function parseServerLine($line) {
        $line = (string)$line;
        $time = '';
        if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            $time = $m[1];
            $text = ltrim(substr($line, strlen($m[0])));
        } else {
            $text = $line;
        }
        $level = 'normal';
        if (preg_match('/(Fatal error|Parse error|Uncaught|\[error\]|E_ERROR)/i', $line)) {
            $level = 'error';
        } elseif (preg_match('/(Warning|Deprecated|\[warn(ing)?\]|E_WARNING|E_DEPRECATED)/i', $line)) {
            $level = 'warning';
        } elseif (preg_match('/(Notice|\[info\]|E_NOTICE)/i', $line)) {
            $level = 'info';
        }
        return array(
            'time'  => $time,
            'level' => $level,
            'text'  => $text,
            'raw'   => $line,
        );
    }

    /** 清空服务器日志文件 */
    public static function clearServer($sourceId) {
        $path = self::serverPath($sourceId);
        if ($path === '' || !is_file($path)) return false;
        return @file_put_contents($path, '') !== false;
    }

    /* ============================================================
     * 元数据（前端左侧栏/管理弹窗使用）
     * ============================================================ */

    /** 操作日志子分类 */
    public static function operationCategories() {
        return array(
            self::OP_LOGIN   => array('title' => '登录日志', 'desc' => '用户登录、退出记录'),
            self::OP_ACCOUNT => array('title' => '账号变更', 'desc' => '修改密码、修改个人信息、账号解锁等'),
        );
    }

    /** 接口日志子分类（按外部系统） */
    public static function interfaceCategories() {
        return array(
            'fhir'      => 'FHIR R4',
            'dicom'     => 'DICOM/PACS',
            'hl7'       => 'HL7 v2.x',
            'lis'       => 'LIS',
            'his'       => 'HIS',
            'insurance' => '医保 / 支付',
            'evid'      => '存证 / 签名',
        );
    }

    /** 级别中文名 */
    public static function levelName($level) {
        $map = array('normal' => '正常', 'info' => '提示', 'warning' => '警告', 'error' => '错误');
        return isset($map[$level]) ? $map[$level] : $level;
    }
}
