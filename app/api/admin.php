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
require __DIR__ . '/parts/admin_import.php';

switch ($action) {

    /* ---------------- 系统设置 / 统计 / 打印中心 ---------------- */
    case 'stats':
    case 'settings':
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
