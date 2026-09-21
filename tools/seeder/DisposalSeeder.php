<?php
/**
 * ============================================================
 * tools/seeder/DisposalSeeder.php — 护理/处置/治疗项目生成器
 * ============================================================
 * 执行：php tools/bin/seed.php --module=disposal
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require __DIR__ . '/Seeder.php';

class DisposalSeeder extends Seeder {

    protected $label = '处置项目';

    /** @var array [名称, 费用, 需护士站处理] */
    protected $defs = [
['清创缝合术(小)', 150, 0],
['清创缝合术(中)', 260, 0],
['清创缝合术(大)', 400, 0],
['换药(小)', 30, 1],
['换药(大)', 50, 1],
['静脉输液', 12, 1],
['肌肉注射', 8, 1],
['皮下注射', 6, 0],
['雾化吸入', 20, 1],
['导尿术', 60, 1],
['气管插管', 300, 1],
['除颤', 200, 1],
['心房按压', 50, 1],
['肛门手动直', 80, 1],
['胸腔穿刺', 120, 1],
['腹腔穿刺', 100, 1],
['关节穿刺', 150, 1],
['皮试(青霉素)', 6, 1],
['皮试(头孢类)', 6, 1],
['血压监测(24h)', 40, 1],
['氧疗', 15, 1],
['换气', 200, 1],
['电疗', 35, 1],
['心电监护', 60, 1],
['血氧饱和度监测', 30, 1],
['吸痰护理', 15, 1],
['鼻饲管置入术', 40, 1],
['胃管置入术', 45, 1],
['留置针置入术', 20, 1],
['静脉采血', 10, 1],
['动脉采血', 15, 1],
['血糖测定(床旁)', 8, 1],
['尿常规床旁检测', 12, 1],
['灌肠术', 35, 1],
['洗胃术', 80, 1],
['胃肠减压术', 30, 1],
['膀胱冲洗术', 25, 1],
['会阴护理', 15, 1],
['口腔护理', 12, 1],
['褥疮护理', 20, 1],
['冷疗', 15, 1],
['热疗', 15, 1],
['针灸治疗', 30, 0],
['推拿治疗', 35, 0],
['拔罐治疗', 25, 0],
['低频脉冲电治疗', 40, 1],
['红外线照射治疗', 30, 1],
['超声波治疗', 45, 1],
['伤口拆线', 20, 1],
['伤口换药(中)', 40, 1],
['石膏拆除术', 25, 1],
['外固定架调整', 50, 1],
['牵引术', 60, 0],
['关节松动术', 40, 1],
['无菌换药包', 15, 1],
['雾化加药处置包', 10, 1],
    ];

    public function run() {
        $created = 0;
        $stmt = $this->pdo->prepare('SELECT id FROM disposal_items WHERE name=?');
        $ins = $this->pdo->prepare('INSERT INTO disposal_items(name,fee,is_nurse,description,status,created_at) VALUES(?,?,?,?,?,?)');
        foreach ($this->defs as $row) {
            $stmt->execute(array($row[0]));
            if (!$stmt->fetchColumn()) {
                $ins->execute(array($row[0], $row[1], $row[2], '', 'approved', now_str()));
                $created++;
            }
        }
        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM disposal_items WHERE status='approved'")->fetchColumn();
        $this->out('新增 ' . $created . ' 项，共 ' . $total . ' 项处置项目');
        return $created;
    }
}

$s = new DisposalSeeder();
$s->run();
