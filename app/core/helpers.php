<?php
/**
 * ============================================================
 * helpers.php v1.0.0 — 全局辅助函数
 * ============================================================
 * 说明：输出转义、JSON 统一响应、参数获取、身份证校验、
 * 年龄计算、拼音首字母（诊断检索）、系统设置读写等。
 * ============================================================ */

/* ============================================================
 * mbstring 扩展缺失时的兼容函数（仅在未加载时生效）
 * 说明：部分 PHP 7.x 环境（如精简镜像）未启用 mbstring 扩展，
 * 会导致 mb_strlen / mb_substr 报致命错误。这里提供基于
 * preg 的 UTF-8 兼容实现，保证系统在无 mbstring 时也能运行。
 * ============================================================ */
if (!function_exists('mb_strlen')) {
    /** UTF-8 安全的字符串长度（mbstring 缺失时使用） */
    function mb_strlen($str, $encoding = null) {
        if (preg_match_all('/./us', (string)$str, $m) > 0) {
            return count($m[0]);
        }
        return 0;
    }
}

if (!function_exists('mb_substr')) {
    /** UTF-8 安全的子串截取（mbstring 缺失时使用） */
    function mb_substr($str, $start, $length = null, $encoding = null) {
        $str = (string)$str;
        if (!preg_match_all('/./us', $str, $m)) {
            return '';
        }
        $chars = $m[0];
        $total = count($chars);
        // 负数 start 从末尾计算
        if ($start < 0) {
            $start = max(0, $total + $start);
        }
        if ($length === null || $length < 0) {
            return implode('', array_slice($chars, $start));
        }
        return implode('', array_slice($chars, $start, $length));
    }
}

/** HTML 输出转义（防止 XSS，所有动态内容输出前必须经过 e()） */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
 * 上传文件引用（uploads/ 相对路径的安全输出）
 * 说明：Upload::save 返回相对 public 的路径（如 uploads/logo/x.png）。
 * 若直接输出到 src/href，浏览器按当前页面路径解析：
 * /login 页解析为 /uploads/... 正常，而 /admin/dashboard 页会解析成
 * /admin/uploads/... 导致 404。统一经以下两个函数输出：
 * ============================================================ */

/**
 * 上传文件绝对 URL：补全根斜杠（uploads/... → /uploads/...）
 * 适用：用户头像等允许直接以 URL 引用的图片
 */
function upload_url($path) {
    $path = ltrim((string)$path, '/');
    return $path === '' ? '' : '/' . $path;
}

/**
 * 图片转 base64 Data URI 内联显示（不暴露文件 URL）
 * 适用：医院 LOGO 等需要隐藏真实路径的图片；favicon 同样适用。
 * 安全：realpath 规范化后必须仍位于 public 目录内（防目录穿越）；
 * 文件不存在/非有效图片时返回 ''，调用方据此决定是否渲染 <img>。
 */
