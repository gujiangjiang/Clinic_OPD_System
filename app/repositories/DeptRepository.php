<?php
/**
 * ============================================================
 * DeptRepository.php — 科室与诊室仓库
 * ============================================================
 * 说明：原查询/增删改方法（all/byId/namesByIds/create/update/
 * deleteDept/byName/rooms/roomById/createRoom/updateRoom/deleteRoom）
 * 全库无调用，科室与诊室业务均以内联 SQL 直调继承的 q/one/val/
 * exec/insert。仅保留仍被引用的 activeById（挂号科室校验），
 * 避免双份 SQL 维护。
 * ============================================================ */
class DeptRepository extends BaseRepository {

    /* ---------------- 科室 ---------------- */

    /** 启用科室详情 */
    public static function activeById($id) {
        return self::one('SELECT * FROM departments WHERE id=? AND status=1', array((int)$id));
    }
}
