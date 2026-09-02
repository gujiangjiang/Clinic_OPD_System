<?php
/**
 * ============================================================
 * admin.php v1.1.0 — 管理端接口（分发入口）
 * ============================================================
 * 说明：为避免单文件过大，管理端接口按功能拆分到 parts/ 子目录：
 *   parts/admin_settings.php   系统设置/统计/打印中心
 *   parts/admin_dept.php       科室管理
 *   parts/admin_user.php       用户管理
 *   parts/admin_item.php       检验/检查项目与分类
 *   parts/admin_drug.php       药品设置与药品信息
 *   parts/admin_disp.php       处置项目
 *   parts/admin_audit.php      审核中心（含密码重置审核）
 * 项目/药品表单统一由 includes/forms.php 渲染（检验科/影像科/药房共用）。
 * 本文件仅负责按 action 分发到对应子模块。
 * ============================================================ */
require __DIR__ . '/_init.php';
require_once APP_ROOT . '/app/includes/forms.php';

$u = Auth::user();

require __DIR__ . '/parts/admin_settings.php';
require __DIR__ . '/parts/admin_dept.php';
require __DIR__ . '/parts/admin_user.php';
require __DIR__ . '/parts/admin_item.php';
require __DIR__ . '/parts/admin_drug.php';
require __DIR__ . '/parts/admin_disp.php';
require __DIR__ . '/parts/admin_audit.php';
require __DIR__ . '/parts/admin_call.php';
require __DIR__ . '/parts/admin_analytics.php';
require __DIR__ . '/parts/admin_import.php';

// 科室角色（检验科/影像科/药房）仅开放与本职相关的只读接口与提交审核：
// 其余管理操作（删除/分类/用户/科室/组合管理/设置等）仍仅限管理员。
// 各角色仅能访问本职字典：检验科=检验项目，影像科=检查项目，药房=药品，
// 杜绝跨科室互改（如影像科改检验项目、检验科改药品）。
if (in_array($u['role'], array('lab', 'imaging', 'pharmacy'), true)) {
    if ($u['role'] === 'pharmacy') {
        // 药房：仅药品信息/设置只读 + 新增修改提交审核
        $roleOpenActions = array(
            'drug_list', 'drugsetting_list', 'drugsetting_save', 'drug_save',
        );
    } else {
        // 检验科（lab）/ 影像科（imaging）：本职项目查看与提交审核 + 组合只读
        $roleOpenActions = array(
            'item_list', 'item_form', 'item_save', 'cat_list',
            'lab_groups', 'lab_group_get', 'lab_group_candidates',
        );
    }
    if (!in_array($action, $roleOpenActions, true)) {
        json_fail('无权限访问该功能（该操作需管理员处理）');
    }
}

switch ($action) {

    /* ---------------- 系统设置 / 统计 / 打印中心 ---------------- */
    case 'stats':
    case 'settings':
    case 'work_save':
    case 'upload_logo':
    case 'print_items':
    // URL 混淆密钥管理（状态/重置）
    case 'obf_status':
    case 'obf_reset':
        admin_part_settings($action);
        break;

    /* ---------------- 科室管理 ---------------- */
    case 'dept_list':
    case 'dept_form':
    case 'dept_save':
    case 'dept_delete':
        admin_part_dept($action);
        break;

    /* ---------------- 用户管理 ---------------- */
    case 'user_list':
    case 'user_form':
    case 'user_save':
    case 'user_delete':
        admin_part_user($action);
        break;

    /* ---------------- 检验/检查项目与分类 ---------------- */
    case 'item_list':
    case 'item_form':
    case 'item_save':
    case 'item_delete':
    case 'cat_list':
    case 'cat_add':
    case 'cat_delete':
    // 检验组合（组合项目：按组价整体收费，医生可单独开或整体开组）
    case 'lab_group_form':
    case 'lab_group_save':
    case 'lab_group_delete':
    case 'lab_groups':
    case 'lab_group_get':
    case 'lab_group_candidates':
    case 'lab_group_add_item':
    case 'lab_group_remove_item':
        admin_part_item($action);
        break;

    /* ---------------- 药品设置与药品信息 ---------------- */
    case 'drugsetting_list':
    case 'drugsetting_save':
    case 'drugsetting_delete':
    case 'drug_list':
    case 'drug_form':
    case 'drug_save':
    case 'drug_delete':
        admin_part_drug($action);
        break;

    /* ---------------- 处置项目 ---------------- */
    case 'disposal_list':
    case 'disposal_form':
    case 'disposal_save':
    case 'disposal_delete':
    // 通用检索 / 关联快建（皮试·途径联动配套）
    case 'disposal_search':
    case 'disposal_quick_create':
        admin_part_disp($action);
        break;

    /* ---------------- 审核中心 ---------------- */
    case 'audit_list':
    case 'audit':
    case 'audit_all':
    case 'audit_preview':
        admin_part_audit($action);
        break;

    /* ---------------- 叫号大屏/诊室管理 ---------------- */
    case 'room_list':
    case 'room_create':
    case 'room_save':
    case 'room_reset_token':
    case 'room_release':
    case 'room_delete':
    case 'room_token':
        admin_part_call($action);
        break;

    /* ---------------- 医院运营分析 ---------------- */
    case 'ana_overview':
    case 'ana_trend':
    case 'ana_dept':
    case 'ana_doctor':
    case 'ana_custom':
    case 'ana_disposition':
        admin_part_analytics($action);
        break;

    /* ---------------- 通用数据导入导出（7 大模块） ---------------- */
    case 'download_template':
    case 'export_data':
    case 'import_preview':
    case 'import_confirm':
        admin_part_import($action);
        break;

    default:
        json_fail('未知操作');
}
