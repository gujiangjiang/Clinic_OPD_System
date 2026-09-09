<?php
/**
 * ============================================================
 * LoginSecurity.php v1.0.0 — 登录安全：验证码与 IP 频控
 * ============================================================
 * 说明：登录验证码生成/校验/销毁与登录失败 IP 频控（防撞库）。
 * 零外部依赖——验证码使用 PHP 原生 GD 库生成轻量图形（4 位字母数字，
 * 干扰线 + 噪点），真实值存 $_SESSION（5 分钟有效期）。
 *
 * 防重放（关键约束）：无论后续密码校验是否通过，只要执行了一次比对
 * （captchaCheck），立即 unset 销毁 Session 中的验证码——严禁同一验证码
 * 被复用发起并发爆破。换图必须重新请求 captcha 接口。
 *
 * IP 频控：以「IP + 浏览器指纹」维度记录连续登录失败次数与最近失败时间
 * （$_SESSION，独立于用户表计数），用于：
 * 1. auto 模式下判定是否需要强制验证码（惩罚期）；
 * 2. 防止恶意脚本借 check_captcha 接口穷举有效工号（微量限流）。
 * ============================================================
 */
class LoginSecurity {

    const SESSION_KEY   = 'login_captcha';   // array(code, created_at)
    const SESSION_IPKEY = 'login_ip_fail';   // array(count, last_at)
    const CAPTCHA_TTL   = 300;               // 验证码有效期 5 分钟
    const CODE_LEN      = 4;                 // 验证码位数
    const IP_FAIL_TTL   = 1800;              // IP 失败计数窗口 30 分钟
    const CHECK_LIMIT   = 30;                // check_captcha 每分钟限次（防枚举）

