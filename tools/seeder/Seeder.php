<?php
/**
 * ============================================================
 * tools/seeder/Seeder.php — Seeder 工厂基类
 * ============================================================
 * 模块化造数架构的领域数据工厂基类。各 Seeder（UserSeeder / DrugSeeder /
 * ItemSeeder / PackageSeeder / PatientSeeder / QueueSeeder / VisitSeeder）
 * 继承本类，实现 run() 单一职责职责；由 tools/bin/seed.php 统一调度
 * （--module=drug → DrugSeeder，场景装配由 scenarios/ 组合多个 Seeder）。
 *
 * 开发铁律：后续任何测试造数需求，严禁在 tools/ 根目录新建孤立
 * seed_xxx.php 脚本，必须在 tools/seeder/ 或 tools/scenarios/ 中扩展复用。
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();

abstract class Seeder {

    /** @var PDO 主库连接 */
    protected $pdo;

    /** @var PDO ICD-10 字典库连接 */
    protected $icd10pdo;

    /** 领域名称（控制台输出前缀） */
    protected $label = '数据';

    public function __construct() {
        $this->pdo = DatabaseManager::getMain();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->icd10pdo = DatabaseManager::getIcd10();
    }

    /** 各 Seeder 实现：执行本领域数据填充，返回影响计数 */
    abstract public function run();

    /** 随机整数 [a,b] */
    protected function rnd($a, $b) {
        return mt_rand($a, $b);
    }

    /** 随机取数组元素 */
    protected function pick($arr) {
        return $arr[array_rand($arr)];
    }

    /** 收费员账号（挂号/缴费流水署名，取库中真实收费员；无则回退 0/空名） */
    protected function cashierUser() {
        $u = $this->pdo->query("SELECT id, name FROM users WHERE role='cashier' AND status=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $u ? array('id' => (int)$u['id'], 'name' => (string)$u['name']) : array('id' => 0, 'name' => '');
    }

    /**
     * 存储口径时间串：epoch → 落库值（utc 模式 = UTC；local 模式 = 站点墙钟）。
     * 造数全链路凡写 *_at/*_time 存储列必须经此（或 store_time），
     * 业务日期列（reg_date 等）仍用 date('Y-m-d', $ts)（站点时区业务日）。
     */
    protected function storeTime($ts) {
        return store_time($ts);
    }

    /** 存储口径解析：存储串 → epoch（模式感知；见 helpers.d/time.php::ts_epoch） */
    protected function storeTs($s) {
        return ts_epoch($s);
    }

    /** 控制台输出 */
    protected function out($msg) {
        echo "  ✓ {$this->label}：{$msg}\n";
    }
}