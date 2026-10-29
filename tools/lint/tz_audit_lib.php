<?php
/**
 * ============================================================
 * tools/lint/tz_audit_lib.php — 时间口径审计核心（纯函数库）
 * ============================================================
 * 说明：被以下调用方共用（高解耦：扫描规则只维护此一处）：
 *  - tools/cli/tz_audit.php   切换准入检查脚本（明细/统计输出）
 *  - tools/cli/tz_migrate.php 一体化切换（switch 前自动执行本审计，未清零拒绝切换）
 *
 * 检查类别与豁免约定详见 tools/cli/tz_audit.php 头注。
 * ============================================================ */

/**
 * 扫描全库时间口径残留。
 * @param string $APP_ROOT 项目根目录（绝对路径）
 * @return array findings：array('DAY_WINDOW'=>[], 'RELATIVE'=>[], 'DISPLAY'=>[], 'PAYLOAD'=>[], 'PRINT'=>[])
 *               每项为 array('file'=>相对路径, 'line'=>行号, 'text'=>行摘录)
 */
function tz_audit_scan($APP_ROOT) {
    /* 扫描目录（业务代码；schema/工具/纯函数层除外） */
    $scanDirs = array(
        $APP_ROOT . '/app/api',
        $APP_ROOT . '/app/repositories',
        $APP_ROOT . '/app/services',
        $APP_ROOT . '/app/views',
        $APP_ROOT . '/app/includes/print',
        $APP_ROOT . '/public',
    );
    /* 例外文件：本身即时间层基础设施 */
    $skipFiles = array(
        'helpers.d/time.php',
        'app/core/db/',
        'tools/cli/tz_migrate.php',
    );
    $TIME_COLS = 'created_at|updated_at|registered_at|paid_at|executed_at|finished_at|reviewed_at|refunded_at|handled_at|decided_at|processed_at|accepted_at|performed_at|withdraw_at|last_call_at|current_called_at|last_heartbeat_at|screen_last_heartbeat_at|doctor_heartbeat_at|evid_time|locked_at|last_login_at|started_at|applied_at';

    $findings = array('DAY_WINDOW' => array(), 'RELATIVE' => array(), 'DISPLAY' => array(), 'PAYLOAD' => array(), 'PRINT' => array());
    if (!is_dir($APP_ROOT)) return $findings;

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($APP_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        $path = $file->getPathname();
        if (substr($path, -4) !== '.php') continue;
        $rel = str_replace($APP_ROOT . '/', '', $path);
        $skip = false;
        foreach ($skipFiles as $sf) { if (strpos($rel, $sf) !== false) { $skip = true; break; } }
        if ($skip) continue;
        $inScan = false;
        foreach ($scanDirs as $d) { if (strpos($path, $d) === 0) { $inScan = true; break; } }
        if (!$inScan) continue;

        $isPrint = strpos($rel, 'app/includes/print/') === 0;
        $lines = file($path);
        foreach ($lines as $no => $line) {
            if (tz_audit_skip_line($line)) continue;
            $ln = $no + 1;
            $ex = trim($line);
            if (mb_strlen($ex) > 110) $ex = mb_substr($ex, 0, 110) . '…';
            $hit = array('file' => $rel, 'line' => $ln, 'text' => $ex);

            // ① 分日窗口：SQL 函数式比较（兼容表别名前缀 date(rp.registered_at)）
            if (preg_match('/\bdate\(\s*(?:[a-z_][a-z0-9_]*\.)?(' . $TIME_COLS . ')\s*\)|substr\(\s*(?:[a-z_][a-z0-9_]*\.)?(' . $TIME_COLS . ')\s*,\s*1\s*,\s*7\s*\)/i', $line)) {
                $findings['DAY_WINDOW'][] = $hit;
                continue;
            }
            // ② 相对计算：内联 strtotime/date 直接解析存储时间列（应走 ts_epoch/fmt_time）
            if (preg_match('/strtotime\([^;]{0,80}(' . $TIME_COLS . ')/i', $line)
                && !preg_match('/ts_epoch|is_recent|fmt_time|local_date_of|ts_epoch_tz|pt_time|iso_utc|utc_to_storage/', $line)) {
                $findings['RELATIVE'][] = $hit;
                continue;
            }
            // ③ 展示截取：substr 时间列（兼容别名前缀 $row['x'] / rp.x）
            if (preg_match('/substr\([^;]{0,120}(?:\$[a-z0-9_]+\[[\'"])?(' . $TIME_COLS . ')[^;]{0,40},\s*(0|5|11)\s*,/i', $line)) {
                $findings['DISPLAY'][] = $hit;
                continue;
            }
            // ④ 打印模板：直接引用时间列或 now_str（应按快照时区/展示换算）
            if ($isPrint && preg_match('/(' . $TIME_COLS . '|now_str\()/', $line)) {
                $findings['PRINT'][] = $hit;
                continue;
            }
            // ⑤ 载荷直出：'时间列' => 未包裹（api/repositories/services）
            $isPayloadSrc = (strpos($rel, 'app/api/') === 0 || strpos($rel, 'app/repositories/') === 0 || strpos($rel, 'app/services/') === 0);
            if (!$isPrint && $isPayloadSrc && preg_match("/'(" . $TIME_COLS . "|__registered_at|__executed_at|__order_created)'\s*=>/", $line)) {
                $findings['PAYLOAD'][] = $hit;
                continue;
            }
            // （FHIR 时间输出已在基类 FhirAdapter::isoDateTime 单点统一换算，无需调用点包裹）
        }
    }
    return $findings;
}

/** 行是否豁免/跳过（注释行 / tz-ok / 已包裹转换函数） */
function tz_audit_skip_line($line) {
    $t = ltrim($line);
    if ($t === '' || strpos($t, '//') === 0 || strpos($t, '*') === 0 || strpos($t, '#') === 0) return true; // 注释
    if (stripos($line, 'tz-ok') !== false) return true;                                                    // 显式豁免
    if (preg_match('/fmt_time\(|local_date_of\(|pt_time\(|iso_utc\(/', $line)) return true;              // 已包裹（本地显示或对外 UTC 单点）
    return false;
}

/** findings 总计数 */
function tz_audit_total(array $findings) {
    $n = 0;
    foreach ($findings as $list) $n += count($list);
    return $n;
}
