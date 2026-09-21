<?php
/**
 * ============================================================
 * tools/seeder/DeptSeeder.php — 科室生成器
 * ============================================================
 * 临床科室（内科/外科/儿科/妇产科门诊 + 急诊科/绿色通道，id 1-6）
 * 与医技/辅助科室（检验科/影像科/药房/护士站，id 7-10）。
 * 门诊科室 id 固定 1-6（与就诊链测试数据的科室依赖保持一致）。
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

    /** @var array [id, 名称, 类型, 挂号费, 上午号源, 下午号源, 排序] */
    protected $defs = [
        [1, '内科门诊', 'clinic', 20, 30, 30, 1],
        [2, '外科门诊', 'clinic', 20, 25, 25, 2],
        [3, '儿科门诊', 'clinic', 15, 25, 25, 3],
        [4, '妇产科门诊', 'clinic', 20, 20, 20, 4],
        [5, '急诊科', 'emergency', 50, 0, 0, 5],
        [6, '绿色通道', 'emergency', 0, 0, 0, 6],
        [7, '检验科', 'tech', 0, 0, 0, 90],
        [8, '影像科', 'tech', 0, 0, 0, 91],
        [9, '药房', 'other', 0, 0, 0, 92],
        [10, '护士站', 'other', 0, 0, 0, 93],
    ];

    public function run() {
        $pdo = $this->pdo;
        $deptCount = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
        $regCount = (int)$pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
        $created = 0;
        if ($deptCount === 0) {
            // 空库：按定义完整重建（固定 id，保证门诊科室 id 稳定，避免挂号科室错乱）
            $ins = $pdo->prepare('INSERT INTO departments(id, name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES(?,?,?,?,?,?,?,1,?)');
            foreach ($this->defs as $d) {
                $ins->execute(array($d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], now_str()));
                $created++;
            }
        } else {
            // 非空库：仅按名称补缺失科室（不改动既有科室 id/号源，防止已有数据错乱）
            $stmt = $pdo->prepare('SELECT id FROM departments WHERE name=?');
            $ins = $pdo->prepare('INSERT INTO departments(name, type, fee, am_quota, pm_quota, sort, status, created_at) VALUES(?,?,?,?,?,?,1,?)');
            foreach ($this->defs as $d) {
                $stmt->execute(array($d[1]));
                if (!$stmt->fetchColumn()) {
                    $ins->execute(array($d[1], $d[2], $d[3], $d[4], $d[5], $d[6], now_str()));
                    $created++;
                }
            }
        }
        unset($regCount);
        $total = (int)$pdo->query('SELECT COUNT(*) FROM departments WHERE status=1')->fetchColumn();
        $this->out('新增 ' . $created . ' 个，共 ' . $total . ' 个启用科室（临床 + 医技）');
        return $created;
    }
}

$s = new DeptSeeder();
$s->run();
