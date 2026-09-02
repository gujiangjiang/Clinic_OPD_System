/**
 * ============================================================
 * emr_patient.js — 患者信息卡渲染
 * ============================================================
 * 说明：自 emr.js 拆出的患者信息模块——顶部横条信息卡、病历文档内
 * 患者信息网格、患者资料保存后的局部刷新。经 Clinic.emr._ctx 读写
 * 共享状态。依赖：Clinic.get / Clinic.patient。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.patient = (function () {
    var ctx = Clinic.emr._ctx;
    var escHtml = ctx.escHtml;

    function renderPatientCard(d) {
        var p = d.patient, v = d.visit;
        var editModal = "Clinic.patient.editModal('" + escHtml(p.patient_id) + "')";
        var historyModal = "showPatientHistory('" + escHtml(p.patient_id) + "')";
        document.getElementById('emrHeader').innerHTML =
            '<div class="flex-between">' +
            '  <div class="flex gap-12" style="align-items:center">' +
            '    <div class="emr-patient-avatar" onclick="' + historyModal + '" title="点击查看就诊历史">👤</div>' +
            '    <div>' +
            '      <div class="fs-18 fw-700">' +
            '        <span class="emr-patient-name" onclick="' + editModal + '" title="点击修改患者信息">' + escHtml(v.name) + '</span>' +
            '        <span class="badge badge-gray" style="margin-left:8px">' + escHtml(v.gender) + ' / ' + escHtml(v.age_fmt || '') + '</span>' +
            '        ' + (v.fee_type ? '<span class="badge badge-warning" style="margin-left:4px" title="费用类别">' + escHtml(v.fee_type) + '</span>' : '') +
            '        <span class="badge ' + (v.dept_type === 'emergency' ? 'badge-danger' : 'badge-primary') +
            '" style="margin-left:4px">' + (v.dept_type === 'emergency' ? '急诊' : '门诊') + '</span>' +
            '        <span class="badge badge-warning" id="hdrTotal" style="display:none"></span>' +
            '      </div>' +
            '      <div class="text-muted fs-13">患者ID：' + escHtml(p.patient_id) + ' ｜ 流水号：' + escHtml(v.visit_no) +
            ' ｜ ' + escHtml(v.first_dept_name || v.dept_name) + ' 第' + String(v.visit_seq).padStart(3, 0) + '号</div>' +
            '    </div>' +
            '  </div>' +
            '</div>';
    }

    function patientGridHtml(d, vtOverride) {
        var r = d.record || {};
        var vv = d.visit || {};
        var p = d.patient || {};
        var vt = vtOverride || r.visit_type || '初诊';
        var vtSelect = '<select class="doc-cell-select" id="visitType">' +
            '<option value="初诊"' + (vt === '初诊' ? ' selected' : '') + '>初诊</option>' +
            '<option value="复诊"' + (vt === '复诊' ? ' selected' : '') + '>复诊</option></select>';
        var cellHtml = function (f) {
            var isSelect = (typeof f[1] === 'string' && f[1].indexOf('<select') === 0);
            if (isSelect) {
                return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' + f[1] + '</div>';
            }
            return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' +
                '<span class="doc-cell-value">' + escHtml(f[1]) + '</span></div>';
        };
        if (vv.dept_type === 'emergency') {
            var lines = [
                [['姓名', vv.name], ['性别', vv.gender], ['出生日期', p.birth_date], ['年龄', vv.age_fmt]],
                [['患者ID', p.patient_id], ['就诊科室', vv.dept_name], ['就诊时间', vv.created_at]],
            ];
            return '<div class="doc-patient-lines">' + lines.map(function (row) {
                return '<div class="doc-line-row">' + row.map(cellHtml).join('') + '</div>';
            }).join('') + '</div>';
        }
        var fields = [['姓名', vv.name], ['性别', vv.gender], ['年龄', vv.age_fmt], ['患者ID', p.patient_id],
           ['证件号码', p.id_card], ['出生日期', p.birth_date], ['民族', p.nation || '—'],
           ['职业', p.occupation || '—'], ['婚姻', p.marital || '—'], ['初复诊', vtSelect],
           ['科室', vv.dept_name], ['联系方式', p.phone || '—']];
        return '<div class="doc-patient-grid">' + fields.map(cellHtml).join('') + '</div>';
    }

    function refreshPatientHead() {
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                ctx.DATA = j.data;
                renderPatientCard(j.data);
                var card = document.getElementById('emrCard');
                if (!card) return;
                var old = card.querySelector('.doc-patient-grid, .doc-patient-lines');
                if (!old) return;
                var sel = document.getElementById('visitType');
                var wrap = document.createElement('div');
                wrap.innerHTML = patientGridHtml(j.data, sel ? sel.value : '');
                if (wrap.firstElementChild) old.parentNode.replaceChild(wrap.firstElementChild, old);
            },
        });
    }

    return {
        renderPatientCard: renderPatientCard,
        patientGridHtml: patientGridHtml,
        refreshPatientHead: refreshPatientHead,
    };
})();