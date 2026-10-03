/**
 * ============================================================
 * emr_fee.js — 医生工作站「总费用悬浮明细」适配层
 * ============================================================
 * 说明：实现已统一到 Clinic.feePop（feepop.js，临床/医技共用同一套代码）。
 * 本文件仅把医生工作站的上下文（Clinic.emr._ctx 的 DATA/ORDERS）适配为
 * Clinic.feePop 所需的数据结构，保留原有对外 API（buildFeeRows 等）供 emr.js 调用。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.fee = (function () {
    var ctx = Clinic.emr._ctx;

    /** 统一数据源：{ visit, orders } */
    function data() {
        return { visit: (ctx.DATA && ctx.DATA.visit) || {}, orders: ctx.ORDERS || [] };
    }

    function buildFeeGroups() { return Clinic.feePop.groups(data()); }

    /** 兼容：扁平费用行 */
    function buildFeeRows() {
        var g = buildFeeGroups(), rows = [];
        g.groups.forEach(function (grp) { grp.rows.forEach(function (r) { rows.push(r); }); });
        return { rows: rows, total: g.total };
    }

    function showFeePop(anchor) { return Clinic.feePop.show(anchor, data()); }
    function hideFeePop() { return Clinic.feePop.hide(); }

    return {
        buildFeeRows: buildFeeRows,
        buildFeeGroups: buildFeeGroups,
        showFeePop: showFeePop,
        hideFeePop: hideFeePop,
    };
})();
