<?php
/**
 * ============================================================
 * auto_finish_records.php — 病历超时自动归档（每日调度）
 * ============================================================
 * 说明：超过 7 天（默认，可参数覆盖）未诊毕且病历完整（主诉/现病史/
 * 诊断齐备）的就诊，自动调用诊毕逻辑完成归档：
 *   离院方式=其他，备注=病历超时自动归档。
 * 完整性判断交给诊毕操作（finish_visit 条件更新）兜底，本脚本只负责
 * 「到时间了就自动诊毕」。由 public/index.php 每日首次访问触发（nohup 后台）。
 * 用法：php-cli tools/cli/auto_finish_records.php [天数，默认7]
 * ============================================================ */

require dirname(dirname(__DIR__)) . '/app/config/bootstrap.php';

$days = 7;
if (isset($argv[1]) && (int)$argv[1] > 0) $days = (int)$argv[1];

// 待归档候选：未诊毕（draft）、就诊未结束、且病历超过 N 天未更新
$rows = EmrRepository::q(
    "SELECT pr.id AS record_id, pr.visit_id, pr.record_type, pr.emr_print_text, pr.updated_at,
            r.chief_complaint, r.present_illness, r.preliminary_diagnosis
     FROM patient_records pr
     JOIN records r ON r.patient_record_id = pr.id
     JOIN registrations reg ON reg.id = pr.visit_id
     WHERE pr.status='draft'
       AND reg.status IN ('paid','visiting')
       AND pr.updated_at < datetime('now','localtime', ?)
     ORDER BY pr.updated_at ASC",
    array('-' . $days . ' days')
);

$done = 0;
$skipped = 0;
foreach ($rows as $r) {
    // 病历完整性判断：主诉/现病史/诊断缺一不可，否则不自动诊毕
    $complete = trim((string)$r['chief_complaint']) !== ''
        && trim((string)$r['present_illness']) !== ''
        && trim((string)$r['preliminary_diagnosis']) !== '';
    if (!$complete) {
        $skipped++;
        continue;
    }
    $recordId = (int)$r['record_id'];
    $visitId = (int)$r['visit_id'];
    // 单条事务：病历置 done + 就诊置 finished + 快照 + 存证（条件更新防并发重复处理）
    try {
        $pdo = DatabaseManager::getMain();
        $pdo->beginTransaction();
        $n = EmrRepository::exec('UPDATE patient_records SET status=? WHERE id=? AND status=?',
            array('done', $recordId, 'draft'));
        if ($n > 0) {
            finish_visit($visitId, $recordId, (string)$r['record_type'], (string)$r['emr_print_text'], '其他', '病历超时自动归档');
        }
        $pdo->commit();
        if ($n > 0) {
            $done++;
            if (defined('DEBUG') && DEBUG) error_log('[自动诊毕] 就诊 #' . $visitId . ' 病历 #' . $recordId . ' 已归档（病历超时自动归档）');
        }
    } catch (Exception $ex) {
        try { if ($pdo && $pdo->inTransaction()) $pdo->rollBack(); } catch (Exception $e) {}
        if (defined('DEBUG') && DEBUG) error_log('[自动诊毕失败] 就诊 #' . $visitId . '：' . $ex->getMessage());
    }
}

echo '自动诊毕完成：归档 ' . $done . ' 条，跳过（病历不完整）' . $skipped . ' 条。' . PHP_EOL;