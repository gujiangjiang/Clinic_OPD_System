/**
 * ============================================================
 * feepop.js — 患者总费用悬浮明细（临床 / 医技统一实现，消除重复代码）
 * ============================================================
 * 说明：患者信息横条「总费用」徽章 hover 弹窗的唯一实现；医生工作站与各医技
 * 工作台共用，避免此前 emr_fee / deptwork 两套实现各自维护导致的改此漏彼。
 * 数据源统一：{ visit, orders }（visit.fee 为挂号费；orders[].order_type 决定费用分类）。
 *
 * 用法：
 *   Clinic.feePop.show(anchorEl, { visit: DATA.visit, orders: ORDERS });
 *   Clinic.feePop.hide();
 *   Clinic.feePop.groups({ visit, orders }) → { groups:[{key,title,rows:[{st,name,amt}]}], total }
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.feePop = (function () {
    var timer = null;
    var CAT_ORDER = ['reg', 'lab', 'imaging', 'procedure', 'prescription', 'other'];
    var CAT_TITLE = { reg: '挂号费', lab: '检验费', imaging: '检查费', procedure: '处置费', prescription: '处方费', other: '其他费用' };
    var TYPE_CAT = { lab: 'lab', imaging: 'imaging', procedure: 'procedure', prescription: 'prescription' };

    function esc(s) {
        if (Clinic.escHtml) return Clinic.escHtml(s == null ? '' : String(s));
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /** 缴费/执行状态 → 指示点（统一口径） */
    function dot(st) {
        st = String(st || '').toLowerCase();
        if (st === 'done' || st === 'dispensed' || st === 'finished') return { cls: 'green', txt: '已完成' };
        if (st === 'dispensing' || st === 'registered' || st === 'in_progress') return { cls: 'yellow', txt: '进行中' };
        if (st === 'gray' || st === 'cancelled' || st === 'refunded') return { cls: 'gray', txt: '已取消' };
        if (st === 'paid') return { cls: 'red', txt: '已缴费' };
        return { cls: 'red', txt: '待完成' };
    }

    /** 按费用分类归组：挂号费 / 检验费 / 检查费 / 处置费 / 处方费 / 其他 */
    function groups(data) {
        data = data || {};
        var visit = data.visit || {}, orders = data.orders || [];
        var out = {}, total = 0;
        var regFee = visit.fee ? parseFloat(visit.fee) : 0;
        var regSt = (visit.status === 'finished') ? 'done' : 'paid';
        var regDept = visit.first_dept_name || '';
        if (regFee > 0) {
            out.reg = { key: 'reg', title: CAT_TITLE.reg, rows: [{ st: regSt, name: regDept ? ('挂号费（' + regDept + '）') : '挂号费', amt: regFee }] };
            total += regFee;
        }
        orders.forEach(function (o) {
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            var cat = TYPE_CAT[o.order_type] || 'other';
            if (!out[cat]) out[cat] = { key: cat, title: CAT_TITLE[cat], rows: [] };
            (o.items || []).forEach(function (i2) {
                var amt = (parseFloat(i2.price) || 0) * (parseFloat(i2.quantity) || 1);
                total += amt;
                out[cat].rows.push({ st: i2.status || o.status, name: i2.item_name, amt: amt });
            });
        });
        var list = [];
        CAT_ORDER.forEach(function (k) { if (out[k] && out[k].rows.length) list.push(out[k]); });
        return { groups: list, total: total };
    }

    function innerHtml(data) {
        var g = groups(data);
        if (!g.groups.length) return '';
        return g.groups.map(function (grp) {
            var head = '<div class="fee-pop-cat"><span>' + esc(grp.title) + '</span></div>';
            var body = grp.rows.map(function (r) {
                var dd = dot(r.st);
                return '<div class="fee-pop-row">' +
                    '<span class="status-indicator ' + dd.cls + '" title="' + dd.txt + '"></span>' +
                    '<span class="fee-pop-name" title="' + esc(r.name) + '">' + esc(r.name) + '</span>' +
                    '<span class="fee-pop-amt">¥' + r.amt.toFixed(2) + '</span></div>';
            }).join('');
            return head + body;
        }).join('') +
            '<div class="fee-pop-total"><span>合计</span><span>¥' + g.total.toFixed(2) + '</span></div>';
    }

    function show(anchor, data) {
        if (timer) { clearTimeout(timer); timer = null; }
        var stale = document.getElementById('feePop');
        if (stale) stale.remove();
        var inner = innerHtml(data);
        if (!inner) return;
        var pop = document.createElement('div');
        pop.id = 'feePop';
        pop.className = 'fee-pop';
        pop.innerHTML = inner;
        document.body.appendChild(pop);
        var rect = anchor.getBoundingClientRect();
        pop.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        pop.style.left = Math.max(8, rect.right + window.scrollX - 270) + 'px';
        if (Clinic.clampPop) Clinic.clampPop(pop);
        pop.addEventListener('mouseenter', function () { if (timer) { clearTimeout(timer); timer = null; } });
        pop.addEventListener('mouseleave', hide);
    }

    function hide() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () {
            var pop = document.getElementById('feePop');
            if (pop) pop.remove();
        }, 180);
    }

    return { groups: groups, show: show, hide: hide };
})();
