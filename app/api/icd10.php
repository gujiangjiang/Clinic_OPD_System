<?php
/**
 * ============================================================
 * icd10.php — ICD10 诊断检索与维护接口
 * ============================================================
 * 说明：ICD-10 疾病编码独立字典库，由 DatabaseManager::getIcd10()
 * 访问（独立文件，隔离于业务主库）。支持按编码 / 名称 / 拼音
 * 首字母检索；管理端可新增、编辑、删除诊断（小规模维护），
 * 大范围导入可走管理端数据导入功能。
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
            $icd10->prepare('UPDATE icd10 SET code=?, name=?, pinyin=? WHERE id=?')->execute(array($code, $name, $pinyin, $id));
        } else {
            $icd10->prepare('INSERT INTO icd10(code, name, pinyin) VALUES(?,?,?)')->execute(array($code, $name, $pinyin));
        }
        json_ok(array(), '诊断已保存');
        break;

    /* ---------------- 删除诊断 ---------------- */
    case 'delete':
        $id = (int)post('id', 0);
        $icd10->prepare('DELETE FROM icd10 WHERE id=?')->execute(array($id));
        json_ok(array(), '诊断已删除');
        break;

    default:
        json_fail('未知操作');
}