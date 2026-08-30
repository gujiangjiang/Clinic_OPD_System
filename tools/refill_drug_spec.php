<?php
/**
 * ============================================================
 * tools/refill_drug_spec.php — 药品规格结构化填充
 * ============================================================
 * 将 drugs.spec 文本（如 0.5g×24粒 / 250ml×1瓶 / 8万U×10支）解析为
 * 结构化字段：spec_dose / spec_dose_unit / spec_pack_qty / spec_pack_unit，
 * 并将 single_dose（如 2粒 / 按说明书）解析为单次数量 single_use_qty。
 * 幂等：重复运行仅更新已有解析结果，不影响手工编辑过的药品。
 * 用法：~/.local/bin/frankenphp php-cli tools/refill_drug_spec.php
 * ============================================================ */
require __DIR__ . '/../app/config/bootstrap.php';
DatabaseManager::initAll();

/** 解析规格文本：0.5g×24粒 → (0.5, g, 24, 粒)；250ml×1瓶 → (250, ml, 1, 瓶) */
function parse_drug_spec($spec) {
    $spec = trim((string)$spec);
    if ($spec === '') return null;
    $dose = 0.0; $unit = ''; $packQty = 1; $packUnit = '';
    if (preg_match('/^([\d.]+)\s*([^×\d]*?)\s*×\s*(\d+)\s*([^\d\s]*?)$/u', $spec, $m)) {
        $dose = (float)$m[1]; $unit = trim((string)$m[2]);
        $packQty = (int)$m[3]; $packUnit = trim((string)$m[4]);
    } elseif (preg_match('/^([\d.]+)\s*([^\d\s]*)$/u', $spec, $m)) {
        $dose = (float)$m[1]; $unit = trim((string)$m[2]);
    } else {
        return null;
    }
    return array('dose' => $dose, 'unit' => $unit, 'pack_qty' => $packQty, 'pack_unit' => $packUnit);
}

/** 解析单次使用剂量为数量："2粒"→2、"1片"→1、"按说明书"→1 */
function parse_single_use($txt) {
    $txt = trim((string)$txt);
    if ($txt === '' || $txt === '按说明书' || $txt === '遵医嘱') return 1;
    if (preg_match('/^\s*(\d+(?:\.\d+)?)/u', $txt, $m)) return max(1, round((float)$m[1]));
    return 1;
}

$rows = DB::q('SELECT id, name, spec, single_dose FROM drugs ORDER BY id');
$updated = 0; $skipped = 0;
foreach ($rows as $r) {
    $p = parse_drug_spec($r['spec']);
    if (!$p) { $skipped++; echo "跳过 #{$r['id']} {$r['name']}: spec=[{$r['spec']}] 无法解析\n"; continue; }
    $useQty = parse_single_use($r['single_dose']);
    DB::exec('UPDATE drugs SET spec_dose=?, spec_dose_unit=?, spec_pack_qty=?, spec_pack_unit=?, single_use_qty=? WHERE id=?',
        array($p['dose'], $p['unit'], $p['pack_qty'], $p['pack_unit'], $useQty, $r['id']));
    $updated++;
    echo "  #{$r['id']} {$r['name']}: [{$r['spec']}] → {$p['dose']}{$p['unit']}×{$p['pack_qty']}{$p['pack_unit']} 单次{$useQty}\n";
}
echo "共更新 {$updated} 条，跳过 {$skipped} 条\n";