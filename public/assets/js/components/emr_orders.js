/**
 * ============================================================
 * emr_orders.js — 病历正文开单展示
 * ============================================================
 * 说明：自 emr.js 拆出的开单展示模块——病历正文 辅助检查/门诊处置/
 * 处方 的所见即所得渲染与只读段纯文本。经 Clinic.emr._ctx 读写共享
 * 状态。依赖：Clinic.orderRxLines / Clinic.emrEditor。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.orders = (function () {
    var ctx = Clinic.emr._ctx;
    var escHtml = ctx.escHtml;
    var myDoctorId = ctx.myDoctorId;

    /**
     * 只读段纯文本（首诊/续写只读展示用）：
     * 返回 [检验检查名列表, 处方行列表, 处置项列表]（已退费/已取消不计）。
     * 开单按【病历记录】强关联过滤：仅展示本记录（record_id）开具的项目，
     * 杜绝会诊/续写病历与首诊之间互相串显示。兼容旧数据（record_id=0）按医生归属。
     */
    function orderTextsFor(doctorId, recordId) {
        var aux = [];
        var proc = [];
        var rxs = [];
        var recId = recordId || 0;
        (ctx.ORDERS || []).forEach(function (o) {
            if ((o.doctor_id || 0) !== doctorId) return;
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            // 新数据按 record_id 强关联；旧数据（record_id=0）回退按医生归属
            var oRec = o.record_id || 0;
            // 严格按病历绑定展示：
            // · 当前记录已有 id（recId>0）→ 仅展示绑定到本记录的（或旧数据 oRec=0 按医生）；
            // · 当前记录未保存（recId=0，新建续写/会诊编辑中）→ 仅展示旧数据（oRec=0），
            //   绝不展示绑定在其他病历上的新开单（防止续写中带入上个续写/会诊的开单）
            if (recId > 0) {
                if (oRec > 0 && oRec !== recId) return;
            } else {
                if (oRec > 0) return;
            }
            o.items.forEach(function (it) {
                if (o.order_type === 'lab' || o.order_type === 'imaging') {
                    aux.push(it.item_name);
                } else if (o.order_type === 'procedure') {
                    proc.push(it.item_name + '×' + it.quantity);
                }
            });
            if (o.order_type === 'prescription') {
                Clinic.orderRxLines(o.items).forEach(function (l) { rxs.push(l); });
            }
        });
        // 会诊：本人发起的会诊在门诊处置中显示「请X科会诊」（目标科室名）
        // 会诊与病历强关联：仅显示【本记录】（record_id）发起的会诊；
        // 兼容旧数据（record_id=0）回退按医生归属
        (ctx.CONSULTS || []).forEach(function (c) {
            if ((c.from_doctor_id || 0) !== doctorId) return;
            var cRec = c.record_id || 0;
            if (recId > 0) {
                if (cRec > 0 && cRec !== recId) return;
            } else {
                if (cRec > 0) return;
            }
            proc.push('请' + (c.target_dept_name || '') + '会诊');
        });
        return { aux: aux, proc: proc, rxs: rxs };
    }

    /** 项目交互 token：活跃病历正文中的可点击行内标签（只读段不使用） */
    function itemToken(o, it, extra) {
        var suffix = '';
        if ((o.order_type === 'lab' || o.order_type === 'imaging') && it.report_id) suffix = '（已出报告）';
        return '<span class="emr-item-link" data-otype="' + o.order_type + '" data-oid="' + o.id + '" data-iid="' + it.id + '">' +
            escHtml(it.item_name) + (extra || '') + suffix + '</span>';
    }

    /**
     * 病历正文 辅助检查/门诊处置 渲染（活跃编辑器）：
     * 项目渲染为交互式行内标签（点击弹出详情模态框）；只读段仍走 orderTextsFor 纯文本。
     * 开单按【病历记录】强关联：仅渲染当前记录（record_id）名下开单——
     * 首诊显示首诊期间开单，续写/会诊显示各自记录期间开单，互不串显示。
     */
    function renderDocOrders() {
        var myId = myDoctorId();
        var curRec = (ctx.DATA && ctx.DATA.record) || {};
        var curRecId = curRec.record_id || 0;
        // 过滤条件：开单属于当前记录（新数据 record_id 强关联；旧数据 record_id=0 回退按医生归属）
        // · 当前记录已保存（curRecId>0）→ 仅展示绑定到本记录的（或旧数据 oRec=0 按医生）；
        // · 当前记录未保存（curRecId=0，新建续写/会诊编辑中）→ 仅展示旧数据（oRec=0），
        //   绝不展示绑定在其他病历上的新开单（防止续写中带入上个续写/会诊的开单）
        var matchRec = function (o) {
            var oRec = o.record_id || 0;
            if (curRecId > 0) {
                if (oRec > 0) return oRec === curRecId;
                return (o.doctor_id || 0) === myId;
            }
            if (oRec > 0) return false;
            return (o.doctor_id || 0) === myId;
        };
        var auxT = [], rxLines = [], dispT = [];
        (ctx.ORDERS || []).forEach(function (o) {
            if (!matchRec(o)) return;
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            if (o.order_type === 'lab' || o.order_type === 'imaging') {
                o.items.forEach(function (it) { auxT.push(itemToken(o, it)); });
            } else if (o.order_type === 'procedure') {
                o.items.forEach(function (it) { dispT.push(itemToken(o, it) + (it.quantity > 1 ? '×' + it.quantity : '')); });
            } else if (o.order_type === 'prescription') {
                var i3 = 0;
                while (i3 < o.items.length) {
                    var it0 = o.items[i3];
                    var g = it0.group_no || 0;
                    if (!g) {
                        rxLines.push('<div class="ef-rx-line">' + itemToken(o, it0) +
                            '\u3000' + escHtml([it0.single_dose, it0.frequency_name, it0.route_name].filter(Boolean).join('\u3000')) +
                            '\u3000\u00D7' + it0.quantity + '</div>');
                        i3++;
                        continue;
                    }
                    var arr = [it0];
                    var j3 = i3 + 1;
                    while (j3 < o.items.length && (o.items[j3].group_no || 0) === g) { arr.push(o.items[j3]); j3++; }
                    arr.forEach(function (x, xi) {
                        if (xi === 0) {
                            rxLines.push('<div class="ef-rx-line">' + itemToken(o, x) +
                                '\u3000' + escHtml([x.single_dose, x.frequency_name, x.route_name].filter(Boolean).join('\u3000')) +
                                '\u3000\u00D7' + x.quantity + '</div>');
                        } else {
                            var head = (xi === arr.length - 1 ? '\u2514\u2500 ' : '\u251C\u2500 ') + itemToken(o, x) +
                                (x.single_dose ? '\u3000' + escHtml(x.single_dose) : '');
                            rxLines.push('<div class="ef-rx-line ef-rx-sub">' + head + '</div>');
                        }
                    });
                    i3 = j3;
                }
            }
        });
        // 会诊：本人发起的会诊在门诊处置中显示「请X科会诊」（点击弹出会诊详情）；
        // 样式由病历系统统一渲染（复用 .emr-item-link 项目标签同款样式）
        // 会诊与病历强关联：仅显示【本记录】（record_id）发起的会诊；旧数据（record_id=0）回退按医生归属
        (ctx.CONSULTS || []).forEach(function (c) {
            if ((c.from_doctor_id || 0) !== myId) return;
            var cRec = c.record_id || 0;
            if (curRecId > 0 && cRec > 0 && cRec !== curRecId) return;
            dispT.push('<span class="emr-item-link emr-consult-link" data-cid="' + c.id + '" onclick="event.stopPropagation();Clinic.emr.openConsultDetail(\'' + c.code + '\')">请' + escHtml(c.target_dept_name || '') + '会诊</span>');
        });
        Clinic.emrEditor.setAuto('aux_orders', auxT.join('，'), auxT.length > 0);
        Clinic.emrEditor.setAuto('rx_lines', rxLines.join(''), rxLines.length > 0);
        Clinic.emrEditor.setAuto('disp_items', dispT.join('，'), dispT.length > 0);
    }

    return {
        orderTextsFor: orderTextsFor,
        itemToken: itemToken,
        renderDocOrders: renderDocOrders,
    };
})();