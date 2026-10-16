<?php
/**
 * ============================================================
 * tools/seeder/UserSeeder.php — 用户账号生成器
 * ============================================================
 * 测试账号：管理员 / 收费员 / 医生×10 / 护士 / 检验 / 影像 / 药房，
 * 初始密码均为 123456（仅本地/测试环境使用）。
 *
 * 医生出诊权限不手工罗列，而是「内在定义专科 + 职称」自动派生：
 *  · 专科对应门诊（骨科医生兼可出外科门诊）；
 *  · 专科对应急诊（内→急诊内科；外、骨→急诊外科；儿科、妇产科无对应急诊）；
 *  · 副主任医师及以上叠加对应专家门诊；
 *  · 具备急诊出诊权限者同步开通绿色通道；方便门诊/体检中心无急诊权限，
 *    不同步绿色通道。
 * 已存在工号同步 username（拼音）/name（中文）；医生账号另同步 dept_ids
 * （出诊权限）与 title，保证权限模型升级后存量库一次执行即对齐。
 * 执行：php tools/bin/seed.php --module=user
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require __DIR__ . '/Seeder.php';

class UserSeeder extends Seeder {

    protected $label = '用户账号';

    /** @var array [emp_no, username(姓名拼音), name(中文), role, title(职称), specialty(专科)] */
    protected $defs = array(
        array('0001', 'admin', '系统管理员', 'admin', '', ''),
        array('1001', 'liudan', '刘丹', 'cashier', '', ''),
        array('2001', 'zhangwei', '张伟', 'doctor', '主治医师', '骨科'),
        array('2002', 'lina', '李娜', 'doctor', '主治医师', '内科'),
        array('2003', 'wangqiang', '王强', 'doctor', '副主任医师', '外科'),
        array('2004', 'zhaomin', '赵敏', 'doctor', '主任医师', '内科'),
        array('2005', 'liuyang', '刘洋', 'doctor', '副主任医师', '儿科'),
        array('2006', 'qianfeng', '钱峰', 'doctor', '主任医师', '骨科'),
        array('2007', 'sunli', '孙丽', 'doctor', '副主任医师', '妇产科'),
        array('2008', 'machao', '马超', 'doctor', '主治医师', '外科'),
        array('2009', 'zhengxia', '郑霞', 'doctor', '主治医师', '方便门诊'),
        array('2010', 'hejianguo', '何建国', 'doctor', '主任医师', '体检中心'),
        array('3001', 'zhoumei', '周梅', 'nurse', '主管护师', ''),
        array('4001', 'chenjing', '陈静', 'lab', '主管技师', ''),
        array('5001', 'huanghao', '黄浩', 'imaging', '主管技师', ''),
        array('6001', 'wutao', '吴涛', 'pharmacy', '主管药师', ''),
    );

    /** @var array 专科 → 出诊科室（clinic 本科门诊 / emergency 对应急诊 / expert 专家门诊 / extra 兼看门诊） */
    protected $specialties = array(
        '内科'     => array('clinic' => '内科门诊',   'emergency' => '急诊内科', 'expert' => '内科专家门诊'),
        '外科'     => array('clinic' => '外科门诊',   'emergency' => '急诊外科', 'expert' => '外科专家门诊'),
        '骨科'     => array('clinic' => '骨科门诊',   'emergency' => '急诊外科', 'expert' => '骨科专家门诊', 'extra' => '外科门诊'),
        '儿科'     => array('clinic' => '儿科门诊',   'emergency' => '',        'expert' => '儿科专家门诊'),
        '妇产科'   => array('clinic' => '妇产科门诊', 'emergency' => '',        'expert' => '妇产科专家门诊'),
        '方便门诊' => array('clinic' => '方便门诊',   'emergency' => '',        'expert' => ''),
        '体检中心' => array('clinic' => '体检中心',   'emergency' => '',        'expert' => ''),
    );

    /** @var array 专家门诊资格职称（副主任医师及以上） */
    protected $seniorTitles = array('副主任医师', '主任医师');

    /**
     * 专科 + 职称 → 出诊科室 id CSV。
     * 科室按名称解析（兼容库中 id 差异），未建成的科室名自动跳过。
     * @param string $specialty 专科
     * @param string $title     职称
     * @param array  $deptIdsByName 科室名称 → id
     * @return string
     */
    protected function deptIdsFor($specialty, $title, $deptIdsByName) {
        if (!isset($this->specialties[$specialty])) return '';
        $s = $this->specialties[$specialty];
        $names = array($s['clinic']);
        if ($s['emergency'] !== '') $names[] = $s['emergency'];
        if ($s['expert'] !== '' && in_array($title, $this->seniorTitles, true)) $names[] = $s['expert'];
        if (!empty($s['extra'])) $names[] = $s['extra'];
        // 绿色通道随急诊出诊权限同步开通（方便门诊/体检中心等无急诊权限者不开通）
        if ($s['emergency'] !== '') $names[] = '绿色通道';
        $ids = array();
        foreach ($names as $n) {
            if (isset($deptIdsByName[$n]) && !in_array($deptIdsByName[$n], $ids, true)) $ids[] = $deptIdsByName[$n];
        }
        return implode(',', $ids);
    }

    public function run() {
        $pdo = $this->pdo;
        // 科室名称 → id（医生出诊权限按名称解析，不依赖固定 id）
        $deptIdsByName = array();
        foreach ($pdo->query('SELECT id, name FROM departments WHERE status=1') as $r) {
            $deptIdsByName[(string)$r['name']] = (int)$r['id'];
        }
        $pwdHash = password_hash('123456', PASSWORD_DEFAULT);
        $created = 0; $updated = 0;
        $stmt = $pdo->prepare('SELECT id, role FROM users WHERE emp_no=?');
        $ins = $pdo->prepare('INSERT INTO users(emp_no, username, password, name, role, dept_ids, title, theme, status, pwd_changed, created_at) VALUES(?,?,?,?,?,?,?,\'auto\',1,0,?)');
        $upd = $pdo->prepare('UPDATE users SET username=?, name=? WHERE id=?');
        // 医生账号：出诊权限（dept_ids）与职称随专科模型同步刷新
        $updDoctor = $pdo->prepare('UPDATE users SET username=?, name=?, dept_ids=?, title=? WHERE id=?');
        foreach ($this->defs as $u) {
            $deptIds = ($u[3] === 'doctor') ? $this->deptIdsFor($u[5], $u[4], $deptIdsByName) : '';
            $stmt->execute(array($u[0]));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $uid = (int)$row['id'];
                if ((string)$row['role'] === 'doctor') {
                    $updDoctor->execute(array($u[1], $u[2], $deptIds, $u[4], $uid));
                } else {
                    $upd->execute(array($u[1], $u[2], $uid));
                }
                $updated++;
            } else {
                $ins->execute(array($u[0], $u[1], $pwdHash, $u[2], $u[3], $deptIds, $u[4], now_str()));
                $created++;
            }
        }
        $this->out('新增 ' . $created . ' / 同步 ' . $updated . ' 个账号（密码均为 123456；医生出诊权限按专科+职称自动派生）');
        return $created;
    }
}

$s = new UserSeeder();
$s->run();
