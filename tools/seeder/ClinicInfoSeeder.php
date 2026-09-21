<?php
/**
 * ============================================================
 * tools/seeder/ClinicInfoSeeder.php — 机构信息生成器
 * ============================================================
 * 写入系统级机构元数据（配合系统设置）：医院主名称（必填）、
 * 必填机构代码、第二名称、机构简介。
 * 执行：php tools/bin/seed.php --module=clinic
 * ============================================================ */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}
if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/../app/config/bootstrap.php';
}
DatabaseManager::initAll();
require __DIR__ . '/Seeder.php';

class ClinicInfoSeeder extends Seeder {

    protected $label = '机构信息';

    public function run() {
        // 机构信息默认值（已安装系统若已有医院名称则保留，仅补全缺失的必填机构代码/简介）
        $hosp = setting('hospital_name', '');
        $org  = setting('org_code', '');
        if ($hosp === '') set_setting('hospital_name', '高新区便民门诊部');
        if ($org === '') {
            // 示例机构代码（医疗机构代码，医保结算/监管报送唯一标识）
            set_setting('org_code', '410105001234');
        }
        if (setting('hospital_sub_name', '') === '') set_setting('hospital_sub_name', '');
        if (setting('hospital_intro', '') === '') {
            set_setting('hospital_intro', '本机构为综合门诊部，提供内科、外科、儿科、妇产科等全科诊疗、检验检查与健康管理服务。');
        }
        $this->out('医院名称=' . setting('hospital_name'));
        $this->out('机构代码=' . setting('org_code'));
        $this->out('机构简介=' . mb_substr(setting('hospital_intro'), 0, 20) . '…');
        return 1;
    }
}

$s = new ClinicInfoSeeder();
$s->run();