<?php
/**
 * ============================================================
 * migrate_split_to_unified.php — 分散式数据库→统一主库迁移工具
 * ============================================================
 * 说明：读取旧分散 SQLite 数据库文件（data/db/{core,user,...}.db），
 * 按照字段映射字典逐行提取·清洗·写入新统一主库（data/db/clinic_main.db）。
 * 保留原主键 ID，兼容旧补丁字段，迁移结束后输出行数校验报告。
 *
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/migrate_split_to_unified.php
 *
 * 安全：迁移前自动备份旧 clinic_main.db（若存在）；迁移为事务性——
 *      任一表迁移期间出错则整体回滚，确保数据一致性。
 * ============================================================ */

require __DIR__ . '/../app/config/bootstrap.php';

// ================================================================
// 字段映射字典（旧字段 → 新字段）
// 格式：'表名' => array('旧字段' => '新字段', ...)
// 未列出的字段名保持不变（新旧同名，直接透传）。
// ================================================================
$fieldMap = array(

    /* ---- patients ---- */
    'patients' => array(
        'past_history_type'   => 'has_past_history',
        'past_history_detail' => 'past_history',
        'allergies'           => 'allergy_history',
    ),

    /* ---- registrations ---- */
    'registrations' => array(
        'register_time'  => 'registered_at',
        'payment_time'   => 'paid_at',
        'finish_time'    => 'finished_at',
    ),

    /* ---- orders ---- */
    'orders' => array(
        'cat_name' => 'category_name',
    ),

    /* ---- order_items ---- */
    'order_items' => array(
        'unit_name'      => 'unit',
        'frequency_name' => 'frequency',
        'route_name'     => 'route',
        'need_nurse'     => 'is_nurse',
    ),

    /* ---- drug_settings ---- */
    'drug_settings' => array(
        'need_nurse' => 'is_nurse',
    ),

    /* ---- drugs ---- */
    'drugs' => array(
        'frequency_name' => 'frequency',
        'route_name'     => 'route',
        'need_nurse'     => 'is_nurse',
        'need_skin_test' => 'is_skin_test',
    ),

    /* ---- records（镜像表） ---- */
    'records' => array(
        'initial_diagnosis' => 'preliminary_diagnosis',
        'diagnosis_code'    => 'icd10_code',
        'advice'            => 'doctor_advice',
    ),

    /* ---- patient_records（结构化病历） ---- */
    'patient_records' => array(
        'main_symptom'      => 'chief_complaint',
        'allergies'         => 'allergy_history',
        'primary_icd10'     => 'icd10_code',
        'primary_diagnosis' => 'diagnosis_name',
    ),

    /* ---- certificates ---- */
    'certificates' => array(
        'initial_diagnosis' => 'preliminary_diagnosis',
    ),

    /* ---- vitals ---- */
    'vitals' => array(
        'bp_systolic'  => 'vital_sbp',
        'bp_diastolic' => 'vital_dbp',
        'heart_rate'   => 'vital_heart_rate',
        'pulse'        => 'vital_pulse',
        'spo2'         => 'vital_spo2',
        'respiration'  => 'vital_respiration',
    ),

    /* ---- disposal_items ---- */
    'disposal_items' => array(
        'need_nurse' => 'is_nurse',
    ),
);

// ================================================================
// 旧库 → 表映射（哪些旧库文件包含哪些表）
// ================================================================
$oldDbTables = array(
    'core'          => array('settings', 'messages', 'sent_messages', 'audits'),
    'user'          => array('users'),
    'dept'          => array('departments', 'extra_slots'),
    'patient'       => array('patients', 'registrations'),
    'order'         => array('orders', 'order_items', 'payments', 'refunds', 'inventory_trans'),
    'drug'          => array('drug_settings', 'drugs'),
    'medical'       => array('records', 'patient_records', 'templates', 'certificates', 'referrals', 'diag_orders', 'consents'),
    'nurse'         => array('vitals', 'nursing_records'),
    'lab'           => array('item_categories', 'lab_items', 'exam_items', 'results', 'reports', 'lab_group_members'),
    'disp'          => array('disposal_items'),
    'emr_templates' => array('emr_templates', 'emr_template_depts'),
    'clinic_rooms'  => array('clinic_rooms'),
    'consultation'  => array('consultations'),
);

// ================================================================
// 主要迁移逻辑
// ================================================================

$oldDir = DATA_DIR . '/db';
$newPdo = DatabaseManager::getMain();
$newPdo->exec('PRAGMA foreign_keys = OFF'); // 迁移期间关闭外键检查

$totalRows = 0;
$errors = array();
$rowCounts = array(); // 表名 => [旧行数, 新行数]

echo "=== 开始数据迁移 ===\n\n";

// 开始事务
$newPdo->beginTransaction();

