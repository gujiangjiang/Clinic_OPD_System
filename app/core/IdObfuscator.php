<?php
/**
 * ============================================================
 * IdObfuscator.php v1.0.0 — 业务实体 ID 混淆加密（防 URL 撞库遍历）
 * ============================================================
 * 说明：
 * 1. 背景：URL / 打印链接中曾以自增明文 ID 标识就诊、申请单、报告等实体
 *    （如 /doctor/emr?visit_id=1），攻击者可改 1→2→3 遍历他人医疗数据。
 * 2. 方案：以管理员可重置的「混淆密钥」（settings 表 obf_token）派生
 *    AES-128-CBC 密钥与 IV，对整数 ID 加密后 base64url 编码为 22 字符
 *    不透明串。链接中不再出现可遍历的连续数字。
 * 3. 重置密钥：旧密文无法解密（填充校验失败）→ 旧链接整体失效，
 *    系统功能不受影响（新链接即时按新密钥生成）。
 * 4. 确定性映射：同一 ID 在同一密钥周期内编码结果恒定 —— 链接稳定可收藏；
 *    IV 由密钥派生而非随机（明文仅为极小整数、目标是防枚举而非抗选择明文，
 *    属于工程权衡，已在注释声明）。密钥重置即切断历史关联。
 * 5. 使用约定（全站统一）：
 *    - 输出侧（HTML/JSON/URL 拼接）：oid($id) 输出混淆串；
 *    - 输入侧（GET/POST 接收）：did($code) 还原整数，失败返回 0，
 *      调用方按「记录不存在」处理，严禁回退接受明文数字。
 *    - 范围：就诊 visit_id、申请单 order_id(s)、明细 item_id(order_items)、
 *      报告 report_id、缴费 payment_id、转诊 ref 等患者级实体。
 *      科室 dept_id 与管理端字典 id 不属患者敏感面，保持原样。
 * ============================================================ */
class IdObfuscator {

    /** settings 表中的密钥键名 */
    const TOKEN_KEY = 'obf_token';

    /** 编码串合法字符集（base64url）长度下限，快速过滤明显非法输入 */
    const MIN_LEN = 16;

    /**
     * 取当前混淆密钥；未设置时自动生成 32 位随机十六进制并持久化
     * （保证系统开箱即用，管理员可在【系统设置】中随时重置）
     */
    public static function secret() {
        $t = setting(self::TOKEN_KEY, '');
        if ($t === '' || !preg_match('/^[0-9a-f]{32}$/', $t)) {
            $t = bin2hex(random_bytes(16));
            set_setting(self::TOKEN_KEY, $t);
        }
        return $t;
    }

    /** 是否已由管理员显式设置过密钥（用于设置页展示状态） */
    public static function configured() {
        return setting(self::TOKEN_KEY, '') !== '';
    }

    /** 重置密钥：生成新随机密钥并覆盖（旧链接即刻失效） */
    public static function reset() {
        $t = bin2hex(random_bytes(16));
        set_setting(self::TOKEN_KEY, $t);
        return $t;
    }

    /** 密钥材料：AES-128 密钥（32 字节截取 16 字节使用前先完整哈希再截断） */
    private static function keyMaterial($secret) {
        return array(
            'key' => substr(hash('sha256', 'obf-key|' . $secret, true), 0, 16),
            'iv'  => substr(hash('sha256', 'obf-iv|' . $secret, true), 0, 16),
        );
    }

    /**
     * 编码：正整数 → URL 安全不透明串（22 字符 base64url）
     * 非法输入（非正整数）返回空串
     */
    public static function encode($id) {
        $id = (int)$id;
        if ($id <= 0) return '';
        $secret = self::secret();
        $km = self::keyMaterial($secret);
        $ct = openssl_encrypt((string)$id, 'aes-128-cbc', $km['key'], OPENSSL_RAW_DATA, $km['iv']);
        if ($ct === false) return '';
        return rtrim(strtr(base64_encode($ct), '+/', '-_'), '=');
    }

    /**
     * 解码：混淆串 → 正整数；非法串 / 密钥不匹配 / 篡改 一律返回 0
     * （调用方按「记录不存在」处理）
     */
    public static function decode($code) {
        $code = trim((string)$code);
        if ($code === '' || strlen($code) < self::MIN_LEN || strlen($code) > 128) return 0;
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) return 0;
        $secret = self::secret();
        $km = self::keyMaterial($secret);
        $ct = base64_decode(strtr($code, '-_', '+/'));
        if ($ct === false || $ct === '') return 0;
        $pt = openssl_decrypt($ct, 'aes-128-cbc', $km['key'], OPENSSL_RAW_DATA, $km['iv']);
        if ($pt === false || !preg_match('/^\d{1,12}$/', $pt)) return 0;
        $n = (int)$pt;
        return $n > 0 ? $n : 0;
    }

    /**
     * 批量解码（兼容单值与逗号分隔批量，如 order_ids=AAA,BBB）：
     * 任一解码失败即整体返回 array()（防止半合法批量的歧义写入）
     */
    public static function decodeList($codes) {
        $arr = is_array($codes) ? $codes : explode(',', (string)$codes);
        $out = array();
        foreach ($arr as $c) {
            $c = trim((string)$c);
            if ($c === '') continue;
            $n = self::decode($c);
            if ($n <= 0) return array();
            $out[] = $n;
        }
        return $out;
    }

    /** 批量编码：数组或逗号分隔的数字 → 逗号分隔混淆串 */
    public static function encodeList($ids) {
        $arr = is_array($ids) ? $ids : explode(',', (string)$ids);
        $out = array();
        foreach ($arr as $i) {
            $e = self::encode($i);
            if ($e === '') return '';
            $out[] = $e;
        }
        return implode(',', $out);
    }
}
