<?php
/**
 * ============================================================
 * icd10.php — ICD10 诊断检索接口
 * ============================================================
 * 说明：病历初步诊断联动：诊断框输入关键字时实时检索，
 * 支持疾病名称 / ICD编码 / 拼音首字母三种方式。
 * ============================================================ */
require __DIR__ . '/_init.php';

switch ($action) {

    /* ---------------- 搜索 ---------------- */
    case 'search':
        $kw = get('kw', '');
        if ($kw === '') {
            json_ok(array('list' => array()));
        }
        $like = '%' . $kw . '%';
        $list = DB::q('icd10', "SELECT id, code, name, pinyin FROM icd10
            WHERE code LIKE ? OR name LIKE ? OR pinyin LIKE ? ORDER BY id LIMIT 20",
            array($like, $like, strtoupper($like)));
        $out = array();
        foreach ($list as $row) {
            $out[] = array(
                'diagnosis_code' => $row['code'],
                'diagnosis_name' => $row['name'],
                'pinyin'         => $row['pinyin'],
            );
        }
        json_ok(array('list' => $out));
        break;

    default:
        json_fail('未知操作');
}
