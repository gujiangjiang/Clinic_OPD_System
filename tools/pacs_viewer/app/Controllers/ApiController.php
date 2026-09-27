<?php
/** app/Controllers/ApiController.php — 前端 JSON 接口（全部数据来自 PACS 接口） */
class PvApiController {

    /** 鉴权：未登录返回 JSON 401（避免 fetch 拿到登录页 HTML） */
    private static function guard() {
        if (!PvAuth::check()) pvw_json(401, '登录会话已失效，请重新登录', array('need_login' => true));
    }

    /** 检索检查列表 */
    public static function search() {
        self::guard();
        $kw = (string)pvw_input('q');
        try {
            $list = PvStudyService::search($kw);
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
        }
        $u = PvAuth::user();
        PvQueryLogRepository::add($u['username'], $kw, count($list));
        pvw_json(200, 'success', array(
            'list' => $list,
            'total' => count($list),
            'mode' => PvPacsClient::mode(),
            'remote' => PvPacsClient::isRemote(),
        ));
    }

    /** 调阅单次检查（患者 + 检查 + 序列） */
    public static function study() {
        self::guard();
        $uid = (string)pvw_input('uid');
        if ($uid === '') pvw_json(400, '缺少检查标识');
        try {
            $data = PvStudyService::study($uid);
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
        }
        pvw_json(200, 'success', $data);
    }

    /** 接口连通性测试（管理端） */
    public static function ping() {
        PvAuth::requireAdmin();
        try {
            $p = PvPacsClient::ping();
            pvw_json(200, 'success', $p);
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
        }
    }
}
