<?php
/**
 * ============================================================
 * fix_icd10_split.php — 修复 ICD-10 字典中抓取拆分导致的编码异常
 * ============================================================
 * 问题背景：Python 抓取时亚目名称过长被拆行，导致：
 *  1. diagnosis_code 被污染：尾部拼接了亚目名被截断的尾段（如
 *     "1000-2499克之间）P07.100"、"B12缺乏性贫血D51.100"）
 *  2. 个别行编码整体被拆到 diagnosis_name 开头（如 #23414 #23434）
 *  3. subcategory_name 被截断，缺失尾部
 * 修复逻辑：
 *  - 从 diagnosis_code 提取真正的 ICD 编码（尾部匹配，含 x 扩展/连码）
 *  - 前缀（亚目名尾段）拼回 subcategory_name（#29383 特殊：前缀含
 *    subcategory_code 自身时先剥离）
 *  - 编码在 name 开头的行为反向拆分：从 name 提取编码，name 去前缀，
 *    原 code 作为亚目名尾段拼回 subcategory_name
 * 幂等：仅处理含空格/中文/名称开头带编码的行；修复后二次校验。
 * ============================================================ */
require __DIR__ . '/../app/config/bootstrap.php';

/** 从字符串提取尾部真正的 ICD 编码 */
function fix_extract_code($s) {
    if (preg_match('/([A-Z]\d{2}(?:\.\d{1,3})?(?:x\d{3})?(?:[+*][A-Z]\d{2}(?:\.\d{1,3})?\*?)?)$/', trim($s), $m)) {
        return array('code' => $m[1], 'prefix' => trim(substr(trim($s), 0, -strlen($m[1]))));
    }
    return null;
}

$pdo = DatabaseManager::getIcd10();

// 备份（仅当存在原始备份文件时跳过）
$dbFile = DATA_DIR . '/db/icd10.db';
$bakFile = DATA_DIR . '/db/icd10.before-fix.db';
if (!file_exists($bakFile)) {
    @copy($dbFile, $bakFile);
    echo "已备份原库 → {$bakFile}\n";
}

// 受影响行：编码含空格 或 编码含中文 或 名称开头带编码
$rows = $pdo->query(
    "SELECT id, diagnosis_code, diagnosis_name, subcategory_code, subcategory_name, search_tags
     FROM icd10
     WHERE diagnosis_code LIKE '% %'
        OR diagnosis_code GLOB '*[一-鿿]*'
        OR diagnosis_name GLOB '[A-Z][0-9][0-9]*'
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$fixed = 0;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $fix = fix_extract_code($r['diagnosis_code']);
    $newCode = '';
    $newName = $r['diagnosis_name'];
    $newSub = $r['subcategory_name'];
    $newTags = $r['search_tags'];

    if ($fix) {
        $newCode = $fix['code'];
        $prefix = $fix['prefix'];
        // 特殊：#29383 前缀含 subcategory_code 自身（"V82.1（市内有轨）电车乘员的损伤"）
        if (strpos($prefix, (string)$r['subcategory_code']) === 0) {
            $prefix = trim(substr($prefix, strlen((string)$r['subcategory_code'])));
        }
        if ($prefix !== '') $newSub .= $prefix;
    } else {
        // 反向拆分：编码在名称开头（#23414 #23434）
        if (preg_match('/^([A-Z]\d{2}(?:\.\d{1,3})?(?:x\d{3})?)\s*/', $r['diagnosis_name'], $m)) {
            $newCode = $m[1];
            $newName = trim(substr($r['diagnosis_name'], strlen($m[0])));
        } else {
            echo "!! 无法修复 #{$id}（code={$r['diagnosis_code']}）\n";
            continue;
        }
        $prefix = $r['diagnosis_code'];
        if ($prefix !== '') $newSub .= $prefix;
    }
    // search_tags 前缀若混入编码（如 "Q96.300tyqht..."）则剥离，保持拼音检索纯净
    if ($newTags !== '' && preg_match('/^[A-Z]\d{2}(?:\.\d{1,3})?(?:x\d{3})?/', $newTags, $tm)) {
        $newTags = trim(substr($newTags, strlen($tm[0])));
    }

    $st = $pdo->prepare(
        'UPDATE icd10 SET diagnosis_code=?, diagnosis_name=?, subcategory_name=?, search_tags=? WHERE id=?'
    );
    $st->execute(array($newCode, $newName, $newSub, $newTags, $id));
    $fixed++;
    echo "#{$id} code={$r['diagnosis_code']} -> {$newCode} | tags={$r['search_tags']} -> {$newTags}\n";
}

echo "=== 修复完成：{$fixed} 条 ===\n";

// ==================== 二次校验 ====================
$bad = $pdo->query(
    "SELECT COUNT(*) FROM icd10
     WHERE diagnosis_code LIKE '% %'
        OR diagnosis_code GLOB '*[一-鿿]*'
        OR diagnosis_name GLOB '[A-Z][0-9][0-9]*'"
)->fetchColumn();
echo "=== 二次校验剩余异常：{$bad} 条 ===\n";
echo $bad === 0 ? "✔ 全部修复干净\n" : "⚠ 仍有残留，需人工检查\n";
