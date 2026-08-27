<?php
/**
 * ============================================================
 * record.php — 电子病历接口（结构化 EMR）— 分发入口
 * ============================================================
 * 说明：按功能拆分到 parts/（沿用 admin parts 模式）：
 *   parts/record_read.php   读取（get）
 *   parts/record_write.php  写入（create_progress/save/save_vitals/
 *                           save_diag_order/save_diags）
 *   parts/record_delete.php 删除（delete_record）
 *   parts/record_cert.php   诊断证明（certificate/certificate_print/
 *                           check_previous_diagnoses）
 * 本文件保留公共引导、共享辅助函数与动作分发。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/print_templates.php';
require_once APP_ROOT . '/app/includes/emr_formatter.php';

$u = Auth::user();

/**
 * 诊断证明病历摘要快照（固化锚点）：以该挂号流水的【首诊文书】为
 * 唯一事实来源投影主诉/现病史/初步诊断——证书一经出具即冻结该内容，
 * 无论谁开具、谁补打、后续有多少次续写，展示与打印完全一致。
 * 无结构化病历时回退旧 records 镜像（兼容历史就诊）。
 */
function cert_snapshot_summary($visitId) {
    $pr = DB::one('medical', "SELECT * FROM patient_records WHERE visit_id=? ORDER BY id ASC LIMIT 1", array($visitId));
    if ($pr) {
        $emr = json_decode($pr['emr_data'], true);
        if (is_array($emr)) {
            return array(
                'chief_complaint' => emr_cc_text(isset($emr['chief_complaint']) ? $emr['chief_complaint'] : array()),
                'present_illness' => emr_pi_text(isset($emr['history_present']) ? $emr['history_present'] : array()),
                'initial_diagnosis' => emr_diag_text(isset($emr['diagnoses']) ? $emr['diagnoses'] : array()),
            );
        }
    }
    $rec = DB::one('medical', 'SELECT chief_complaint, present_illness, initial_diagnosis FROM records WHERE visit_id=? ORDER BY id ASC LIMIT 1', array($visitId));
    return array(
        'chief_complaint' => $rec ? (string)$rec['chief_complaint'] : '',
        'present_illness' => $rec ? (string)$rec['present_illness'] : '',
        'initial_diagnosis' => $rec ? (string)$rec['initial_diagnosis'] : '',
    );
}

/** 已开项目快照（与 /api/order visit_orders 同口径，排除已退费/已取消；
 * 多医生接诊：$doctorId>0 时仅取该医生本人开具的项目——谁开单归属谁的病历）
    返回 [检验检查名列表, 处方行列表, 处置项列表] */
function emr_order_snapshot($visitId, $doctorId = 0) {
    $sql = 'SELECT * FROM orders WHERE visit_id=?';
    $params = array($visitId);
    if ((int)$doctorId > 0) { $sql .= ' AND doctor_id=?'; $params[] = (int)$doctorId; }
    $sql .= ' ORDER BY id ASC';   // 与 visit_orders 同口径：新开项目追加在列表末尾
    $orders = DB::q('order', $sql, $params);
    $orderNames = array();
    $rxLines = array();
    $dispItems = array();
    foreach ($orders as $o) {
        $items = DB::q('order', 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', array($o['id']));
        $agg = order_agg_status($o['order_type'], $items);
        if ($agg === 'refunded' || $agg === 'cancelled') continue;
        foreach ($items as $it) {
            if (empty($it['item_name'])) continue; // 防空名明细混入病历文本
            if ($o['order_type'] === 'lab' || $o['order_type'] === 'imaging') {
                $orderNames[] = $it['item_name'];
            } elseif ($o['order_type'] === 'procedure') {
                $dispItems[] = array('name' => $it['item_name'], 'qty' => (int)$it['quantity']);
            } elseif ($o['order_type'] === 'prescription') {
                // 处方行统一走公共方法：成组医嘱树形格式（子药含剂量，
                // 组内频次/途径/数量仅主药行一次），全系统同一套规则
                foreach (emr_rx_display_lines($items) as $l) { $rxLines[] = $l; }
                break; // 该处方单已整单处理，无需逐条重复
            }
        }
    }
    return array($orderNames, $rxLines, $dispItems);
}

require __DIR__ . '/parts/record_read.php';
require __DIR__ . '/parts/record_write.php';
require __DIR__ . '/parts/record_delete.php';
require __DIR__ . '/parts/record_cert.php';

switch ($action) {
    case 'get':
        record_part_read($action);
        break;

    case 'create_progress':
    case 'save':
    case 'save_vitals':
    case 'save_diag_order':
    case 'save_diags':
        record_part_write($action);
        break;

    case 'delete_record':
        record_part_delete($action);
        break;

    case 'certificate':
    case 'certificate_print':
    case 'check_previous_diagnoses':
        record_part_cert($action);
        break;

    default:
        json_fail('未知操作');
}
