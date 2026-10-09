<?php
/**
 * ============================================================
 * services/imaging/PacsAuth.php — 出向区域 PACS 鉴权与基地址统一
 * ============================================================
 * 说明：DICOMweb 出向（QIDO/WADO）、区域 UID 解析、连通性测试三处
 * 此前各自复制「基地址解析 + bearer/x-api-key/basic/custom 头构造」。
 * 本类收敛为唯一实现：
 *  - base()   出向区域 PACS 基地址（去掉 {study_uid} 模板与结尾 /studies；
 *             QIDO 优先，回退 WADO）
 *  - headers() 鉴权请求头（可显式传 scheme/value，缺省读取系统设置）
 * ============================================================ */
class PacsAuth {

    /** 出向区域 PACS 基地址（未配置返回空串） */
    public static function base() {
        $base = trim((string)setting('integration.outbound.pacs.qido_url', ''));
        if ($base === '') $base = trim((string)setting('integration.outbound.pacs.wado_url', ''));
        if ($base === '') return '';
        $base = preg_replace('#/\{?(study_uid|studyUID)\}.*$#i', '', $base);
        return rtrim(preg_replace('#/studies/?$#i', '', rtrim($base, '/')), '/');
    }

    /**
     * 鉴权请求头数组
     * @param string|null $scheme none/bearer/x-api-key/basic/custom；null=读系统设置
     * @param string|null $value  Bearer/API-Key 填 Token；Basic 填 用户名:密码；custom 填 头名: 头值
     * @return array
     */
    public static function headers($scheme = null, $value = null) {
        if ($scheme === null) {
            $scheme = (string)setting('integration.outbound.pacs.auth_scheme', 'none');
        }
        if ($value === null) {
            $value = (string)setting('integration.outbound.pacs.auth_value', '');
        }
        $scheme = strtolower(trim((string)$scheme));
        $value = trim((string)$value);
        if ($scheme === '' || $scheme === 'none' || $value === '') return array();
        if ($scheme === 'bearer') return array('Authorization: Bearer ' . $value);
        if ($scheme === 'x-api-key') return array('X-API-Key: ' . $value);
        if ($scheme === 'basic') return array('Authorization: Basic ' . base64_encode($value));
        if ($scheme === 'custom') return array($value);
        return array();
    }
}
