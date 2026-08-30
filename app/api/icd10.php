<?php
/**
 * ============================================================
 * icd10.php — ICD10 诊断检索接口（只读字典库）
 * ============================================================
 * 说明：ICD-10 疾病编码独立只读库，由 DatabaseManager::getIcd10()
 * 以 PRAGMA query_only 打开。支持按编码 / 名称 / 拼音首字母检索。
 * 管理端维护 ICD-10 诊断需通过其他途径（如直接导入文件）。
 * ============================================================ */
require __DIR__ . '/_init.php';

$icd10 = DatabaseManager::getIcd10();

switch ($action) {

    /* ---------------- 搜索 ---------------- */
    case 'search':
        $kw = get('kw', '');
        if ($kw === '') {
            json_ok(array('list' => array()));
        }
        $like = '%' . $kw . '%';
        $st = $icd10->prepare("SELECT id, code, name, pinyin FROM icd10
            WHERE code LIKE ? OR name LIKE ? OR pinyin LIKE ? ORDER BY id LIMIT 20");
        $st->execute(array($like, $like, strtoupper($like)));
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach ($list as $row) {
            $out[] = array(
                'id'             => (int)$row['id'],
                'icd10_code'     => $row['code'],
                'diagnosis_name' => $row['name'],
                'pinyin'         => $row['pinyin'],
            );
        }
        json_ok(array('list' => $out));
        break;

    /* ---------------- 诊断列表（管理端，支持检索与分页） ---------------- */
    case 'list':
        $kw = get('kw', '');
        $limit = (int)get('limit', 50);
        if ($limit <= 0 || $limit > 200) $limit = 50;
        $offset = (int)get('offset', 0);
        if ($offset < 0) $offset = 0;
        $sql = 'SELECT id, code, name, pinyin FROM icd10';
        $params = array();
        $where = '';
        if ($kw !== '') {
            $like = '%' . $kw . '%';
            $where = ' WHERE code LIKE ? OR name LIKE ? OR pinyin LIKE ?';
            $params = array($like, $like, strtoupper($like));
        }
        $st = $icd10->prepare('SELECT COUNT(*) FROM icd10' . $where);
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = $icd10->prepare($sql . $where . ' ORDER BY code ASC LIMIT ' . $limit . ' OFFSET ' . $offset);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id' => (int)$row['id'], 'code' => $row['code'],
                'name' => $row['name'], 'pinyin' => $row['pinyin'],
            );
        }
        json_ok(array('list' => $out, 'total' => $total, 'offset' => $offset, 'limit' => $limit));
        break;

    /* ---------------- 保存/删除（只读库，拒绝写操作） ---------------- */
    case 'save':
    case 'delete':
        json_fail('ICD-10 字典库为只读，请通过管理端数据导入功能维护');
        break;

    default:
        json_fail('未知操作');
}