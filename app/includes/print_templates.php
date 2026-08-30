<?php
/**
 * ============================================================
 * print_templates.php v1.0.0 — 统一打印模板（加载器）
 * ============================================================
 * 说明：所有单据打印（挂号凭条/缴费凭条/检验检查申请单/处方单/
 * 处置单/检验检查报告/诊断证明/电子病历）统一由本模块生成 HTML，
 * 前端 print.js 渲染到 #print-area 后调用 window.print()。
 * 打印样式见 print.css（@media print 只显示打印区域）。
 *
 * 按单据类型拆分到 print/ 子目录（公共 helper 在前，各单据随后）：
 *   print/print_common.php  公共小票/页头/患者信息/节 helper
 *   print/print_receipt.php 挂号凭条/缴费凭条
 *   print/print_order.php   申请单/处方单/处置单
 *   print/print_report.php  检验/检查报告
 *   print/print_record.php  电子病历
 *   print/print_cert.php    诊断证明
 * ============================================================ */
require_once APP_ROOT . '/app/includes/emr_formatter.php';

/**
 * 条形码生成已独立到 app/core/barcode.php（barcode128_svg，纯 PHP Code 128 / SVG），
 * 由 bootstrap.php 全站加载，本文件直接调用即可。
 */

require_once __DIR__ . '/print/print_common.php';
require_once __DIR__ . '/print/print_receipt.php';
require_once __DIR__ . '/print/print_order.php';
require_once __DIR__ . '/print/print_report.php';
require_once __DIR__ . '/print/print_record.php';
require_once __DIR__ . '/print/print_cert.php';
require_once __DIR__ . '/print/print_consent.php';
require_once __DIR__ . '/print/print_consult.php';