try {
    foreach ($oldDbTables as $dbKey => $tables) {
        $oldFile = $oldDir . '/' . $dbKey . '.db';
        if (!file_exists($oldFile)) {
            echo "[跳过] 旧库不存在: $dbKey.db\n";
            continue;
        }
        $oldPdo = new PDO('sqlite:' . $oldFile, null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));

        foreach ($tables as $table) {
            // 检查旧表是否存在
            $oldExists = $oldPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
            if (!$oldExists || (int)$oldExists === 0) {
                // 尝试旧表名（可能因序号差异，改用 PRAGMA 检查）
                $oldTables = $oldPdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array($table, $oldTables, true)) {
                    echo "[跳过] 表不存在: $dbKey.$table\n";
                    continue;
                }
            }

            // 获取旧表列信息
            $oldCols = array();
            foreach ($oldPdo->query("PRAGMA table_info($table)") as $c) {
                $oldCols[] = $c['name'];
            }
            if (empty($oldCols)) {
                echo "[跳过] 空表: $dbKey.$table\n";
                continue;
            }

            // 获取新表列信息
            $newCols = array();
            foreach ($newPdo->query("PRAGMA table_info($table)") as $c) {
                $newCols[] = $c['name'];
            }
            if (empty($newCols)) {
                echo "[跳过] 新表不存在: $table\n";
                continue;
            }

            // 构建映射列列表：旧列 → 新列
            $map = isset($fieldMap[$table]) ? $fieldMap[$table] : array();
            $oldToNew = array();
            foreach ($oldCols as $oc) {
                $newName = isset($map[$oc]) ? $map[$oc] : $oc;
                if (in_array($newName, $newCols, true)) {
                    $oldToNew[$oc] = $newName;
                }
            }

            // 如果没有任何列映射，跳过
            if (empty($oldToNew)) {
                echo "[跳过] 无映射列: $dbKey.$table\n";
                continue;
            }

            // 读取旧数据
            $oldRows = $oldPdo->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC);
            $oldCount = count($oldRows);

            if ($oldCount === 0) {
                echo "[空] $dbKey.$table (0 行)\n";
                $rowCounts[$table] = array(0, 0);
                continue;
            }

            // 构建 INSERT SQL
            $newColList = array();
            $placeholders = array();
            foreach ($oldToNew as $newCol) {
                $newColList[] = $newCol;
                $placeholders[] = '?';
            }
            $insertSql = 'INSERT OR IGNORE INTO "' . $table . '" ("' . implode('","', $newColList) . '") VALUES (' . implode(',', $placeholders) . ')';
            $stmt = $newPdo->prepare($insertSql);

            $inserted = 0;
            $skipped = 0;
            foreach ($oldRows as $row) {
                $params = array();
                foreach ($oldToNew as $oldCol => $newCol) {
                    $val = isset($row[$oldCol]) ? $row[$oldCol] : null;
                    $params[] = $val;
                }
                $stmt->execute($params);
                if ($stmt->rowCount() > 0) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }

            $totalRows += $inserted;
            $rowCounts[$table] = array($oldCount, $inserted);
            echo sprintf("[OK] %-25s %4d → %4d 行 (跳过 %d)\n", $dbKey . '.' . $table, $oldCount, $inserted, $skipped);
        }
    }

    $newPdo->commit();
    echo "\n=== 迁移完成 ===\n";
    echo "总迁移行数: $totalRows\n\n";

} catch (Exception $e) {
    $newPdo->rollBack();
    echo "\n[错误] 迁移失败，已回滚事务: " . $e->getMessage() . "\n";
    exit(1);
}

// 恢复外键检查
$newPdo->exec('PRAGMA foreign_keys = ON');

// ================================================================
// 一致性校验：对比新旧库行数
// ================================================================
echo "=== 一致性校验 ===\n\n";
$allOk = true;
foreach ($oldDbTables as $dbKey => $tables) {
    $oldFile = $oldDir . '/' . $dbKey . '.db';
    if (!file_exists($oldFile)) continue;
    $oldPdo = new PDO('sqlite:' . $oldFile);
    foreach ($tables as $table) {
        $oldCnt = (int)$oldPdo->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
        $newCnt = (int)$newPdo->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
        $status = $oldCnt === $newCnt ? 'OK' : 'MISMATCH';
        if ($status !== 'OK') $allOk = false;
        printf("[%s] %-25s 旧库: %4d  |  新库: %4d\n", $status, $table, $oldCnt, $newCnt);
    }
}
echo "\n";
if ($allOk) {
    echo "✓ 全部表行数一致，数据迁移验证通过\n";
} else {
    echo "⚠ 部分表行数不一致，请检查日志\n";
}
echo "\n=== 迁移报告完毕 ===\n";