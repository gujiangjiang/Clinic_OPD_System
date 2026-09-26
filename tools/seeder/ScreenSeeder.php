<?php
/**
 * ============================================================
 * tools/seeder/ScreenSeeder.php — 叫号大屏/诊室窗口生成器
 * ============================================================
 * 严禁写死固定诊室/窗口名称，必须根据数据库现存的科室分类动态生成：
 *  - 临床科室（门诊/急诊） → 【xx1诊室】【xx2诊室】（doctor）
 *  - 检验科                 → 【xx1号采血窗口】（lab）
 *  - 影像科                 → 读取检查分类（CT/DR/超声…）生成【CT室】【DR室】【彩超室】（imaging）
 *  - 药房                   → 【xx1号发药窗口】（pharmacy）
 *  - 护士站                 → 【xx输液室】（nurse）
 * 执行：php tools/bin/seed.php --module=screen
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require __DIR__ . '/Seeder.php';

class ScreenSeeder extends Seeder {

    protected $label = '叫号大屏';

    public function run() {
        // 可重复执行：先清空旧大屏映射
        $old = (int)$this->pdo->query('SELECT COUNT(*) FROM clinic_rooms')->fetchColumn();
        $this->pdo->exec('DELETE FROM clinic_rooms');
        $count = 0;
        $warns = 0;
        foreach ($this->pdo->query('SELECT id, name, type FROM departments ORDER BY type, sort, id') as $d) {
            $id = (int)$d['id'];
            $name = trim((string)$d['name']);
            $type = (string)$d['type'];
            if ($type === 'clinic' || $type === 'emergency') {
                // 临床科室：动态生成 2 个诊室（诊室名不含科室前缀，科室名由叫号时自动拼接）
                // 急诊科默认允许跨天叫号（夜班场景）
                $crossDay = ($type === 'emergency');
                $this->addRoom($id, '1诊室', 'doctor', $crossDay);
                $this->addRoom($id, '2诊室', 'doctor', $crossDay);
                $count += 2;
            } elseif (mb_strpos($name, '检验') !== false) {
                $this->addRoom($id, '1号采血窗口', 'lab');
                $count++;
            } elseif (mb_strpos($name, '影像') !== false) {
                // 影像科：读取管理员设置的检查分类（CT/DR/超声等）动态生成检查室
                $cats = $this->pdo->query("SELECT name FROM item_categories WHERE ctype='exam' ORDER BY sort, id")->fetchAll(PDO::FETCH_COLUMN);
                if (!$cats) {
                    fwrite(STDERR, "  \033[33m[ScreenSeeder] 影像科「{$name}」未设置检查分类（CT/DR/超声…），跳过，严禁臆造检查室。\033[0m\n");
                    $warns++;
                    continue;
                }
                foreach ($cats as $c) {
                    $this->addRoom($id, $c . '室', 'imaging');
                    $count++;
                }
            } elseif (mb_strpos($name, '药') !== false) {
                $this->addRoom($id, '1号发药窗口', 'pharmacy');
                $count++;
            } elseif (mb_strpos($name, '护士') !== false) {
                $this->addRoom($id, '输液室', 'nurse');
                $count++;
            }
        }
        if ($count === 0) {
            fwrite(STDERR, "  \033[33m[ScreenSeeder] 未匹配到任何可生成大屏的科室（临床/检验/影像/药房/护士站）。\033[0m\n");
        }
        $this->out('清理旧大屏 ' . $old . ' 块，动态生成 ' . $count . ' 块大屏/诊室' . ($warns ? '（跳过 ' . $warns . ' 个无分类科室）' : ''));
        return $count;
    }

    /** 插入一块大屏（自动生成 Token；默认开启脱敏；急诊等可传 allowCrossDay） */
    protected function addRoom($deptId, $roomName, $roomType, $allowCrossDay = false) {
        $token = bin2hex(random_bytes(16));
        $sql = 'INSERT INTO clinic_rooms(dept_id, room_name, room_type, screen_token, enable_voice, enable_mask, allow_cross_day, tip_interval, created_at, updated_at) VALUES(?,?,?,?,1,1,?,5,?,?)';
        $this->pdo->prepare($sql)->execute(array($deptId, $roomName, $roomType, $token, $allowCrossDay ? 1 : 0, now_str(), now_str()));
    }
}

$s = new ScreenSeeder();
$s->run();