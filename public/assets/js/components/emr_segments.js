/**
 * ============================================================
 * emr_segments.js — 只读段渲染
 * ============================================================
 * 说明：自 emr.js 拆出的他人文书只读段模块——只读段 HTML 生成（含
 * 首诊/续写版式）、多医生文书排序分割、只读区刷新、前序诊断上下文注入。
 * 经 Clinic.emr._ctx 读写共享状态。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.segments = (function () {
    var ctx = Clinic.emr._ctx;
    var escHtml = ctx.escHtml;
    var vitalDisplayText = ctx.vitalDisplayText;

    function roSegmentHtml(rec) {
        var e = rec.emr || {};
        var isProgress = rec.record_type === 'progress';
        var isConsultRec = (rec.consultation_id || 0) > 0;
        var secs = [];
        var push = function (label, val, dashWhenEmpty) {
            val = val == null ? '' : String(val).trim();
            if (!val && !dashWhenEmpty) return;
            secs.push('<div class="prev-sec"><span class="doc-sec-label">' + label + '：</span>' +
                escHtml(val || '-') + '</div>');
        };
        if (isProgress) push(isConsultRec ? '会诊记录' : '病历续写', (e.progress || {}).content);
        // 续写/会诊：主诉/现病史/主要症状归首诊文书；既往史/过敏史仅「承认」时显示（与打印一致）
        if (isProgress) {
            var phT = Clinic.emr.format.fmtPH(e.past_history);
            if (phT !== '否认') push('既往史', phT);
            var alT = Clinic.emr.format.fmtAL(e.allergies);
            if (alT !== '否认') push('过敏史', alT);
        } else {
            push('主诉', Clinic.emr.format.fmtCC(e.chief_complaint));
            push('现病史', Clinic.emr.format.fmtPI(e.history_present));
            push('既往史', Clinic.emr.format.fmtPH(e.past_history));
            push('过敏史', Clinic.emr.format.fmtAL(e.allergies));
            push('主要症状', Clinic.emr.format.fmtMS(e.main_symptoms));
        }
        // 生命体征：续写/会诊记录只用本记录自身 emr.vitals（完全独立，绝不回退就诊体征）；
        // 首诊记录才回退就诊 vitals。vitalDisplayText 空时返回 '—'（非空），
        // 需按原始数据判断是否有值；续写文书空段不显示（仅首诊显示 -）
        var recVitals = (e.vitals && Object.keys(e.vitals).length) ? e.vitals : (isProgress ? {} : (rec.vitals || {}));
        var hasVitals = false;
        ['bp_systolic', 'bp_diastolic', 'heart_rate', 'pulse', 'spo2', 'respiration'].forEach(function (k) {
            if (recVitals[k]) hasVitals = true;
        });
        push('生命体征', hasVitals ? vitalDisplayText(recVitals) : '', isProgress ? false : true);
        push('意识状态', rec.consciousness || '', isProgress ? false : true);
        push('体格检查', Clinic.emr.format.fmtPE(e.physical_exam), isProgress ? false : true);
        push('初步诊断', Clinic.emr.format.fmtDiags(e.diagnoses));
        // ===== 辅助检查 / 门诊处置 =====
        // 按 record_id 强关联拉取本记录名下开单 + 会诊（续写/会诊病历也拉取自己名下开单），
        // 杜绝跨病历串显示；兼容旧数据（record_id=0）回退按医生归属
        var auxParts = [];
        var rxHtml = '';
        var procParts = [];
        // 所有记录类型（包括续写/会诊）均按 record_id 拉取本记录名下开单
        var t2 = Clinic.emr.orders.orderTextsFor(rec.doctor_id || 0, rec.record_id || rec.id || 0);
        t2.aux.forEach(function (n) { auxParts.push(escHtml(n)); });
        if (t2.rxs.length) {
            rxHtml = t2.rxs.map(function (l) { return '<div>' + l + '</div>'; }).join('');
        }
        t2.proc.forEach(function (p) { procParts.push(escHtml(p)); });
        // 续写/会诊记录的辅助检查/门诊处置：本记录手工字段 + 本记录名下开单（通过 orderTextsFor + record_id 精确匹配）
        [e.aux_result, e.aux_external].forEach(function (x) {
            if (x && String(x).trim()) auxParts.push(escHtml(x));
        });
        push('辅助检查', auxParts.join('，'), isProgress ? false : true);
        if (e.disposition_custom && String(e.disposition_custom).trim()) procParts.push(escHtml(e.disposition_custom));
        var treatHtml = (rxHtml ? rxHtml : '') + (procParts.length ? '<span>' + procParts.join('，') + '</span>' : '');
        // 门诊处置：续写空时整段不显示（首诊显示 -）
        if (!isProgress || treatHtml) {
            secs.push('<div class="prev-sec"><span class="doc-sec-label">门诊处置：</span>' + (treatHtml || '-') + '</div>');
        }
        push('是否留观', e.is_leave_hospital === '是' ? '是' : '否', isProgress ? false : true);
        push('嘱托', e.advice);
        var typeBadge = isConsultRec
            ? '<span class="badge badge-warning">会诊记录</span>'
            : (isProgress
                ? '<span class="badge badge-primary">病历续写</span>'
                : '<span class="badge badge-gray">首诊</span>');
        var authorSpan = '<span class="fw-600">记录医生：' + escHtml(rec.doctor_name) +
            (rec.doctor_title ? ' ' + escHtml(rec.doctor_title) : '') +
            (rec.doctor_emp ? ' （工号 ' + escHtml(rec.doctor_emp) + '）' : '') + '</span>';
        return '<div class="prev-record-wrap-sec emr-record-readonly" id="recSeg' + rec.id + '">' +
            '<div class="prev-record-head">' + authorSpan +
            '<span>记录时间：' + escHtml(rec.created_at) + '</span>' + typeBadge + '</div>' +
            '<div class="prev-record-body">' +
            (secs.length ? secs.join('') : '<div class="text-muted fs-13">（该文书暂无内容）</div>') + '</div>' +
            '<div class="doc-body-sign ro-sign">医生：' + escHtml(rec.doctor_name) + '</div></div>';
    }

    function splitOthers(d) {
        var mineRid = d.record && d.record.record_id;
        var hist = d.records_history || [];
        var myIdx = -1;
        for (var i = 0; i < hist.length; i++) {
            if (mineRid && ((hist[i].record_id || 0) === mineRid || (hist[i].id || 0) === mineRid)) {
                myIdx = i; break;
            }
        }
        if (myIdx === -1) return { before: hist.slice(), after: [] };
        // 转科后：本人当前文书书写科室与就诊当前科室不一致 → 该文书一并归入只读段
        var deptMismatch = d.record && d.record.dept_match === 0;
        if (deptMismatch) return { before: hist.slice(0, myIdx + 1), after: hist.slice(myIdx + 1) };
        return { before: hist.slice(0, myIdx), after: hist.slice(myIdx + 1) };
    }

    function refreshReadOnlyBodies(d) {
        if (!d) d = ctx.DATA;
        if (!d) return;
        // 诊毕只读：全部文书以只读段展示（打印版式），重新渲染 docBody 显示开单
        if (d.visit && d.visit.status === 'finished') {
            var docBody = document.getElementById('docBody');
            if (docBody && (d.records_history || []).length) {
                docBody.innerHTML = '<div class="prev-record-wrap">' + d.records_history.map(roSegmentHtml).join('') + '</div>';
            }
            return;
        }
        // 会诊锁/只读模式：同时重刷 roBefore/roAfter 和 docBody 中的当前记录只读段
        // （ORDERS 异步加载后调用，确保只读段显示开单）
        if (d.__consult_mode) {
            var parts = splitOthers(d);
            var beforeEl = document.getElementById('roBefore');
            var afterEl = document.getElementById('roAfter');
            if (beforeEl) beforeEl.innerHTML = parts.before.length ? parts.before.map(roSegmentHtml).join('') : '';
            if (afterEl) afterEl.innerHTML = parts.after.length ? parts.after.map(roSegmentHtml).join('') : '';
            var docBody2 = document.getElementById('docBody');
            if (docBody2 && d.record && d.record.record_id > 0) {
                var rec2 = d.record;
                var seg2 = { id: rec2.record_id, record_id: rec2.record_id, doctor_id: rec2.doctor_id,
                    doctor_name: rec2.doctor_name, doctor_emp: rec2.doctor_emp||'', doctor_title: rec2.doctor_title||'',
                    record_type: rec2.record_type, emr: rec2.emr||{}, created_at: rec2.created_at||'',
                    consultation_id: rec2.consultation_id||0, consciousness: rec2.consciousness||'', vitals: {} };
                docBody2.innerHTML = '<div class="prev-record-wrap">' + roSegmentHtml(seg2) + '</div>';
            }
            return;
        }
        var parts = splitOthers(d);
        var beforeEl = document.getElementById('roBefore');
        var afterEl = document.getElementById('roAfter');
        if (beforeEl) beforeEl.innerHTML = parts.before.length ? parts.before.map(roSegmentHtml).join('') : '';
        if (afterEl) afterEl.innerHTML = parts.after.length ? parts.after.map(roSegmentHtml).join('') : '';
    }

    function injectPrevDiagContext() {
        if (!ctx.DATA || !ctx.DATA.records_history) return;
        var mineId = ctx.DATA.record && ctx.DATA.record.doctor_id;
        var flat = [];
        ctx.DATA.records_history.forEach(function (r) {
            if (r.doctor_id === mineId) return;
            ((r.emr && r.emr.diagnoses) || []).forEach(function (dg) {
                if (dg && dg.name) {
                    flat.push({
                        code: dg.code || '', name: dg.name, part: dg.part || '',
                        note: dg.note || '', suspected: dg.suspected || '',
                        doctor_name: r.doctor_name || '前序医生',
                    });
                }
            });
        });
        Clinic.emrEditor.setPrevDiagnoses(flat);
    }

    return {
        roSegmentHtml: roSegmentHtml,
        splitOthers: splitOthers,
        refreshReadOnlyBodies: refreshReadOnlyBodies,
        injectPrevDiagContext: injectPrevDiagContext,
    };
})();