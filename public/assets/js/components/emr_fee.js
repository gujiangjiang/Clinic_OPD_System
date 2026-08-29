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

    function buildFeeRows() {
        var rows = [];
        var total = 0;
        var regFee = (ctx.DATA && ctx.DATA.visit ? parseFloat(ctx.DATA.visit.fee) : 0) || 0;
        var regSt = (ctx.DATA && ctx.DATA.visit && ctx.DATA.visit.status === 'finished') ? 'done' : 'paid';
        var regDept = (ctx.DATA && ctx.DATA.visit ? ctx.DATA.visit.first_dept_name : '') || '';
        if (regFee > 0) rows.push({ st: regSt, name: regDept ? ('挂号费（' + regDept + '）') : '挂号费', amt: regFee });
        (ctx.ORDERS || []).forEach(function (o) {
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            (o.items || []).forEach(function (i2) {
                var amt = (parseFloat(i2.price) || 0) * (parseFloat(i2.quantity) || 1);
                total += amt;
                rows.push({ st: i2.status || o.status, name: i2.item_name, amt: amt });
            });
        });
        total += regFee;
        return { rows: rows, total: total };
    }

    function showFeePop(anchor) {
        if (feePopTimer) { clearTimeout(feePopTimer); feePopTimer = null; }
        var stale = document.getElementById('feePop');
        if (stale) stale.remove();
        var d = buildFeeRows();
        if (!d.rows.length) return;
        var pop = document.createElement('div');
        pop.id = 'feePop';
        pop.className = 'fee-pop';
        pop.innerHTML = d.rows.map(function (r) {
            var cls = navDotCls(r.st);
            var tip = (r.name.indexOf('挂号费') === 0 && r.st === 'done') ? '已完成' : navDotText(r.st);
            return '<div class="fee-pop-row">' +
                '<span class="status-indicator ' + cls + '" title="' + tip + '"></span>' +
                '<span class="fee-pop-name" title="' + escHtml(r.name) + '">' + escHtml(r.name) + '</span>' +
                '<span class="fee-pop-amt">¥' + r.amt.toFixed(2) + '</span></div>';
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
        showFeePop: showFeePop,
        hideFeePop: hideFeePop,
    };
})();