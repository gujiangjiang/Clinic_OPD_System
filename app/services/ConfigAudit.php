<?php
/**
 * ============================================================
 * services/ConfigAudit.php — 配置/接口变更审计
 * ============================================================
 * 记录管理员在「接口管理」等处的配置变更（区域、变更键、操作人、IP、时间），
 * 便于溯源密钥轮换与开关调整。仅记录键名与简要说明，**不记录密钥明文**。
 * 表：config_audit（见 app/config/schema/main.php）。
 * ============================================================ */
class ConfigAudit {

    /** 记录一次配置变更（失败静默，不阻断业务） */
    public static function record($area, array $keys, $detail = '') {
        try {
            $u = class_exists('Auth') ? Auth::user() : null;
            $actor = '';
            if ($u) {
                if (isset($u['name']) && $u['name'] !== '') $actor = (string)$u['name'];
                elseif (isset($u['username'])) $actor = (string)$u['username'];
            }
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
            PatientRepository::insert(
                'INSERT INTO config_audit(actor,area,keys_changed,detail,ip,created_at) VALUES(?,?,?,?,?,?)',
                array($actor, (string)$area, implode(',', array_values($keys)), (string)$detail, $ip, now_str())
            );
        } catch (Exception $e) {
            /* 审计写入失败不影响主流程 */
        }
    }

    /** 某模块（area 形如 integration:fhir:inbound）最近的变更记录 */
    public static function byAreaPrefix($prefix, $limit = 10) {
        $limit = max(1, min(100, (int)$limit));
        try {
            return PatientRepository::q(
                'SELECT * FROM config_audit WHERE area LIKE ? ORDER BY id DESC LIMIT ' . $limit,
                array((string)$prefix . '%')
            );
        } catch (Exception $e) {
            return array();
        }
    }
}
