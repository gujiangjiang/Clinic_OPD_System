/**
 * ============================================================
 * emr_fee.js — 费用悬浮明细弹窗
 * ============================================================
 * 说明：自 emr.js 拆出的费用悬浮窗模块——汇总费用行（挂号费 + 开单逐项）
 * 的悬浮明细弹窗（横条徽章 hover）。经 Clinic.emr._ctx 读写共享状态。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.fee = (function () {
    var ctx = Clinic.emr._ctx;
    var escHtml = ctx.escHtml;
    var navDotCls = ctx.navDotCls;
    var navDotText = ctx.navDotText;
    var clampPop = ctx.clampPop;

    var feePopTimer = null;

    /* 费用分类：按开单类型归组，虚线标题分隔（挂号费 / 检验费 / 检查费 / 处置费 / 处方费） */
    var CAT_ORDER = ['reg', 'lab', 'imaging', 'procedure', 'prescription', 'other'];
    var CAT_TITLE = { reg: '挂号费', lab: '检验费', imaging: '检查费', procedure: '处置费', prescription: '处方费', other: '其他费用' };
    var TYPE_CAT = { lab: 'lab', imaging: 'imaging', procedure: 'procedure', prescription: 'prescription' };

    function buildFeeGroups() {
        var groups = {}, total = 0;
        var regFee = (ctx.DATA && ctx.DATA.visit ? parseFloat(ctx.DATA.visit.fee) : 0) || 0;
        var regSt = (ctx.DATA && ctx.DATA.visit && ctx.DATA.visit.status === 'finished') ? 'done' : 'paid';
        var regDept = (ctx.DATA && ctx.DATA.visit ? ctx.DATA.visit.first_dept_name : '') || '';
        if (regFee > 0) {
            groups.reg = { key: 'reg', title: CAT_TITLE.reg, rows: [{ st: regSt, name: regDept ? ('挂号费（' + regDept + '）') : '挂号费', amt: regFee }] };
            total += regFee;
        }
        (ctx.ORDERS || []).forEach(function (o) {
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            var cat = TYPE_CAT[o.order_type] || 'other';
            if (!groups[cat]) groups[cat] = { key: cat, title: CAT_TITLE[cat], rows: [] };
            (o.items || []).forEach(function (i2) {
                var amt = (parseFloat(i2.price) || 0) * (parseFloat(i2.quantity) || 1);
                total += amt;
                groups[cat].rows.push({ st: i2.status || o.status, name: i2.item_name, amt: amt });
            });
        });
        var list = [];
        CAT_ORDER.forEach(function (k) { if (groups[k] && groups[k].rows.length) list.push(groups[k]); });
        return { groups: list, total: total };
    }

    /** 兼容：扁平费用行（不分类） */
    function buildFeeRows() {
        var rows = [], total = 0;
        var g = buildFeeGroups();
        g.groups.forEach(function (grp) { grp.rows.forEach(function (r) { rows.push(r); }); });
        total = g.total;
        return { rows: rows, total: total };
    }

    function showFeePop(anchor) {
        if (feePopTimer) { clearTimeout(feePopTimer); feePopTimer = null; }
        var stale = document.getElementById('feePop');
        if (stale) stale.remove();
        var d = buildFeeGroups();
        if (!d.groups.length) return;
        var pop = document.createElement('div');
        pop.id = 'feePop';
        pop.className = 'fee-pop';
        pop.innerHTML = d.groups.map(function (grp) {
            var head = '<div class="fee-pop-cat"><span>' + escHtml(grp.title) + '</span></div>';
            var body = grp.rows.map(function (r) {
                var cls = navDotCls(r.st);
                var tip = (r.name.indexOf('挂号费') === 0 && r.st === 'done') ? '已完成' : navDotText(r.st);
                return '<div class="fee-pop-row">' +
                    '<span class="status-indicator ' + cls + '" title="' + tip + '"></span>' +
                    '<span class="fee-pop-name" title="' + escHtml(r.name) + '">' + escHtml(r.name) + '</span>' +
                    '<span class="fee-pop-amt">¥' + r.amt.toFixed(2) + '</span></div>';
            }).join('');
            return head + body;
        }).join('') +
            '<div class="fee-pop-total"><span>合计</span><span>¥' + d.total.toFixed(2) + '</span></div>';
        document.body.appendChild(pop);
        var rect = anchor.getBoundingClientRect();
        pop.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        pop.style.left = Math.max(8, rect.right + window.scrollX - 270) + 'px';
        clampPop(pop);
        pop.addEventListener('mouseenter', function () { if (feePopTimer) { clearTimeout(feePopTimer); feePopTimer = null; } });
        pop.addEventListener('mouseleave', hideFeePop);
    }

    function hideFeePop() {
        if (feePopTimer) clearTimeout(feePopTimer);
        feePopTimer = setTimeout(function () {
            var pop = document.getElementById('feePop');
            if (pop) pop.remove();
        }, 180);
    }

    return {
        buildFeeRows: buildFeeRows,
        buildFeeGroups: buildFeeGroups,
        showFeePop: showFeePop,
        hideFeePop: hideFeePop,
    };
})();