<?php
/**
 * ============================================================
 * DataExportImport.php v1.0.0 — 通用数据导入导出核心（零依赖）
 * ============================================================
 * 说明：覆盖科室/药品/人员/检验/检查/处置/诊断 7 大模块：
 *   1. 模板下载：标准中文表头 + 1~2 行示例
 *   2. 数据导出：CSV / UTF-8 with BOM（防 Excel 中文乱码）
 *   3. 导入预检：解析 + 唯一键冲突比对 → valid / conflict / error 分类
 *   4. 确认导入：事务批量写入，冲突策略 skip（忽略）/ overwrite（覆盖）
 * 冲突唯一键：
 *   科室 = 名称；药品 = 名称；人员 = 工号（username）；检验/检查 = 名称；
 *   处置 = 名称；诊断 = ICD-10 编码
 * ============================================================ */
class DataExportImport {

    /**
     * 模块配置：模块名 → 字段定义
     * 每字段：[列名(中文表头), 数据库列, 是否必填, 默认值/示例]
     * 该数组同时驱动模板生成、导出、导入映射。
     */
    public static function modules() {
        return array(
            'dept' => array(
                'title' => '科室',
                'table' => array('dept', 'departments'),
                'key'   => 'name',
                'fields' => array(
                    array('科室名称', 'name', true, '内科门诊'),
                    array('类型', 'type', true, 'clinic'),
                    array('挂号费(元)', 'fee', false, '20'),
                    array('上午号源数', 'am_quota', false, '30'),
                    array('下午号源数', 'pm_quota', false, '30'),
                    array('排序', 'sort', false, '1'),
                ),
            ),
            'drug' => array(
                'title' => '药品',
                'table' => array('drug', 'drugs'),
                'key'   => 'name',
                'fields' => array(
                    array('药品名称', 'name', true, '阿莫西林胶囊'),
                    array('通用名', 'generic_name', false, '阿莫西林'),
                    array('分类', 'category', false, '西药'),
                    array('厂家', 'vendor', false, '华北制药'),
                    array('厂家简称', 'vendor_short', false, '华北'),
                    array('规格', 'spec', false, '0.5g×24粒'),
                    array('包装单位', 'package_unit', false, '盒'),
                    array('剂型', 'form', false, '胶囊'),
                    array('单次剂量', 'single_dose', false, '2粒'),
                    array('用药频次', 'frequency_name', false, '每日三次'),
                    array('给药途径', 'route_name', false, '口服'),
                    array('价格(元)', 'price', false, '12.5'),
                    array('库存量', 'qty', false, '500'),
                ),
            ),
            'user' => array(
                'title' => '人员',
                'table' => array('user', 'users'),
                'key'   => 'username',
                'fields' => array(
                    array('工号', 'emp_no', true, '1001'),
                    array('用户名(登录)', 'username', true, 'cashier2'),
                    array('姓名', 'name', true, '李某某'),
                    array('角色', 'role', true, 'cashier'),
                    array('关联科室ID(逗号分隔)', 'dept_ids', false, ''),
                    array('初始密码', '_password', false, '123456'),
                ),
            ),
            'lab' => array(
                'title' => '检验项目',
                'table' => array('lab', 'lab_items'),
                'key'   => 'name',
                'fields' => array(
                    array('项目名称', 'name', true, '白细胞计数(WBC)'),
                    array('分类', 'category', false, '血液检验'),
                    array('单位', 'unit', false, '×10⁹/L'),
                    array('价格(元)', 'price', false, '5'),
                    array('正常范围', 'normal_range', false, '3.5-9.5'),
                    array('危急值下限', 'critical_low', false, ''),
                    array('危急值上限', 'critical_high', false, ''),
                ),
            ),
            'exam' => array(
                'title' => '检查项目',
                'table' => array('lab', 'exam_items'),
                'key'   => 'name',
                'fields' => array(
                    array('项目名称', 'name', true, '胸部正位X线(DR)'),
                    array('分类', 'category', false, 'DR（数字化X线）'),
                    array('价格(元)', 'price', false, '80'),
                ),
            ),
            'disp' => array(
                'title' => '处置项目',
                'table' => array('disp', 'disposal_items'),
                'key'   => 'name',
                'fields' => array(
                    array('处置名称', 'name', true, '青霉素皮试'),
                    array('费用(元)', 'fee', false, '8'),
                    array('描述', 'description', false, ''),
                ),
            ),
            'icd10' => array(
                'title' => 'ICD-10 诊断',
                'table' => array('icd10', 'icd10'),
                'key'   => 'code',
                'fields' => array(
                    array('编码', 'code', true, 'A09.0'),
                    array('疾病名称', 'name', true, '急性肠炎'),
                    array('拼音首字母', 'pinyin', false, 'JXCY'),
                ),
            ),
        );
    }

    /** 模块存在性校验 */
    public static function module($mod) {
        $all = self::modules();
        return isset($all[$mod]) ? $all[$mod] : null;
    }

    /** CSV 安全单元格（含 ," 换行时加引号） */
    private static function csvCell($v) {
        $v = (string)$v;
        if (strpbrk($v, ",\"\n\r") !== false) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    /**
     * 输出 CSV 下载（UTF-8 with BOM，防 Excel 乱码）
     * @param array  $headers 表头数组
     * @param array  $rows    数据行数组
     * @param string $filename 下载文件名
     */
    public static function download($headers, $rows, $filename) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";  // BOM
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    /** 解析 CSV 内容为行数组（兼容 BOM / \r\n） */
    public static function parse($content) {
        $content = (string)$content;
        // 去 BOM
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        $lines = preg_split("/\r\n|\n|\r/", $content);
        $rows = array();
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    /** 从 CSV 行 + 表头构建关联数组 */
    public static function rowToAssoc($header, $row) {
        $out = array();
        $n = count($header);
        foreach ($row as $i => $val) {
            if ($i < $n) $out[$header[$i]] = trim((string)$val);
        }
        return $out;
    }

    /** 通用：按模块读取全部数据（供导出） */
    public static function fetchAll($mod, $cfg) {
        list($db, $table) = $cfg['table'];
        $cols = array();
        foreach ($cfg['fields'] as $f) {
            if ($f[1] !== '_password') $cols[] = $f[1];
        }
        $rows = DB::q($db, 'SELECT ' . implode(',', $cols) . ' FROM ' . $table . ' ORDER BY id');
        return $rows;
    }
}
