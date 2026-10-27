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
 *   DAY_WINDOW  SQL 分日窗口直接函数比较（date(时间列)=? / substr(col,1,7) 等；
 *               兼容表别名前缀）→ 应改为 day_range() 边界比较
 *   RELATIVE    内联 strtotime 直接解析存储时间列 → 应走 ts_epoch()/fmt_time()
 *   DISPLAY     展示层直接 substr 截取时间列（未包 fmt_time()）
 *   PAYLOAD     API/仓储载荷直接透出时间列（未包 fmt_time()，前端按原样显示）
 *   PRINT       打印模板直接引用时间列 / now_str()（未包转换，应按快照时区渲染）
 *   （FHIR 时间输出为基类 FhirAdapter::isoDateTime 单点统一换算，不在扫描范围）
 *
 * 豁免约定：确认为「内部用途（版本戳/幂等键/写入路径等，不面向用户展示）」的
 * 行，行尾加 `tz-ok` 注释即被跳过（须逐条评审，严禁批量豁免掩盖展示点）。
 *
 * 扫描核心在 tools/lint/tz_audit_lib.php（与 tz_migrate switch 共用）。
 *
 * 用法：
 *   php tools/cli/tz_audit.php            # 明细清单（分组）
 *   php tools/cli/tz_audit.php --summary  # 仅统计
 *   退出码：0=无残留（可切换）；1=有残留（禁止切换）
 * ============================================================ */

$APP_ROOT = dirname(dirname(__DIR__));
require $APP_ROOT . '/tools/lint/tz_audit_lib.php';

$summaryOnly = in_array('--summary', $argv, true);
$findings = tz_audit_scan($APP_ROOT);

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
