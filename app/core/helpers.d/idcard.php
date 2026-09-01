<?php
/**
 * ============================================================
 * helpers.d/idcard.php — 身份证校验 / 年龄计算
 * ============================================================
 * 说明：18 位身份证 + 校验码算法 + 出生日期合法性检查、年龄计算与
 * 全年龄段医疗格式化年龄（EMR 规范）。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

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
 * @param string|int $target  计算目标时间（默认当前；可传就诊时间如 registered_at）
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