function img_data($path) {
    $path = ltrim((string)$path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    $base = realpath(APP_ROOT . '/public');
    $file = realpath(APP_ROOT . '/public/' . $path);
    if (!$base || !$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    $info = @getimagesize($file);
    if (!$info || empty($info['mime'])) {
        return '';
    }
    $bin = @file_get_contents($file);
    if ($bin === false || strlen($bin) > 2097152) {
        return '';
    }
    return 'data:' . $info['mime'] . ';base64,' . base64_encode($bin);
}

/**
 * 统一 JSON 响应格式：{ ok, msg, data }
 * @param bool   $ok   是否成功
 * @param string $msg  提示信息
 * @param mixed  $data 业务数据
 */
function json_response($ok, $msg = '', $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'   => (bool)$ok,
        'msg'  => (string)$msg,
        'data' => $data,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/** 成功响应快捷方式 */
function json_ok($data = array(), $msg = '操作成功') {
    json_response(true, $msg, $data);
}

/** 失败响应快捷方式 */
function json_fail($msg) {
    json_response(false, $msg);
}

/** 读取 POST 参数（自动去首尾空格） */
function post($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/**
 * 读取 POST 原始值（不去除首尾空格）
 * 说明：密码等敏感输入必须原样读取，禁止 trim：
 * 用户输入的密码可能含前导/尾随空格（复制粘贴或误输入），
 * 一旦 trim 会导致长度校验误判（如实际输入9位被判定少于6位），
 * 且入库/校验的密码与用户实际输入不一致，造成无法登录。
 */
function post_raw($key, $default = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : $default;
}

/** 读取 GET 参数（自动去首尾空格） */
function get($key, $default = '') {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/**
 * 读取 GET 或 POST 参数（兼容两种请求方式，自动去首尾空格）
 * 说明：表单弹窗（loadModal / Clinic.modal.load）统一通过 POST 提交，
 * 而部分老接口用 get() 读参数导致编辑弹窗永远拿到 id=0（空白表单）。
 * form 类接口一律改用本函数读取，GET / POST 均兼容。
 */
function req($key, $default = '') {
    return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : $default;
}

/** 当前时间字符串（站点时区） */
function now_str($fmt = 'Y-m-d H:i:s') {
    return date($fmt);
}

/** 当前日期字符串 */
function today_str() {
    return date('Y-m-d');
}

/** 金额格式化：保留两位小数 */
function money($n) {
    return number_format((float)$n, 2, '.', '');
}

/* ============================================================
 * 身份证号码校验
 * 说明：18 位身份证 + 校验码算法 + 出生日期合法性检查。
 * 挂号时必须通过本校验才允许以身份证方式挂号。
 * ============================================================ */
function idcard_valid($id) {
    $id = strtoupper(trim((string)$id));
    if (!preg_match('/^\d{17}[\dX]$/', $id)) {
        return false;
    }
    // 出生日期合法性
    $y = (int)substr($id, 6, 4);
    $m = (int)substr($id, 10, 2);
    $d = (int)substr($id, 12, 2);
    if ($y < 1900 || $y > (int)date('Y')) return false;
    if (!checkdate($m, $d, $y)) return false;
    if (substr($id, 6, 8) > date('Ymd')) return false; // 不能是未来日期
    // 校验码
    $w  = array(7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2);
    $chk = '10X98765432';
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
        $sum += (int)$id[$i] * $w[$i];
    }
    return $chk[$sum % 11] === $id[17];
}

/**
 * 从身份证提取 出生日期/年龄/性别（挂号时自动计算并锁定）
 * @return array ['birth'=>'Y-m-d','age'=>int,'gender'=>'男'|'女']
 */
function idcard_info($id) {
    $id = strtoupper(trim((string)$id));
    $birth = substr($id, 6, 4) . '-' . substr($id, 10, 2) . '-' . substr($id, 12, 2);
    $gender = ((int)substr($id, 16, 1)) % 2 === 1 ? '男' : '女';
    return array('birth' => $birth, 'age' => calc_age($birth), 'gender' => $gender);
}

/** 根据出生日期计算周岁年龄 */
function calc_age($birth) {
    if (!$birth) return 0;
    $b = explode('-', $birth);
    if (count($b) < 3) return 0;
    $by = (int)$b[0]; $bm = (int)$b[1]; $bd = (int)$b[2];
    $age = (int)date('Y') - $by;
    if ((int)date('m') < $bm || ((int)date('m') === $bm && (int)date('d') < $bd)) {
        $age--;
    }
    return $age < 0 ? 0 : $age;
}

/**
 * 全年龄段医疗格式化年龄（EMR 规范），系统内所有年龄展示统一使用本函数：
 *   出生 < 24小时   → X小时 / X小时Y分（不足1小时显示 Y分）
 *   1 ~ 28 天       → X天（新生儿期，不按周换算）
 *   29天 ~ <12个月  → X月 / X月Y天（天数为0只显示X月；未满1月按天显示）
 *   1 ~ 5 岁        → X岁Y月（月数为0只显示X岁）
 *   ≥ 6 岁          → X岁
 * 约束：不使用周/星期；严禁浮点数；日历精确计算（自动处理大小月/平闰年）；
 *       目标时间早于出生时间或无法解析时返回 ''（异常防御）。
 * @param string|int $birth   出生日期/时间（'Y-m-d'、'Y-m-d H:i:s' 或 Unix 时间戳）
 * @param string|int $target  计算目标时间（默认当前；可传就诊时间如 register_time）
 * @return string
 */
function age_format($birth, $target = null) {
    if ($birth === '' || $birth === null) return '';
    $b = date_create(is_numeric($birth) ? '@' . $birth : (string)$birth);
    $t = $target === null || $target === ''
        ? date_create()
        : date_create(is_numeric($target) ? '@' . $target : (string)$target);
    if (!$b || !$t || $t < $b) return '';
    $secs = $t->getTimestamp() - $b->getTimestamp();
    if ($secs < 86400) {
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        if ($h > 0) return $m > 0 ? $h . '小时' . $m . '分' : $h . '小时';
        return $m . '分';
    }
    $iv = date_diff($b, $t);
    $days = (int)$iv->format('%a'); // 总天数
    $monthsTotal = (int)$iv->y * 12 + (int)$iv->m;
    if ($days <= 28) return $days . '天';
    if ($monthsTotal < 12) {
        if ($monthsTotal < 1) return $days . '天';
        return (int)$iv->d > 0 ? $monthsTotal . '月' . $iv->d . '天' : $monthsTotal . '月';
    }
    if ((int)$iv->y < 6) {
        return (int)$iv->m > 0 ? $iv->y . '岁' . $iv->m . '月' : $iv->y . '岁';
    }
    return $iv->y . '岁';
}

/* ============================================================
 * 拼音首字母（用于 ICD 诊断拼音检索）
 * 说明：内置常见医学/常用字映射；命中率之外的字符忽略。
 * 种子 ICD 数据自带 pinyin 字段，新建诊断可调用本函数生成。
 * ============================================================ */
function pinyin_initial($str) {
    static $map = null;
    if ($map === null) {
        $map = array(
            '上'=>'S','呼'=>'H','吸'=>'X','道'=>'D','感'=>'G','染'=>'R','肺'=>'F','炎'=>'Y','支'=>'Z','气'=>'Q','管'=>'G',
            '高'=>'G','血'=>'X','压'=>'Y','糖'=>'N','尿'=>'U','病'=>'B','心'=>'X','脏'=>'Z','梗'=>'G','死'=>'S','痛'=>'T',
            '冠'=>'G','状'=>'Z','动'=>'D','脉'=>'M','粥'=>'Z','样'=>'Y','硬'=>'Y','化'=>'H','衰'=>'S','竭'=>'J','律'=>'L',
            '失'=>'S','常'=>'C','早'=>'Z','搏'=>'B','房'=>'F','颤'=>'C','室'=>'S','速'=>'Q','过'=>'G','缓'=>'H','停'=>'T',
            '脑'=>'N','中'=>'Z','风'=>'F','出'=>'C','血'=>'X','栓'=>'S','塞'=>'S','偏'=>'P','瘫'=>'T','瘤'=>'L','癌'=>'A',
            '肝'=>'G','肾'=>'S','胃'=>'W','肠'=>'C','结'=>'J','直'=>'Z','十'=>'S','二'=>'E','指'=>'Z','溃'=>'K','疡'=>'Y',
            '胆'=>'D','囊'=>'N','结'=>'J','石'=>'S','胰'=>'Y','腺'=>'X','甲'=>'J','状'=>'Z','亢'=>'K','减'=>'J','退'=>'T',
            '贫'=>'P','白'=>'B','细'=>'X','胞'=>'B','减'=>'J','少'=>'S','多'=>'D','紫'=>'Z','癜'=>'D','过'=>'G','敏'=>'M',
            '哮'=>'X','喘'=>'C','鼻'=>'B','窦'=>'D','咽'=>'Y','喉'=>'H','扁'=>'B','桃'=>'T','体'=>'T','中'=>'Z','耳'=>'E',
            '骨'=>'G','折'=>'Z','关'=>'G','节'=>'J','椎'=>'Z','间'=>'J','盘'=>'P','突'=>'T','出'=>'C','腰'=>'Y','颈'=>'J',
            '椎'=>'Z','膝'=>'X','肩'=>'J','踝'=>'H','腕'=>'W','骨'=>'G','质'=>'Z','疏'=>'S','松'=>'S','风'=>'F','湿'=>'S',
            '类'=>'L','关'=>'G','节'=>'J','炎'=>'Y','痛'=>'T','风'=>'F','尿'=>'N','酸'=>'S','结'=>'J','晶'=>'J','体'=>'T',
            '白'=>'B','内'=>'N','障'=>'Z','青'=>'Q','光'=>'G','视'=>'S','网'=>'W','膜'=>'M','脱'=>'T','离'=>'L','皮'=>'P',
            '肤'=>'F','湿'=>'S','疹'=>'Z','荨'=>'Q','麻'=>'M','癣'=>'X','疱'=>'P','疮'=>'C','溃'=>'K','疡'=>'Y','疖'=>'J',
            '肿'=>'Z','瘤'=>'L','结'=>'J','核'=>'H','艾'=>'A','滋'=>'Z','病'=>'B','狂'=>'K','犬'=>'Q','伤'=>'S','破'=>'P',
            '伤'=>'S','风'=>'F','流'=>'L','行'=>'X','性'=>'X','感'=>'G','冒'=>'M','发'=>'F','热'=>'R','咳'=>'K','嗽'=>'S',
            '痰'=>'T','呕'=>'O','吐'=>'T','腹'=>'F','泻'=>'X','便'=>'B','秘'=>'M','尿'=>'N','频'=>'P','急'=>'J','淋'=>'L',
            '巴'=>'B','炎'=>'Y','贫'=>'P','缺'=>'Q','铁'=>'T','维'=>'W','生'=>'S','素'=>'S','缺'=>'Q','乏'=>'F','钙'=>'G',
            '锌'=>'X','碘'=>'D','肥'=>'F','胖'=>'P','营'=>'Y','养'=>'Y','不'=>'B','良'=>'L','食'=>'S','欲'=>'Y','不'=>'B',
            '振'=>'Z','失'=>'S','眠'=>'M','焦'=>'J','虑'=>'L','抑'=>'Y','郁'=>'Y','精'=>'J','神'=>'S','分'=>'F','裂'=>'L',
            '痴'=>'C','呆'=>'D','帕'=>'P','金'=>'J','森'=>'S','癫'=>'D','痫'=>'X','头'=>'T','晕'=>'Y','眩'=>'X','耳'=>'E',
            '鸣'=>'M','聋'=>'L','鼻'=>'B','塞'=>'S','流'=>'L','涕'=>'T','打'=>'D','喷'=>'P','嚏'=>'T','牙'=>'Y','龋'=>'Q',
            '齿'=>'C','龈'=>'Y','口'=>'K','腔'=>'Q','溃'=>'K','疡'=>'Y','舌'=>'S','扁'=>'B','咽'=>'Y','喉'=>'H','声'=>'S',
            '音'=>'Y','嘶'=>'S','哑'=>'Y','眼'=>'Y','睛'=>'J','角'=>'J','膜'=>'M','结'=>'J','膜'=>'M','巩'=>'G','瞳'=>'T',
            '孔'=>'K','视'=>'S','力'=>'L','模'=>'M','糊'=>'H','斜'=>'X','视'=>'S','弱'=>'R','视'=>'S','近'=>'J','视'=>'S',
            '远'=>'Y','视'=>'S','散'=>'S','光'=>'G','妇'=>'F','科'=>'K','孕'=>'Y','产'=>'C','宫'=>'G','颈'=>'J','卵'=>'L',
            '巢'=>'C','囊'=>'N','肿'=>'Z','月'=>'Y','经'=>'J','不'=>'B','调'=>'T','痛'=>'T','经'=>'J','盆'=>'P','腔'=>'Q',
            '炎'=>'Y','阴'=>'Y','道'=>'D','感'=>'G','染'=>'R','乳'=>'R','腺'=>'X','增'=>'Z','生'=>'S','前'=>'Q','列'=>'L',
            '腺'=>'X','增'=>'Z','生'=>'S','睾'=>'G','丸'=>'W','附'=>'F','睾'=>'G','炎'=>'Y','急'=>'J','性'=>'X','慢'=>'M',
            '性'=>'X','传'=>'C','染'=>'R','病'=>'B','菌'=>'J','毒'=>'D','寄'=>'J','生'=>'S','虫'=>'C','原'=>'Y','虫'=>'C',
            '感'=>'G','染'=>'R','败'=>'B','症'=>'Z','脓'=>'N','毒'=>'D','症'=>'Z','休'=>'X','克'=>'K','昏'=>'H','迷'=>'M',
            '窒'=>'Z','息'=>'X','心'=>'X','搏'=>'B','骤'=>'Z','停'=>'T','电'=>'D','击'=>'J','溺'=>'N','水'=>'S','烧'=>'S',
            '伤'=>'S','烫'=>'T','伤'=>'S','刀'=>'D','割'=>'G','伤'=>'S','骨'=>'G','盆'=>'P','骨'=>'G','折'=>'Z','肋'=>'L',
            '骨'=>'G','骨'=>'G','折'=>'Z','锁'=>'S','骨'=>'G','骨'=>'G','折'=>'Z','股'=>'G','骨'=>'G','颈'=>'J','骨'=>'G','折'=>'Z',
            '急'=>'J','诊'=>'Z','门'=>'M','诊'=>'Z','住'=>'Z','院'=>'Y','复'=>'F','诊'=>'Z','初'=>'C','诊'=>'Z','随'=>'S','访'=>'F',
        );
    }
    $out = '';
    $len = mb_strlen($str, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($str, $i, 1, 'UTF-8');
        if (isset($map[$ch])) {
            $out .= $map[$ch];
        }
    }
    return $out;
}

/* ============================================================
 * 系统设置读写（core 库 settings 表）
 * ============================================================ */
function setting($key, $default = '') {
    $v = DB::val('core', 'SELECT svalue FROM settings WHERE skey=?', array($key));
    return $v === null ? $default : $v;
}

function set_setting($key, $value) {
    DB::exec('core', 'INSERT OR REPLACE INTO settings(skey, svalue) VALUES(?, ?)', array($key, (string)$value));
}

/* ============================================================
 * 医院作息时间（影响门诊号源开放时段；急诊 24 小时不受限）
 * 说明：
 * 1. 常规作息四要素存于 settings（work_am_start 等，HH:MM）
 * 2. 夏令时（此处指医院夏/冬季作息切换，非系统时区夏令时）：
 *    开启后可设置生效日期范围（MM-DD，支持跨年如 11-01~03-31）
 *    与夏令时作息四要素；命中日期范围时系统自动改用夏令时作息
 * ============================================================ */

/** 读取当前生效的作息（含夏令时判断），返回 HH:MM 四要素与 is_dst 标记 */
function work_schedule() {
    $w = array(
        'am_start' => setting('work_am_start', '08:00'),
        'am_end'   => setting('work_am_end', '12:00'),
        'pm_start' => setting('work_pm_start', '14:00'),
        'pm_end'   => setting('work_pm_end', '17:30'),
        'dst_enabled' => setting('dst_enabled', '0'),
        'dst_start'   => setting('dst_start', ''),
        'dst_end'     => setting('dst_end', ''),
        'dst_am_start' => setting('dst_am_start', ''),
        'dst_am_end'   => setting('dst_am_end', ''),
        'dst_pm_start' => setting('dst_pm_start', ''),
        'dst_pm_end'   => setting('dst_pm_end', ''),
    );
    $w['is_dst'] = '0';
    if ($w['dst_enabled'] === '1' && $w['dst_start'] !== '' && $w['dst_end'] !== '') {
        // 取 MM-DD 部分（兼容误存 YYYY-MM-DD）；跨年区间（起始>结束）任一命中即视为在范围内
        $now = date('m-d');
        $a = substr($w['dst_start'], -5);
        $b = substr($w['dst_end'], -5);
        $in = ($a <= $b) ? ($now >= $a && $now <= $b) : ($now >= $a || $now <= $b);
        if ($in) {
            $w['is_dst'] = '1';
            foreach (array('am_start', 'am_end', 'pm_start', 'pm_end') as $k) {
                if ($w['dst_' . $k] !== '') $w[$k] = $w['dst_' . $k];
            }
        }
    }
    return $w;
}

/**
 * 当前挂号时段状态（按生效作息判定）：
 *   before 未上班 / am 上午可挂 / noon 午休 / pm 下午可挂 / after 已下班
 */
function work_session_now() {
    $w = work_schedule();
    $t = date('H:i');
    if ($t < $w['am_start']) return 'before';
    if ($t <= $w['am_end']) return 'am';
    if ($t < $w['pm_start']) return 'noon';
    if ($t <= $w['pm_end']) return 'pm';
    return 'after';
}

/** 当前时段的提示文案（供接口与页面复用） */
function work_status_msg($state = null) {
    $w = work_schedule();
    if ($state === null) $state = work_session_now();
    switch ($state) {
        case 'before': return '今日挂号尚未开始，上午 ' . $w['am_start'] . ' 开始放号';
        case 'noon':   return '午休中：上午号源已截止，下午 ' . $w['pm_start'] . ' 开始放号';
        case 'after':  return '今日已下班，门诊挂号已结束（急诊 24 小时可挂）';
        default:       return '';
    }
}

/* ============================================================
 * 业务实体 ID 混淆快捷函数（全站统一入口，详见 core/IdObfuscator.php）
 * ------------------------------------------------------------
 * oid($id)  输出侧：整数 ID → URL 安全混淆串（HTML/JSON/链接拼接用）
 * did($code) 输入侧：混淆串 → 整数（GET/POST 接收；失败返回 0，
 *            调用方按「记录不存在」处理，严禁回退接受明文数字）
 * 适用：visit_id / order_id(s) / item_id(明细) / report_id /
 *       payment_id / ref 等患者级实体；dept_id 与管理端字典 id 不适用。
 * ============================================================ */
function oid($id) {
    return IdObfuscator::encode($id);
}

function did($code) {
    return IdObfuscator::decode($code);
}

/** 批量解码（逗号分隔混淆串 → 整数数组；任一失败返回空数组） */
function did_list($codes) {
    return IdObfuscator::decodeList($codes);
}

/* ============================================================
 * 业务辅助：就诊记录/患者档案联查、状态中文名
 * ============================================================ */

/** 按就诊ID联查 挂号记录 + 患者档案 */
function get_visit_row($visitId) {
    $v = DB::one('patient', 'SELECT * FROM registrations WHERE id=?', array((int)$visitId));
    if (!$v) {
        return null;
    }
    $p = DB::one('patient', 'SELECT * FROM patients WHERE patient_no=?', array($v['patient_no']));
    return array('visit' => $v, 'patient' => $p);
}

/** 挂号状态中文名 */
function visit_status_name($s) {
    $map = array(
        'pending'   => '待缴费',
        'paid'      => '待就诊',
        'visiting'  => '就诊中',
        'finished'  => '就诊完毕',
        'refunded'  => '已退费',
        'cancelled' => '已取消',
    );
    return isset($map[$s]) ? $map[$s] : $s;
}

/** 开单明细流程状态中文名 */
function item_status_name($s) {
    $map = array(
        'open'       => '待缴费',
        'paid'       => '已缴费',
        'registered' => '已登记',
        'executing'  => '执行中',
        'done'       => '已完成',
        'dispensing' => '发药中',
        'dispensed'  => '已发药',
        'refunded'   => '已退费',
        'cancelled'  => '已取消',
    );
    return isset($map[$s]) ? $map[$s] : $s;
}

/** 计算订单聚合状态（open/paid/registered/in_progress/done/dispensed/refunded/cancelled） */
function order_agg_status($orderType, $items) {
    $sts = array();
    foreach ($items as $it) $sts[] = $it['status'];
    if (!$sts) return 'open';
    if (count(array_unique($sts)) === 1) {
        $only = $sts[0];
        if ($only === 'refunded') return 'refunded';
        if ($only === 'cancelled') return 'cancelled';
        if ($only === 'dispensed') return 'dispensed';
        if ($only === 'done') return 'done';
    }
    if (in_array('open', $sts, true)) return 'open';
    if (in_array('paid', $sts, true)) return 'paid';
    if ($orderType === 'prescription') {
        if (in_array('dispensed', $sts, true)) return 'dispensed';
        return 'paid';
    }
    if (in_array('executing', $sts, true)) return 'in_progress';
    if (in_array('registered', $sts, true)) return 'registered';
    if (in_array('done', $sts, true)) return 'done';
    return 'open';
}

/** 生成站内消息（通知方式：纯站内消息 + 打印提醒） */
function send_msg($toRole, $toUserId, $title, $content = '', $printType = '', $printUrl = '', $extra = array()) {
    // $extra：可选扩展字段
    //   msg_type     'patient' 患者消息 / 'system' 系统消息（默认）
    //   patient_name 患者姓名（患者消息时显示）
    //   visit_id     关联就诊ID（点击可跳转到该次病历）
    //   link_url     自定义跳转链接（如审核驳回后跳回添加页回填重提）
    $extra = is_array($extra) ? $extra : array();
    DB::insert('core', 'INSERT INTO messages(from_name, from_user_id, to_role, to_user_id, title, content, print_type, print_url, is_read, msg_type, patient_name, visit_id, link_url, created_at) VALUES(?,?,?,?,?,?,?,?,0,?,?,?,?,?)', array(
        isset($_SESSION['auth_user']['name']) ? $_SESSION['auth_user']['name'] : '系统',
        isset($_SESSION['auth_user']['id']) ? (int)$_SESSION['auth_user']['id'] : 0,
        $toRole, (int)$toUserId, $title, $content, $printType, $printUrl,
        isset($extra['msg_type']) ? $extra['msg_type'] : 'system',
        isset($extra['patient_name']) ? $extra['patient_name'] : '',
        isset($extra['visit_id']) ? (int)$extra['visit_id'] : 0,
        isset($extra['link_url']) ? $extra['link_url'] : '',
        now_str(),
    ));
}
