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

    /** 控制台输出 */
    protected function out($msg) {
        echo "  ✓ {$this->label}：{$msg}\n";
    }
}