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
                'id'             => (int)$row['id'],
                'diagnosis_code' => $row['code'],
                'diagnosis_name' => $row['name'],
                'pinyin'         => $row['pinyin'],
            );
        }
        json_ok(array('list' => $out));
        break;

    /* ---------------- 诊断列表（管理端，支持检索） ---------------- */
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
        // 总数（分页「加载更多」用）
        $total = (int)DB::val('icd10', 'SELECT COUNT(*) FROM icd10' . $where, $params);
        $rows = DB::q('icd10', $sql . $where . ' ORDER BY id ASC LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id' => (int)$row['id'], 'code' => $row['code'],
                'name' => $row['name'], 'pinyin' => $row['pinyin'],
            );
        }
        json_ok(array('list' => $out, 'total' => $total, 'offset' => $offset, 'limit' => $limit));
        break;

    /* ---------------- 保存诊断（新增/编辑，拼音首字母自动生成） ---------------- */
    case 'save':
        $id = (int)post('id', 0);
        $code = strtoupper(post('code', ''));
        $name = post('name', '');
        $pinyin = strtoupper(post('pinyin', ''));
        if ($code === '') json_fail('请填写诊断编码（ICD10）');
        if ($name === '') json_fail('请填写诊断名称');
        if ($pinyin === '') {
            // 自动生成诊断名称拼音首字母，便于快速检索
            $pinyin = pinyin_initial($name);
        }
        // 编码+名称 唯一性校验
        $dup = DB::one('icd10', 'SELECT id FROM icd10 WHERE (code=? OR name=?) AND id<>?', array($code, $name, $id));
        if ($dup) json_fail('该诊断编码或名称已存在');
        if ($id > 0) {
            DB::exec('icd10', 'UPDATE icd10 SET code=?, name=?, pinyin=? WHERE id=?', array($code, $name, $pinyin, $id));
        } else {
            DB::insert('icd10', 'INSERT INTO icd10(code, name, pinyin) VALUES(?,?,?)', array($code, $name, $pinyin));
        }
        json_ok(array(), '诊断已保存');
        break;

    /* ---------------- 删除诊断 ---------------- */
    case 'delete':
        $id = (int)post('id', 0);
        DB::exec('icd10', 'DELETE FROM icd10 WHERE id=?', array($id));
        json_ok(array(), '诊断已删除');
        break;

    default:
        json_fail('未知操作');
}