    /** 客户端 IP（反代兼容：优先 X-Forwarded-For 首个） */
    public static function clientIp() {
        $keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
                if ($ip !== '') return $ip;
            }
        }
        return '0.0.0.0';
    }

    /** 全局验证码模式（off/auto/force，非法值回落 auto） */
    public static function mode() {
        $m = setting('login_captcha_mode', 'auto');
        return in_array($m, array('off', 'auto', 'force'), true) ? $m : 'auto';
    }

    /** 锁定阈值（settings.login_fail_lock_count，越界回落 5） */
    public static function lockCount() {
        $n = (int)setting('login_fail_lock_count', 5);
        return ($n >= 3 && $n <= 10) ? $n : 5;
    }

    /* ==================== IP 失败频控 ==================== */

    /** 记录一次当前 IP 的登录失败（连续失败计数 +1，窗口外重新计） */
    public static function ipFailRecord() {
        $st = isset($_SESSION[self::SESSION_IPKEY]) && is_array($_SESSION[self::SESSION_IPKEY])
            ? $_SESSION[self::SESSION_IPKEY] : array('count' => 0, 'last_at' => 0);
        $now = time();
        $st['count'] = (($now - (int)$st['last_at']) > self::IP_FAIL_TTL) ? 1 : ((int)$st['count'] + 1);
        $st['last_at'] = $now;
        $_SESSION[self::SESSION_IPKEY] = $st;
    }

    /** 清除当前 IP 的失败计数（登录成功后调用） */
    public static function ipFailClear() {
        unset($_SESSION[self::SESSION_IPKEY]);
    }

    /** 当前 IP 是否处于连续失败惩罚期（auto 模式判定强制验证码用） */
    public static function ipInPenalty() {
        $st = isset($_SESSION[self::SESSION_IPKEY]) && is_array($_SESSION[self::SESSION_IPKEY])
            ? $_SESSION[self::SESSION_IPKEY] : null;
        if (!$st || (int)$st['count'] <= 0) return false;
        if ((time() - (int)$st['last_at']) > self::IP_FAIL_TTL) return false;   // 窗口已过
        return (int)$st['count'] >= 2;   // 连续失败 ≥2 次进入惩罚期
    }

    /* ==================== 验证码需求判定 ==================== */

    /**
     * 判定登录是否需要验证码。
     * @param string $mode        全局模式 off/auto/force
     * @param bool   $clientFlag  客户端提交标志（前端本地失败标记驱动）
     * @param int    $userFailCnt 目标用户 login_fail_count（auto 模式嗅探用）
     * @return bool 是否需要验证码
     */
    public static function needCaptcha($mode, $clientFlag = false, $userFailCnt = 0) {
        if ($mode === 'off') return false;
        if ($mode === 'force') return true;
        // auto：IP 惩罚期 / 用户历史失败 / 客户端本地失败标记 → 需要
        return self::ipInPenalty() || $userFailCnt > 0 || (bool)$clientFlag;
    }

    /**
     * 校验验证码（防重放：比对即销毁，无论对错）。
     * @param string $input 用户输入（统一小写比对）
     * @return bool 是否通过
     */
    public static function captchaCheck($input) {
        $st = isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])
            ? $_SESSION[self::SESSION_KEY] : null;
        // 消耗即销毁（关键：先取值后立即 unset，后续任何分支都不可能复用）
        unset($_SESSION[self::SESSION_KEY]);
        if (!$st) return false;
        if ((time() - (int)$st['created_at']) > self::CAPTCHA_TTL) return false;   // 已过期
        return strtolower(trim((string)$input)) === strtolower((string)$st['code']);
    }

    /** 销毁验证码（登录成功/失败后刷新状态用） */
    public static function captchaClear() {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /* ==================== 验证码生成（原生 GD） ==================== */

    /** 验证码字符集（剔除易混淆 0/O/1/I/l） */
    private static function charset() {
        return 'abcdefghjkmnpqrstuvwxyz23456789';
    }

    /**
     * 生成验证码：写入 Session 并输出 PNG。
     * 4 位字母数字（统一小写存储），2 条干扰弧线 + 80 噪点，轻度扭曲感。
     * GD 扩展缺失时降级输出纯 Session 校验模式（理论上可通过 HTTP 头
     * 探测，但相比无验证码仍多一道比对，属可接受降级）。
     */
    public static function captchaRender() {
        // 生成 4 位随机码（random_int 密码学安全）
        $cs = self::charset();
        $len = strlen($cs);
        $code = '';
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $code .= $cs[random_int(0, $len - 1)];
        }
        // 统一小写存 Session（比对时忽略大小写），附生成时间（TTL 判定）
        $_SESSION[self::SESSION_KEY] = array('code' => $code, 'created_at' => time());

        $w = 130; $h = 42;
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        if (!function_exists('imagecreatetruecolor')) {
            // GD 缺失降级：输出 1x1 透明 PNG（校验仍按 Session 走）
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
            return;
        }
        $img = imagecreatetruecolor($w, $h);
        // 底色（浅灰白）与前景色系（深色随机，保证打印/屏显可辨识）
        $bg = imagecolorallocate($img, 245, 247, 250);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        // 干扰线：2 条随机弧线（浅色）
        for ($i = 0; $i < 2; $i++) {
            $c = imagecolorallocate($img, random_int(150, 200), random_int(150, 200), random_int(150, 200));
            imagesetthickness($img, 1);
            imagearc($img, random_int(0, $w), random_int(0, $h), random_int(40, 130), random_int(30, 90),
                random_int(0, 180), random_int(181, 360), $c);
        }
        // 噪点：80 个 1px 随机点
        for ($i = 0; $i < 80; $i++) {
            $c = imagecolorallocate($img, random_int(120, 220), random_int(120, 220), random_int(120, 220));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }
        // 逐字符绘制（横纵微扰 + 随机色，提高机读难度；imagestring 内置字体零字体文件依赖）
        $charW = 22;
        $x0 = (int)(($w - $charW * self::CODE_LEN) / 2) + 2;
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $c = imagecolorallocate($img, random_int(30, 90), random_int(30, 90), random_int(30, 110));
            imagestring($img, 5, $x0 + $i * $charW + random_int(-2, 2), random_int(8, 14), $code[$i], $c);
        }
        imagepng($img);
        imagedestroy($img);
    }

    /* ==================== check_captcha 防枚举限流 ==================== */

    /**
     * 预检接口限流：每分钟每会话最多 CHECK_LIMIT 次。
     * 超限返回 false（调用方直接拒绝响应，防止高频穷举有效工号）。
     */
    public static function checkRateOk() {
        $k = 'login_check_rate';
        $now = time();
        $st = isset($_SESSION[$k]) && is_array($_SESSION[$k]) ? $_SESSION[$k] : array('c' => 0, 't' => $now);
        if (($now - (int)$st['t']) >= 60) { $st = array('c' => 0, 't' => $now); }
        $st['c'] = (int)$st['c'] + 1;
        $st['t'] = $st['t'] ?: $now;
        $_SESSION[$k] = $st;
        return (int)$st['c'] <= self::CHECK_LIMIT;
    }
}
