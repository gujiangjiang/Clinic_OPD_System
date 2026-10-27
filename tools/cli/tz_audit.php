<?php
/**
 * ============================================================
 * tools/cli/tz_audit.php — 时间口径迁移审计门（UTC 切换准入检查）
 * ============================================================
 * 用途：扫描全库代码中「仍按墙钟口径直接使用时间字段」的残留点，作为
 * 「time.storage=utc 切换」前的**硬性准入检查**——全部清零（或逐条以
 * `tz-ok` 注释确认豁免）后，方可执行切换窗口。
 *
 * 检查类别：
 *   DAY_WINDOW  SQL 分日窗口直接函数比较（date(时间列)=? / substr(col,1,7) 等）
 *               → 应改为 day_range() 边界比较（reg_date 业务日列除外，天然安全）
 *   DISPLAY     展示层直接 substr 截取时间列（未包 fmt_time()）
 *   PAYLOAD     API/仓储载荷直接透出时间列（未包 fmt_time()，前端按原样显示）
 *   PRINT       打印模板直接引用时间列 / now_str()（未包 fmt_time()，应按快照时区渲染）
 *
 * 豁免约定：确认为「内部用途（版本戳/幂等键/文件路径等，不面向用户展示）」的
 * 行，行尾加 `tz-ok` 注释即被跳过（须逐条评审，严禁批量豁免掩盖展示点）。
 *
 * 用法：
 *   php tools/cli/tz_audit.php            # 明细清单（分组）
 *   php tools/cli/tz_audit.php --summary  # 仅统计
 *   退出码：0=无残留（可切换）；1=有残留（禁止切换）
 * ============================================================ */

$APP_ROOT = dirname(dirname(__DIR__));

$summaryOnly = in_array('--summary', $argv, true);

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

/** 行是否豁免/跳过（注释行 / tz-ok / 已包裹转换函数） */
function tz_skip_line($line) {
    $t = ltrim($line);
    if ($t === '' || strpos($t, '//') === 0 || strpos($t, '*') === 0 || strpos($t, '#') === 0) return true; // 注释
    if (stripos($line, 'tz-ok') !== false) return true;                                                    // 显式豁免
    if (preg_match('/fmt_time\(|local_date_of\(|pt_time\(/', $line)) return true;                           // 已包裹
    return false;
}

$findings = array('DAY_WINDOW' => array(), 'RELATIVE' => array(), 'DISPLAY' => array(), 'PAYLOAD' => array(), 'PRINT' => array());

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
        if (tz_skip_line($line)) continue;
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
            && !preg_match('/ts_epoch|seconds_ago|is_recent|fmt_time|local_date_of|ts_epoch_tz|pt_time/', $line)) {
            $findings['RELATIVE'][] = $hit;
            continue;
        }
        // ③ 展示截取：substr 时间列（兼容别名前缀 $row['x'] / rp.x）
        if (preg_match('/substr\([^;]{0,120}(?:\$[a-z0-9_]+\[[\'"])?(' . $TIME_COLS . ')[^;]{0,40},\s*(0|5|11)\s*,/i', $line)) {
            $findings['DISPLAY'][] = $hit;
            continue;
        }
        // ③ 打印模板：直接引用时间列或 now_str（应按快照时区/展示换算）
        if ($isPrint && preg_match('/(' . $TIME_COLS . '|now_str\()/', $line)) {
            $findings['PRINT'][] = $hit;
            continue;
        }
        // ④ 载荷直出：'时间列' => 未包裹（api/repositories/services）
        $isPayloadSrc = (strpos($rel, 'app/api/') === 0 || strpos($rel, 'app/repositories/') === 0 || strpos($rel, 'app/services/') === 0);
        if (!$isPrint && $isPayloadSrc && preg_match("/'(" . $TIME_COLS . "|__registered_at|__executed_at|__order_created)'\s*=>/", $line)) {
            $findings['PAYLOAD'][] = $hit;
            continue;
        }
        // （FHIR 时间输出已在基类 FhirAdapter::isoDateTime 单点统一换算，无需调用点包裹）
    }
}

/* ---------------- 输出 ---------------- */
$total = 0;
foreach ($findings as $cat => $list) $total += count($list);
echo "══════ 时间口径迁移审计（UTC 切换准入检查） ══════\n";
foreach ($findings as $cat => $list) {
    printf("%-12s %4d 处\n", $cat, count($list));
    if (!$summaryOnly) {
        foreach ($list as $h) {
            echo "  [{$cat}] {$h['file']}:{$h['line']}\n      {$h['text']}\n";
        }
    }
}
echo "──────────────────────────────────────────\n";
if ($total === 0) {
    echo "✓ 无残留：可执行 time.storage=utc 切换窗口\n";
    exit(0);
}
echo "✗ 残留 {$total} 处：切换前必须逐条处理（改为 fmt_time/day_range/快照时区渲染）\n";
echo "  或经评审后以行尾 tz-ok 注释豁免（仅限非展示的内部用途）\n";
exit(1);
