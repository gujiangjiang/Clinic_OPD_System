<?php
/**
 * ============================================================
 * tools/seeder/DeptSeeder.php — 科室生成器
 * ============================================================
 * 临床科室：内科/外科/儿科/妇产科/骨科门诊 + 各专科专家门诊 +
 * 方便门诊/体检中心 + 急诊内科/急诊外科/绿色通道，
 * 医技/辅助科室：检验科/影像科/药房/护士站。
 * 门诊核心科室 id 1-6 保持稳定（与就诊链测试数据一致），新增科室 11+。
 * 历史库兼容：「急诊科」按急诊拆分口径迁移为「急诊内科」（不重复建科）。
 * 非空库按名称幂等对齐：命中的科室同步挂号费/号源/排序（不改 id/状态），
 * 未命中的补建——存量库一次执行即与定义一致。
 * 执行：php tools/bin/seed.php --module=dept
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require __DIR__ . '/Seeder.php';

class DeptSeeder extends Seeder {

    protected $label = '科室';

    /** @var array [id, 名称, 类型, 挂号费, 上午号源, 下午号源, 排序]（id=0 表示空库按序自增） */
    protected $defs = array(
        // 核心科室：固定 id 1-10（门诊/急诊 1-6 + 医技辅助 7-10，就诊链/叫号数据依赖）
        array(1,  '内科门诊',       'clinic',    20, 30, 30, 1),
        array(2,  '外科门诊',       'clinic',    20, 25, 25, 2),
        array(3,  '儿科门诊',       'clinic',    15, 25, 25, 3),
        array(4,  '妇产科门诊',     'clinic',    20, 20, 20, 4),
        array(5,  '急诊内科',       'emergency', 50, 0, 0, 20),
        array(6,  '绿色通道',       'emergency',  0, 0, 0, 22),
        array(7,  '检验科',         'tech',       0, 0, 0, 90),
        array(8,  '影像科',         'tech',       0, 0, 0, 91),
        array(9,  '药房',           'other',      0, 0, 0, 92),
        array(10, '护士站',         'other',      0, 0, 0, 93),
        // 扩展门诊：空库按顺序自增 id 11+；存量库按名称幂等补建
        array(0,  '骨科门诊',       'clinic',    25, 20, 20, 5),
        array(0,  '方便门诊',       'clinic',     5, 50, 50, 6),
        array(0,  '体检中心',       'clinic',     0, 20, 20, 7),
        array(0,  '内科专家门诊',   'clinic',    50, 15, 15, 8),
        array(0,  '外科专家门诊',   'clinic',    50, 12, 12, 9),
        array(0,  '骨科专家门诊',   'clinic',    50, 12, 12, 10),
        array(0,  '儿科专家门诊',   'clinic',    30, 15, 15, 11),
        array(0,  '妇产科专家门诊', 'clinic',    50, 12, 12, 12),
        array(0,  '急诊外科',       'emergency', 50, 0, 0, 21),
    );

    /** @var array 历史科室名 → 现名（存量库迁移口径：急诊科拆分为急诊内科/急诊外科） */
    protected $legacyNames = array('急诊科' => '急诊内科');

    public function run() {
        $pdo = $this->pdo;
        $deptCount = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
        $created = 0; $updated = 0;
        if ($deptCount === 0) {
            // 空库：按定义完整重建（核心科室固定 id，扩展门诊按顺序自增，保证 id 稳定）
            $insId = $pdo->prepare('INSERT INTO departments(id, name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES(?,?,?,?,?,?,?,1,?)');
            $insAuto = $pdo->prepare('INSERT INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES(?,?,?,?,?,?,1,?)');
            foreach ($this->defs as $d) {
                if ($d[0] > 0) {
                    $insId->execute(array($d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], now_str()));
                } else {
                    $insAuto->execute(array($d[1], $d[2], $d[3], $d[4], $d[5], $d[6], now_str()));
                }
                $created++;
            }
        } else {
            // 非空库：按名称对齐——命中同步 挂号费/号源/排序（不改 id/状态），未命中补建
            $byName = array();
            foreach ($pdo->query('SELECT id, name FROM departments') as $r) {
                $byName[(string)$r['name']] = (int)$r['id'];
            }
            $upd = $pdo->prepare('UPDATE departments SET name=?, fee=?, am_quota=?, pm_quota=?, sort=? WHERE id=?');
            $ins = $pdo->prepare('INSERT INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES(?,?,?,?,?,?,1,?)');
            foreach ($this->defs as $d) {
                $id = $this->matchId($d[1], $byName);
                if ($id) {
                    $upd->execute(array($d[1], $d[3], $d[4], $d[5], $d[6], $id));
                    $updated++;
                } else {
                    $ins->execute(array($d[1], $d[2], $d[3], $d[4], $d[5], $d[6], now_str()));
                    $created++;
                }
            }
        }
        $total = (int)$pdo->query('SELECT COUNT(*) FROM departments WHERE status=1')->fetchColumn();
        $this->out('新增 ' . $created . ' / 同步 ' . $updated . ' 个，共 ' . $total . ' 个启用科室（临床 + 医技）');
        return $created;
    }

    /** 按名称匹配既有科室 id；命中历史名（如「急诊科」）时登记为新名，避免重复建科 */
    protected function matchId($name, &$byName) {
        if (isset($byName[$name])) return $byName[$name];
        foreach ($this->legacyNames as $old => $new) {
            if ($new !== $name || !isset($byName[$old])) continue;
            $id = $byName[$old];
            unset($byName[$old]);
            $byName[$name] = $id;
            return $id;
        }
        return 0;
    }
}

$s = new DeptSeeder();
$s->run();
