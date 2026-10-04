/**
 * ============================================================
 * emr_format.js — 电子病历文本格式化纯函数
 * ============================================================
 * 说明：自 emr.js 拆出的纯函数模块——主诉/现病史/既往史/过敏史/
 * 主要症状/体格检查/诊断列表的文本格式化，仅依赖 Clinic.escHtml 与
 * Clinic.emrEditor.diagText，无内部状态，可独立测试。
 * 用法：Clinic.emr.format.fmtCC(...) 等；emr.js 内以本地别名调用。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};
Clinic.emr.format = (function () {

    /** 主诉文本：主要症状+时间+单位 [次要症状+时间+单位]（同 emr_cc_text） */
    function fmtCC(cc) {
        cc = cc || {};
        var seg = function (s, d, u) { return (s || '') + (d || '') + (u || ''); };
        return seg(cc.symptom, cc.duration, cc.unit) + seg(cc.second_symptom, cc.second_duration, cc.second_unit);
    }

    /** 现病史文本：供史者+时间+单位+内容[，来院途径]（同 emr_pi_text） */
    function fmtPI(pi) {
        pi = pi || {};
        var head = (pi.informant || '') + (pi.duration || '') + (pi.unit || '') + (pi.content || '');
        var way = pi.arrival_way || '';
        if (!head && !way) return '';
        return way ? (head ? head + '，' : '') + way : head;
    }

    /** 既往史文本（同 emr_ph_text） */
    function fmtPH(ph) {
        ph = ph || {};
        if ((ph.type || '否认') !== '承认') return '否认';
        return ph.detail ? '承认，' + ph.detail : '承认';
    }

    /** 过敏史文本（同 emr_al_text；兼容旧纯文本格式） */
    function fmtAL(al) {
        if (typeof al === 'string') return al;
        al = al || {};
        if ((al.type || '否认') !== '承认') return '否认';
        return al.detail || '承认';
    }

    /** 主要症状文本：仅输出已选项（同 emr_ms_text） */
    function fmtMS(ms) {
        ms = ms || {};
        return Object.keys(ms).filter(function (k) { return ms[k]; })
            .map(function (k) { return k + '：' + ms[k]; }).join('，');
    }

    /** 体格检查文本：已填项「名称：值」（同 emr_pe_text，全空返回 '-'） */
    function fmtPE(pe) {
        pe = pe || {};
        return Object.keys(pe).filter(function (k) { return String(pe[k] || '').trim(); })
            .map(function (k) { return k + '：' + pe[k]; }).join('，');
    }

    /** 诊断列表文本（复用编辑器同款格式：编码 部位名称（备注）疑似?） */
    function fmtDiags(list) {
        return (list || []).map(function (dg) {
            return dg && dg.name ? Clinic.emrEditor.diagText(dg) : '';
        }).filter(Boolean).join('，');
    }

    return {
        fmtCC: fmtCC, fmtPI: fmtPI, fmtPH: fmtPH, fmtAL: fmtAL,
        fmtMS: fmtMS, fmtPE: fmtPE, fmtDiags: fmtDiags,
    };
})();
