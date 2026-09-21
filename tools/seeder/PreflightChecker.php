<?php
/**
 * ============================================================
 * tools/seeder/PreflightChecker.php — 数据库先验探测
 * ============================================================
 * 任何场景执行前必须先做只读探测：诊断库（ICD-10）、检查/检验/
 * 药品/处置项目依赖缺失时立即终止并给出明确提示，绝不写入脏数据。
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();

/** 先验失败：红字输出并终止（配合事务环境不写入脏数据） */
function preflight_die($msg) {
    fwrite(STDERR, "\033[31m[Preflight Error] " . $msg . "\033[0m\n");
    exit(1);
}

class PreflightChecker {

    /** @var PDO 主库连接 */
    protected $pdo;

    /** @var PDO ICD-10 字典库连接 */
    protected $icd;

    public function __construct() {
        $this->pdo = DatabaseManager::getMain();
        $this->icd = DatabaseManager::getIcd10();
    }

    /** ICD-10 诊断库检查（生成病历前必须存在） */
    public function diagnosis() {
        try {
            $n = (int)$this->icd->query('SELECT COUNT(*) FROM icd10')->fetchColumn();
        } catch (Exception $ex) {
            preflight_die('诊断库不可访问：' . $ex->getMessage());
        }
        if ($n === 0) {
            preflight_die('系统诊断库（ICD-10）为空！无法生成合规病历，请先导入 ICD-10 疾病编码。');
        }
    }

    /** 检查项目依赖 */
    public function exam() {
        $n = (int)$this->pdo->query('SELECT COUNT(*) FROM exam_items')->fetchColumn();
        if ($n === 0) {
            preflight_die('未找到任何【检查项目】，开单终止！请先添加或执行 php tools/bin/seed.php --module=exam。');
        }
    }

    /** 检验项目依赖 */
    public function lab() {
        $n = (int)$this->pdo->query('SELECT COUNT(*) FROM lab_items WHERE is_group=0')->fetchColumn();
        if ($n === 0) {
            preflight_die('未找到任何【检验项目】，开单终止！请先添加或执行 php tools/bin/seed.php --module=lab。');
        }
    }

    /** 药品字典及库存依赖 */
    public function drugs() {
        $n = (int)$this->pdo->query("SELECT COUNT(*) FROM drugs WHERE status='approved' AND qty>0")->fetchColumn();
        if ($n === 0) {
            preflight_die('未找到有效【药品字典及库存】，开单终止！请先补充药品（可执行 --module=drug）。');
        }
    }

    /** 处置项目依赖 */
    public function disposal() {
        $n = (int)$this->pdo->query('SELECT COUNT(*) FROM disposal_items')->fetchColumn();
        if ($n === 0) {
            preflight_die('未找到任何【处置项目】，开单终止！请先添加处置项目（可执行 --module=disposal）。');
        }
    }

    /** 按需批量执行探测
     * @param array $checks 如 ['diagnosis','exam','lab','drugs','disposal'] */
    public function run(array $checks) {
        foreach ($checks as $c) {
            if (method_exists($this, $c)) $this->{$c}();
        }
    }
}