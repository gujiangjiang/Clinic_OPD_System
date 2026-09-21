<?php
/**
 * ============================================================
 * tools/seeder/PackageSeeder.php — 全院公共套餐模板生成器
 * ============================================================
 * 管理员身份发布（scope=hospital 全院可见），幂等按标题覆盖
 * （保留旧 id 以防开单引用）。套餐类型：处方/检验/检查/处置，
 * 明细含拆零/单位/结构化剂量（加载自各目录 seeder 生成的基础字典）。
 * 执行：php tools/bin/seed.php --module=package
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require __DIR__ . '/Seeder.php';

class PackageSeeder extends Seeder {

    protected $label = '全院套餐';

    /** @var array [套餐名称, 类型, 明细构造[目录表, 项目名, 数量, 单位类型(pack/min，仅处方)]] */
    protected $defs = [
        ['成人上呼吸道感染（感冒）口服套餐', 'prescription', [
            array('drugs', '感康片', 1, 'pack'),
            array('drugs', '盐酸氨溴索口服液', 1, 'pack'),
            array('drugs', '感冒清热颗粒', 1, 'pack'),
        ]],
        ['急性支气管炎门诊静脉输液套餐', 'prescription', [
            array('drugs', '0.9%氯化钠注射液', 1, 'pack'),
            array('drugs', '硫酸庆大霉素注射液', 2, 'min'),
            array('drugs', '地塞米松磷酸钠注射液', 1, 'min'),
        ]],
        ['血常规及感染筛查套餐', 'lab', [
            array('lab_items', '血常规二十项', 1, ''),
            array('lab_items', 'C反应蛋白(CRP)', 1, ''),
            array('lab_items', '降钙素原(PCT)', 1, ''),
        ]],
        ['肝肾功能及生化基础套餐', 'lab', [
            array('lab_items', '肝功能十项', 1, ''),
            array('lab_items', '肾功能三项', 1, ''),
            array('lab_items', '空腹血糖', 1, ''),
        ]],
        ['胸部基础影像检查套餐', 'imaging', [
            array('exam_items', '胸部正位X线(DR)', 1, ''),
        ]],
        ['腹部基础超声筛查套餐', 'imaging', [
            array('exam_items', '腹部彩超', 1, ''),
        ]],
        ['常规心电生理检查', 'imaging', [
            array('exam_items', '12导心电图', 1, ''),
        ]],
        ['门诊小伤口清创缝合套餐', 'procedure', [
            array('disposal_items', '清创缝合术(小)', 1, ''),
            array('disposal_items', '无菌换药包', 1, ''),
        ]],
        ['常规雾化吸入治疗套餐', 'procedure', [
            array('disposal_items', '雾化吸入', 1, ''),
            array('disposal_items', '雾化加药处置包', 1, ''),
        ]],
    ];

    public function run() {
        $pdo = $this->pdo;
        $adminRow = $pdo->query("SELECT id, name FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $adminId = $adminRow ? (int)$adminRow['id'] : 1;
        $adminName = $adminRow ? (string)$adminRow['name'] : '系统管理员';

        $created = 0; $skipped = 0;
        foreach ($this->defs as $pkg) {
            $items = array();
            foreach ($pkg[2] as $ref) {
                $it = $this->buildItem($ref[0], $ref[1], $ref[2], $ref[3]);
                if (!$it) { $skipped++; continue; }
                $items[] = $it;
            }
            if (!$items) continue;
            // 幂等：同标题全院套餐覆盖（保留旧 id 以防开单引用，更新内容）
            $oldPkg = $pdo->query("SELECT id FROM packages WHERE title=" . $pdo->quote($pkg[0]) . " AND scope='hospital' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $content = json_encode(array('items' => $items), JSON_UNESCAPED_UNICODE);
            if ($oldPkg) {
                $pdo->prepare('UPDATE packages SET type=?, content_json=?, updated_at=? WHERE id=?')
                    ->execute(array($pkg[1], $content, now_str(), (int)$oldPkg['id']));
            } else {
                $pdo->prepare('INSERT INTO packages(title,type,scope,creator_id,creator_name,status,content_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
                    ->execute(array($pkg[0], $pkg[1], 'hospital', $adminId, $adminName, 'published', $content, now_str(), now_str()));
            }
            $created++;
        }
        $total = (int)$pdo->query("SELECT COUNT(*) FROM packages WHERE scope='hospital'")->fetchColumn();
        $this->out('生成 ' . $created . ' 组，共 ' . $total . ' 组全院公共套餐（跳过缺项 ' . $skipped . ' 项）');
        return $created;
    }

    /** 按目录表加载项目并构造套餐明细（含拆零/单位/结构化剂量/组合成员） */
    private function buildItem($table, $name, $quantity, $unitType) {
        $row = $this->pdo->query("SELECT * FROM {$table} WHERE name=" . $this->pdo->quote($name) . " AND status='approved' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($table === 'drugs') return $this->drugItem($row, $quantity, $unitType);
        if ($table === 'lab_items') return $this->labItem($row, $quantity);
        if ($table === 'disposal_items') return $this->dispItem($row, $quantity);
        return $this->examItem($row);
    }

    /** 药品明细（拆零限制：不允许拆零药品强制按包装单位） */
    private function drugItem($row, $quantity, $unitType) {
        $ps = max(1, (int)$row['spec_pack_qty']);
        $unitType = ($unitType === 'min') ? 'min' : 'pack';
        if ($unitType === 'min' && (int)$row['allow_split'] !== 1) $unitType = 'pack';
        $packUnit = trim((string)$row['package_unit']);
        if ($packUnit === '') $packUnit = '盒';
        $minUnit = trim((string)$row['spec_pack_unit']);
        $packPrice = (float)$row['price'];
        $salePrice = ($unitType === 'min') ? round($packPrice / $ps, 4) : $packPrice;
        $saleUnit = ($unitType === 'min') ? ($minUnit !== '' ? $minUnit : '个') : $packUnit;
        $singleUseQty = (float)$row['single_use_qty'];
        $doseVal = round($singleUseQty * (float)$row['spec_dose'], 4);
        $doseUnit = trim((string)$row['spec_dose_unit']);
        $singleDose = rtrim(rtrim(number_format($doseVal, 4, '.', ''), '0'), '.') . $doseUnit;
        return array(
            'item_id' => (int)$row['id'], 'sub_of' => 0,
            'item_name' => $row['name'], 'spec' => $row['spec'],
            'unit' => $saleUnit, 'company_short' => $row['vendor_short'],
            'price' => $salePrice, 'pack_price' => $packPrice,
            'quantity' => max(1, (int)$quantity),
            'single_dose' => $singleDose, 'frequency' => $row['frequency'], 'route' => $row['route'],
            'nurse_required' => (int)$row['is_nurse'],
            'is_skin_test' => (int)$row['is_skin_test'], 'skin_test_item_id' => (int)$row['skin_test_item_id'],
            'spec_dose' => (float)$row['spec_dose'], 'spec_dose_unit' => $row['spec_dose_unit'],
            'spec_pack_qty' => $ps, 'spec_pack_unit' => $row['spec_pack_unit'],
            'single_use_qty' => $singleUseQty,
            'unit_type' => $unitType, 'pack_unit' => $packUnit, 'allow_split' => (int)$row['allow_split'],
            'is_group' => 0, 'members' => '', 'member_ids' => '',
        );
    }

    /** 检验单项/组合明细（组合展开成员列表） */
    private function labItem($row, $qty) {
        $members = ''; $memberIds = '';
        if ((int)$row['is_group'] === 1) {
            $mN = array(); $mI = array();
            foreach ($this->pdo->query('SELECT li.id, li.name FROM lab_items li WHERE li.id IN (SELECT item_id FROM lab_group_members WHERE group_id=' . (int)$row['id'] . ') ORDER BY li.id') as $m) {
                $mN[] = $m['name']; $mI[] = (int)$m['id'];
            }
            $members = implode('、', $mN); $memberIds = implode(',', $mI);
        }
        return array(
            'item_id' => (int)$row['id'], 'sub_of' => 0, 'item_name' => $row['name'],
            'spec' => $members, 'unit' => $row['unit'], 'company_short' => '',
            'price' => (float)$row['price'], 'pack_price' => (float)$row['price'],
            'quantity' => max(1, (int)$qty), 'single_dose' => '', 'frequency' => '', 'route' => '',
            'nurse_required' => 0, 'is_skin_test' => 0, 'skin_test_item_id' => 0,
            'spec_dose' => 0, 'spec_dose_unit' => '', 'spec_pack_qty' => 1, 'spec_pack_unit' => '',
            'single_use_qty' => 1, 'unit_type' => 'pack', 'pack_unit' => '', 'allow_split' => 0,
            'is_group' => (int)$row['is_group'], 'members' => $members, 'member_ids' => $memberIds,
        );
    }

    /** 检查项目明细 */
    private function examItem($row) {
        return array(
            'item_id' => (int)$row['id'], 'sub_of' => 0, 'item_name' => $row['name'],
            'spec' => '', 'unit' => '', 'company_short' => '',
            'price' => (float)$row['price'], 'pack_price' => (float)$row['price'],
            'quantity' => 1, 'single_dose' => '', 'frequency' => '', 'route' => '',
            'nurse_required' => 0, 'is_skin_test' => 0, 'skin_test_item_id' => 0,
            'spec_dose' => 0, 'spec_dose_unit' => '', 'spec_pack_qty' => 1, 'spec_pack_unit' => '',
            'single_use_qty' => 1, 'unit_type' => 'pack', 'pack_unit' => '', 'allow_split' => 0,
            'is_group' => 0, 'members' => '', 'member_ids' => '',
        );
    }

    /** 处置项目明细 */
    private function dispItem($row, $qty) {
        return array(
            'item_id' => (int)$row['id'], 'sub_of' => 0, 'item_name' => $row['name'],
            'spec' => '', 'unit' => '次', 'company_short' => '',
            'price' => (float)$row['fee'], 'pack_price' => (float)$row['fee'],
            'quantity' => max(1, (int)$qty), 'single_dose' => '', 'frequency' => '', 'route' => '',
            'nurse_required' => (int)$row['is_nurse'], 'is_skin_test' => 0, 'skin_test_item_id' => 0,
            'spec_dose' => 0, 'spec_dose_unit' => '', 'spec_pack_qty' => 1, 'spec_pack_unit' => '',
            'single_use_qty' => 1, 'unit_type' => 'pack', 'pack_unit' => '', 'allow_split' => 0,
            'is_group' => 0, 'members' => '', 'member_ids' => '',
        );
    }
}

$s = new PackageSeeder();
$s->run();
