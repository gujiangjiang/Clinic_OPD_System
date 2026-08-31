<?php
/**
 * ============================================================
 * icd10.php — ICD10 诊断检索与维护接口
 * ============================================================
 * 说明：ICD-10 疾病编码独立字典库，通过 Icd10Repository 访问
 * （内部对接 DatabaseManager::getIcd10()，独立文件隔离于业务主库）。
 * 本文件仅做入参解析、校验与 JSON 响应组装，不含任何原生 SQL。
 * ============================================================ */
require __DIR__ . '/_init.php';

switch ($action) {

    /* ---------------- 搜索 ---------------- */
    case 'search':
        $kw = get('kw', '');
        if ($kw === '') {
            json_ok(array('list' => array()));
        }
        $out = array();
        foreach (Icd10Repository::search($kw, 20) as $row) {
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
        list($rows, $total) = Icd10Repository::paginate($kw, $limit, $offset);
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
        if (Icd10Repository::findDuplicate($code, $name, $id)) json_fail('该诊断编码或名称已存在');
        if ($id > 0) {
            Icd10Repository::update($id, $code, $name, $pinyin);
        } else {
            Icd10Repository::create($code, $name, $pinyin);
        }
        json_ok(array(), '诊断已保存');
        break;

    /* ---------------- 删除诊断 ---------------- */
    case 'delete':
        Icd10Repository::remove((int)post('id', 0));
        json_ok(array(), '诊断已删除');
        break;

    default:
        json_fail('未知操作');
}