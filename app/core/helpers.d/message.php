<?php
/**
 * ============================================================
 * helpers.d/message.php — 站内消息发送
 * ============================================================
 * 说明：生成站内消息（通知方式：纯站内消息 + 打印提醒）。
 * 由 helpers.php 统一加载，拆分后引用方式不变。
 * ============================================================ */

/** 生成站内消息（通知方式：纯站内消息 + 打印提醒） */
function send_msg($toRole, $toUserId, $title, $content = '', $printType = '', $printUrl = '', $extra = array()) {
    // $extra：可选扩展字段
    //   msg_type     'patient' 患者消息 / 'system' 系统消息（默认）
    //   patient_name 患者姓名（患者消息时显示）
    //   visit_id     关联就诊ID（点击可跳转到该次病历）
    //   link_url     自定义跳转链接（如审核驳回后跳回添加页回填重提）
    $extra = is_array($extra) ? $extra : array();
    DB::insert('INSERT INTO messages(from_name, from_user_id, to_role, to_user_id, title, content, print_type, print_url, is_read, msg_type, patient_name, visit_id, link_url, created_at) VALUES(?,?,?,?,?,?,?,?,0,?,?,?,?,?)', array(
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