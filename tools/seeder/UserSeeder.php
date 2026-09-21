<?php
/**
 * ============================================================
 * tools/seeder/UserSeeder.php — 用户账号生成器
 * ============================================================
 * 测试账号：管理员 / 收费员 / 医生×8 / 护士 / 检验 / 影像 / 药房，
 * 初始密码均为 123456（仅本地/测试环境使用）。
 * 已存在工号仅同步 username（拼音）/name（中文），保留密码/角色/科室/职称。
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

    /** @var array [emp_no, username(姓名拼音), name(中文), role, dept_ids, title] */
    protected $defs = [
        ['0001', 'admin', '系统管理员', 'admin', '', ''],
        ['1001', 'shoukuanyuan', '收款员', 'cashier', '', ''],
        ['2001', 'zhangwei', '张伟', 'doctor', '2,5', '主治医师'],
        ['2002', 'lina', '李娜', 'doctor', '1,3', '主治医师'],
        ['2003', 'wangqiang', '王强', 'doctor', '2,4', '副主任医师'],
        ['2004', 'zhaomin', '赵敏', 'doctor', '1,5', '主任医师'],
        ['2005', 'liuyang', '刘洋', 'doctor', '3,4', '主治医师'],
        ['2006', 'qianfeng', '钱峰', 'doctor', '1,2,5', '主任医师'],
        ['2007', 'sunli', '孙丽', 'doctor', '3,6', '副主任医师'],
        ['2008', 'machao', '马超', 'doctor', '4,5,6', '主治医师'],
        ['3001', 'zhoumei', '周梅', 'nurse', '', '主管护师'],
        ['4001', 'chenjing', '陈静', 'lab', '', '主管技师'],
        ['5001', 'huanghao', '黄浩', 'imaging', '', '主管技师'],
        ['6001', 'wutao', '吴涛', 'pharmacy', '', '主管药师'],
    ];

    public function run() {
        $pdo = $this->pdo;
        $pwdHash = password_hash('123456', PASSWORD_DEFAULT);
        $created = 0; $updated = 0;
        $stmt = $pdo->prepare('SELECT id FROM users WHERE emp_no=?');
        $ins = $pdo->prepare('INSERT INTO users(emp_no, username, password, name, role, dept_ids, title, theme, status, pwd_changed, created_at) VALUES(?,?,?,?,?,?,?,\'auto\',1,0,?)');
        $upd = $pdo->prepare('UPDATE users SET username=?, name=? WHERE id=?');
        foreach ($this->defs as $u) {
            $stmt->execute(array($u[0]));
            $uid = $stmt->fetchColumn();
            if ($uid) {
                $upd->execute(array($u[1], $u[2], (int)$uid));
                $updated++;
            } else {
                $ins->execute(array($u[0], $u[1], $pwdHash, $u[2], $u[3], $u[4], $u[5], now_str()));
                $created++;
            }
        }
        $this->out('新增 ' . $created . ' / 同步 ' . $updated . ' 个账号（密码均为 123456，用户名=姓名拼音缩写）');
        return $created;
    }
}

$s = new UserSeeder();
$s->run();
