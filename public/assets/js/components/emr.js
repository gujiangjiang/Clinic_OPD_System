/**
 * ============================================================
 * emr.js v1.0.0 — 电子病历编辑器逻辑
 * ============================================================
 * 说明：医生看诊页专用。负责：
 * 1. 加载病历数据（患者信息不可编辑区 + 可编辑病历区）
 * 2. 保存病历 / 保存并诊毕（必填校验）
 * 3. 初步诊断与 ICD10 编码联动（搜索下拉）
 * 4. 病历模板调用（个人/全科/全院，审核后生效）
 * 5. 转科、诊断证明、打印病历
 * 依赖：ajax.js、modal.js、print.js、editor.js、validation.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.emr = (function () {
    /** 当前就诊数据缓存 */
    var DATA = null;

    /**
     * 初始化页面
     */
    function init() {
        var visitId = document.getElementById('visitId').value;
        loadData(visitId);
    }

    /**
     * 加载病历数据
     */
    function loadData(visitId) {
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                DATA = j.data;
                renderPatientCard(j.data);
                renderEmrCard(j.data);
                loadOrders(visitId);
                Clinic.order.init(visitId, j.data);
                // 一键引用前序病历
                var refId = document.getElementById('refRecordId').value;
                if (refId) {
                    var prev = (j.data.prev_records || []).find(function (r) { return r.id == refId; });
                    if (prev) {
                        applyTemplate(prev);
                    }
                }
                bindTemplateBtn();
            },
        });
    }

    /**
     * 渲染患者信息卡（不可编辑区域）
     */
    function renderPatientCard(d) {
        var p = d.patient, v = d.visit;
        document.getElementById('emrHeader').innerHTML =
            '<div class="card" style="background:var(--bg-card)">' +
            '<div class="flex-between">' +
            '  <div class="flex gap-12" style="align-items:center">' +
            '    <div style="font-size:30px">👤</div>' +
            '    <div>' +
            '      <div class="fs-18 fw-700">' + v.name +
            '        <span class="badge badge-gray" style="margin-left:8px">' + v.gender + ' / ' + v.age + '岁</span>' +
            '        <span class="badge ' + (v.dept_type === 'emergency' ? 'badge-danger' : 'badge-primary') +
            '" style="margin-left:4px">' + (v.dept_type === 'emergency' ? '急诊' : '门诊') + '</span>' +
            '      </div>' +
            '      <div class="text-muted fs-13">患者ID：' + p.patient_id + ' ｜ 流水号：' + v.visit_no +
            ' ｜ ' + v.dept_name + ' 第' + String(v.visit_seq).padStart(3, '0') + '号' +
            (d.has_certificate ? ' ｜ <span class="text-success">已开诊断证明</span>' : '') + '</div>' +
            '    </div>' +
            '  </div>' +
            '  <div class="text-right fs-13 text-muted">' +
            '    <div>就诊医生：' + (DATA.record.doctor_name || '') + '</div>' +
            '    <div>记录时间：<span id="recTime">' + (DATA.record.created_at || '') + '</span></div>' +
            '  </div>' +
            '</div></div>';

        document.getElementById('patientCard').innerHTML =
            '<div class="card-title">患者信息（不可修改）</div>' +
            '<div class="flex gap-12" style="flex-wrap:wrap" id="patientInfo"></div>';
        // 门诊/急诊不同抬头
        var fields = v.dept_type === 'emergency'
            ? [['姓名', v.name], ['性别', v.gender], ['出生日期', p.birth_date], ['年龄', v.age + '岁'],
               ['患者ID', p.patient_id], ['就诊科室', v.dept_name], ['就诊时间', v.created_at]]
            : [['姓名', v.name], ['性别', v.gender], ['年龄', v.age + '岁'], ['患者ID', p.patient_id],
               ['证件号码', p.id_card], ['出生日期', p.birth_date], ['民族', p.nation || '—'],
               ['职业', p.occupation || '—'], ['婚姻', p.marital || '—'], ['初复诊', '—'],
               ['科室', v.dept_name], ['记录时间', v.created_at], ['联系方式', p.phone || '—']];
        document.getElementById('patientInfo').innerHTML = fields.map(function (f) {
            return '<div style="min-width:140px"><div class="fs-12 text-muted">' + f[0] + '</div>' +
                '<div class="fw-600">' + f[1] + '</div></div>';
        }).join('');
    }

    /**
     * 渲染病历编辑区（WYSIWYG）
     */
    function renderEmrCard(d) {
        var r = d.record;
        var v = d.vitals || {};
        var tplBtn = '<button type="button" class="btn btn-outline btn-sm" id="tplBtn" onclick="Clinic.emr.openTemplates()">📋 病历模板</button>';
        var consciousness = ['清醒', '嗜睡', '意识模糊', '昏睡', '昏迷', '谵妄']
            .map(function (c) { return '<option value="' + c + '"' + (r.consciousness === c ? ' selected' : '') + '>' + c + '</option>'; })
            .join('');

        document.getElementById('emrCard').innerHTML =
            '<div class="card-title">' +
            '  <span>电子病历</span>' +
            '  <span>' + tplBtn + '</span>' +
            '</div>' +

            // 生命体征（5参数，与护士站共用接口）
            '<div class="form-group" style="background:var(--bg-soft);padding:12px;border-radius:8px">' +
            '  <div class="fs-13 fw-600 mb-8">生命体征（护士站填写后自动同步）</div>' +
            '  <div class="flex gap-8" style="flex-wrap:wrap">' +
            vitalInput('血压', 'vitalBP', (v.bp_systolic || '') + (v.bp_systolic ? '/' + (v.bp_diastolic || '') : ''), 'mmHg') +
            vitalInput('心率', 'vitalHR', v.heart_rate || '', '次/分') +
            vitalInput('脉搏', 'vitalPulse', v.pulse || '', '次/分') +
            vitalInput('血氧饱和度', 'vitalSpO2', v.spo2 || '', '%') +
            vitalInput('呼吸', 'vitalResp', v.respiration || '', '次/分') +
            '  </div>' +
            '  <button type="button" class="btn btn-outline btn-sm mt-8" onclick="saveVitals()">保存生命体征</button>' +
            '</div>' +

            '<div class="form-group">' +
            '  <label class="form-label">主诉 <span class="req">*</span></label>' +
            '  <div class="rich-editor" id="ccEditor" style="border:1px solid var(--border);border-radius:6px;padding:10px;min-height:44px"></div>' +
            '</div>' +
            '<div class="form-group">' +
            '  <label class="form-label">现病史 <span class="req">*</span></label>' +
            '  <div class="rich-editor" id="piEditor" style="border:1px solid var(--border);border-radius:6px;padding:10px;min-height:66px"></div>' +
            '</div>' +
            '<div class="form-row">' +
            '  <div class="form-group"><label class="form-label">既往史</label>' +
            '    <div class="rich-editor" id="phEditor" style="border:1px solid var(--border);border-radius:6px;padding:10px;min-height:44px"></div></div>' +
            '  <div class="form-group"><label class="form-label">过敏史</label>' +
            '    <div class="rich-editor" id="ahEditor" style="border:1px solid var(--border);border-radius:6px;padding:10px;min-height:44px"></div></div>' +
            '</div>' +
            '<div class="form-row">' +
            '  <div class="form-group"><label class="form-label">意识状态</label>' +
            '    <select class="select" id="consciousness"><option value="">请选择</option>' + consciousness + '</select></div>' +
            '  <div class="form-group"><label class="form-label">体格检查</label>' +
            '    <div class="rich-editor" id="peEditor" style="border:1px solid var(--border);border-radius:6px;padding:10px;min-height:44px"></div></div>' +
            '</div>' +
            '<div class="form-group">' +
            '  <label class="form-label">初步诊断（含 ICD10 编码） <span class="req">*</span></label>' +
            '  <div class="flex gap-8">' +
            '    <input type="text" class="input" id="diagInput" style="flex:1" placeholder="点击输入，支持疾病名称/ICD编码/拼音检索" autocomplete="off">' +
            '    <input type="text" class="input" id="diagCode" style="width:150px" placeholder="ICD10编码" readonly>' +
            '  </div>' +
            '</div>' +
            '<div class="form-row">' +
            '  <div class="form-group"><label style="display:flex;align-items:center;gap:6px">' +
            '    <input type="checkbox" id="isObs" ' + (r.is_observation == 1 ? 'checked' : '') + '> 留观</label></div>' +
            '  <div class="form-group"><label class="form-label">嘱托</label>' +
            '    <div class="rich-editor" id="advEditor" style="border:1px solid var(--border);border-radius:6px;padding:10px;min-height:44px"></div></div>' +
            '</div>';

        // 初始化编辑器
        Clinic.editor.create('#ccEditor', '请输入主诉…').set(r.chief_complaint);
        Clinic.editor.create('#piEditor', '请输入现病史…').set(r.present_illness);
        Clinic.editor.create('#phEditor', '请输入既往史…').set(r.past_history);
        Clinic.editor.create('#ahEditor', '请输入过敏史…').set(r.allergy_history);
        Clinic.editor.create('#peEditor', '请输入体格检查…').set(r.physical_exam);
        Clinic.editor.create('#advEditor', '请输入嘱托…').set(r.advice);

        // 初始化诊断联动
        initDiagnosis(r.initial_diagnosis, r.diagnosis_code);
    }

    /**
     * 生命体征输入框 HTML
     */
    function vitalInput(label, id, val, unit) {
        return '<div style="min-width:110px">' +
            '<div class="fs-12 text-muted">' + label + '</div>' +
            '<input type="text" class="input" id="' + id + '" value="' + (val || '') +
            '" placeholder="' + unit + '" style="padding:6px 8px;min-height:32px"></div>';
    }

    /**
     * 初始化诊断与 ICD10 联动
     */
    function initDiagnosis(diagName, diagCode) {
        var input = document.getElementById('diagInput');
        var codeInput = document.getElementById('diagCode');
        input.value = diagName || '';
        codeInput.value = diagCode || '';

        // 搜索下拉：选中后编码固定，删空文字则编码清空并重新显示下拉
        window.__diagSelector = Clinic.selector.bind(input, [], function (value, opt) {
            input.value = opt.label;
            codeInput.value = opt.value;
        }, { placeholder: '搜索诊断' });

        // 输入时动态搜索 ICD10（码/名称/拼音）
        var timer = null;
        input.addEventListener('input', function () {
            var kw = input.value.trim();
            if (kw === '') {
                codeInput.value = '';   // 删空则编码清空
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(function () {
                Clinic.get('/api/icd10?action=search&kw=' + encodeURIComponent(kw), null, {
                    onSuccess: function (j) {
                        var opts = j.data.list.map(function (x) {
                            return { label: x.diagnosis_name, value: x.diagnosis_code,
                                     sub: x.diagnosis_code + ' ' + x.pinyin };
                        });
                        window.__diagSelector.setOptions(opts);
                    },
                });
            }, 250);
        });
    }

    /**
     * 加载患者已开项目（病历处置区）
     */
    function loadOrders(visitId) {
        Clinic.get('/api/order?action=visit_orders&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var typeNames = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
                var statusMap = {
                    open: '<span class="badge badge-warning">待缴费</span>',
                    paid: '<span class="badge badge-primary">已缴费</span>',
                    registered: '<span class="badge badge-primary">已登记</span>',
                    in_progress: '<span class="badge badge-primary">执行中</span>',
                    done: '<span class="badge badge-success">已执行/已完成</span>',
                    dispensing: '<span class="badge badge-warning">发药中</span>',
                    dispensed: '<span class="badge badge-success">已发药</span>',
                    refunded: '<span class="badge badge-gray">已退费</span>',
                    cancelled: '<span class="badge badge-gray">已取消</span>',
                };
                var box = document.getElementById('orderList');
                if (!j.data.list.length) {
                    box.innerHTML = '<div class="text-muted fs-13">暂无开单</div>';
                    return;
                }
                box.innerHTML = j.data.list.map(function (o) {
                    var items = o.items.map(function (it) {
                        return '<div class="fs-13" style="padding:2px 0">· ' + it.item_name +
                            (it.quantity > 1 ? ' ×' + it.quantity : '') + '</div>';
                    }).join('');
                    var delBtn = o.status === 'open'
                        ? ' <button class="btn btn-outline btn-sm" style="padding:1px 8px" onclick="delOrder(' + o.id + ')">✕</button>'
                        : '';
                    return '<div style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px;cursor:pointer" ' +
                        'onclick="viewOrderFlow(' + o.id + ')">' +
                        '<div class="flex-between">' +
                        '  <span class="fw-600 fs-13">' + (typeNames[o.order_type] || o.order_type) + ' ' + o.order_no + '</span>' +
                        delBtn +
                        '</div>' +
                        items +
                        '<div class="mt-4">' + (statusMap[o.status] || o.status) + ' ' +
                        '  <span class="fs-12 text-muted">¥' + parseFloat(o.total_amount).toFixed(2) + '</span></div>' +
                        '</div>';
                }).join('');
            },
        });
    }

    /**
     * 保存病历
     * @param {boolean} finish 是否诊毕
     */
    function save(finish) {
        var chief = document.getElementById('ccEditor').innerText.trim();
        var present = document.getElementById('piEditor').innerText.trim();
        var diag = document.getElementById('diagInput').value.trim();
        if (!chief) { Clinic.toast.warning('请填写主诉（必填）'); return; }
        if (!present) { Clinic.toast.warning('请填写现病史（必填）'); return; }
        if (!diag) { Clinic.toast.warning('请填写初步诊断（必填）'); return; }

        var data = {
            action: 'save',
            visit_id: document.getElementById('visitId').value,
            chief_complaint: document.getElementById('ccEditor').innerHTML,
            present_illness: document.getElementById('piEditor').innerHTML,
            past_history: document.getElementById('phEditor').innerHTML,
            allergy_history: document.getElementById('ahEditor').innerHTML,
            physical_exam: document.getElementById('peEditor').innerHTML,
            consciousness: document.getElementById('consciousness').value,
            initial_diagnosis: diag,
            diagnosis_code: document.getElementById('diagCode').value,
            is_observation: document.getElementById('isObs').checked ? 1 : 0,
            advice: document.getElementById('advEditor').innerHTML,
        };
        if (finish) data.finish = 1;
        Clinic.ajax('/api/record', data, {
            loading: true,
            onSuccess: function (j) {
                document.getElementById('saveStatus').textContent = '已保存 ' + new Date().toLocaleTimeString();
                Clinic.toast.success(j.msg);
                if (finish) {
                    setTimeout(function () { window.location.href = '/doctor/dashboard'; }, 900);
                }
            },
        });
    }

    /**
     * 打开病历模板列表
     */
    function openTemplates() {
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/template?action=list&dept_id=' +
            (DATA ? DATA.visit.current_dept_id : 0), null, {
            onSuccess: function (j) {
                if (!j.data.list.length) {
                    Clinic.toast.info('暂无可用的病历模板（可先创建模板并经管理员审核）');
                    return;
                }
                var scopeNames = { personal: '个人', department: '全科', hospital: '全院' };
                var html = j.data.list.map(function (t) {
                    return '<div class="dd-item" style="display:flex;justify-content:space-between;align-items:center" ' +
                        'onclick="Clinic.emr.applyTemplateById(' + t.id + ')">' +
                        '<span>' + t.name + '</span>' +
                        '<span class="badge badge-gray">' + (scopeNames[t.scope] || t.scope) + '</span></div>';
                }).join('') || '<div class="dd-empty">暂无模板</div>';
                Clinic.modal.open(html, { title: '选择病历模板', size: 'modal-sm' });
            },
        });
    }

    /**
     * 按 ID 应用模板
     */
    function applyTemplateById(tplId) {
        Clinic.get('/api/template?action=list&dept_id=0', null, {
            onSuccess: function (j) {
                var t = j.data.list.find(function (x) { return x.id == tplId; });
                if (t) {
                    applyTemplate(t);
                    Clinic.modal.close();
                    Clinic.toast.success('已应用模板，可在模板基础上修改');
                }
            },
        });
    }

    /**
     * 应用模板内容到编辑器
     */
    function applyTemplate(t) {
        var c = {};
        try { c = JSON.parse(t.content || '{}'); } catch (e) { c = {}; }
        if (c.chief_complaint) document.getElementById('ccEditor').innerHTML = c.chief_complaint;
        if (c.present_illness) document.getElementById('piEditor').innerHTML = c.present_illness;
        if (c.past_history) document.getElementById('phEditor').innerHTML = c.past_history;
        if (c.allergy_history) document.getElementById('ahEditor').innerHTML = c.allergy_history;
    }

    /**
     * 绑定病历模板按钮（我的模板管理）
     */
    function bindTemplateBtn() {
        var btn = document.getElementById('tplBtn');
        if (!btn) return;
        btn.onclick = null;
        btn.addEventListener('click', function () { openTemplates(); });
    }

    /**
     * 打开转科弹窗
     */
    function openTransfer() {
        var visitId = document.getElementById('visitId').value;
        var curDept = DATA ? DATA.visit.current_dept_id : 0;
        Clinic.get('/api/transfer?action=targets&dept_id=' + curDept, null, {
            onSuccess: function (j) {
                var opts = j.data.list.map(function (d) {
                    return '<option value="' + d.id + '">' + d.name + '</option>';
                }).join('');
                Clinic.modal.open(
                    '<div class="form-group"><label class="form-label">转往科室</label>' +
                    '<select class="select" id="trgDept">' + opts + '</select></div>' +
                    '<div class="fs-13 text-muted">转科后患者就诊序号、首次挂号科室等信息均保持不变。</div>',
                    {
                        title: '转科',
                        size: 'modal-sm',
                        buttons: [
                            { text: '取消', cls: 'btn-outline' },
                            {
                                text: '确认转科', cls: 'btn-primary', autoClose: false,
                                onClick: function () {
                                    Clinic.ajax('/api/transfer', {
                                        action: 'do', visit_id: visitId,
                                        target_dept: document.getElementById('trgDept').value,
                                    }, {
                                        onSuccess: function (j) {
                                            Clinic.toast.success(j.msg);
                                            Clinic.modal.close();
                                            setTimeout(function () { location.href = '/doctor/dashboard'; }, 900);
                                        },
                                    });
                                },
                            },
                        ],
                    }
                );
            },
        });
    }

    /**
     * 开具诊断证明（单次就诊仅一次）
     */
    function openCertificate() {
        var visitId = document.getElementById('visitId').value;
        if (DATA && DATA.has_certificate) {
            Clinic.toast.warning('本次就诊已开具过诊断证明，不可重复开具');
            return;
        }
        var cc = document.getElementById('ccEditor').innerText.trim();
        var pi = document.getElementById('piEditor').innerText.trim();
        var diag = document.getElementById('diagInput').value.trim();
        if (!cc || !pi || !diag) {
            Clinic.toast.warning('请先完善病历（主诉、现病史、诊断）');
            return;
        }
        Clinic.modal.open(
            '<div class="fs-13 text-muted mb-8">将自动引用病历中的主诉、现病史与初步诊断，医生建议请手动填写。</div>' +
            '<div class="form-group"><label class="form-label">医生建议</label>' +
            '<textarea class="textarea" id="certContent" rows="3" placeholder="如：建议休息3天，清淡饮食，不适随诊"></textarea></div>',
            {
                title: '开具诊断证明',
                size: 'modal-sm',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    {
                        text: '开具并打印', cls: 'btn-success', autoClose: false,
                        onClick: function () {
                            var content = document.getElementById('certContent').value.trim();
                            if (!content) { Clinic.toast.warning('请填写医生建议'); return; }
                            Clinic.ajax('/api/record', {
                                action: 'certificate', visit_id: visitId, content: content,
                            }, {
                                onSuccess: function () {
                                    Clinic.toast.success('诊断证明已开具');
                                    Clinic.modal.close();
                                    Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null);
                                    loadData(visitId);
                                },
                            });
                        },
                    },
                ],
            }
        );
    }

    /**
     * 打印电子病历
     */
    function printRecord() {
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var v = j.data.visit, p = j.data.patient, r = j.data.record;
                var hosp = document.body.getAttribute('data-hosp') || '';
                var hosp2 = document.body.getAttribute('data-hosp2') || '';
                var title = v.dept_type === 'emergency' ? '急诊电子病历' : '门诊电子病历';
                var headFields = v.dept_type === 'emergency'
                    ? [['姓名', v.name], ['性别', v.gender], ['出生日期', p.birth_date], ['年龄', v.age + '岁'],
                       ['患者ID', p.patient_id], ['就诊科室', v.dept_name], ['就诊时间', v.created_at]]
                    : [['姓名', v.name], ['性别', v.gender], ['年龄', v.age + '岁'], ['患者ID', p.patient_id],
                       ['证件号码', p.id_card], ['出生日期', p.birth_date], ['民族', p.nation || '—'],
                       ['职业', p.occupation || '—'], ['婚姻', p.marital || '—'], ['初复诊', '—'],
                       ['科室', v.dept_name], ['记录时间', v.created_at], ['联系方式', p.phone || '—']];
                var info = headFields.map(function (f) {
                    return '<span><strong>' + f[0] + '</strong>：' + f[1] + '</span>';
                }).join('');
                var html =
                    '<div class="print-hosp">' + hosp + '</div>' +
                    (hosp2 ? '<div class="print-sub">' + hosp2 + '</div>' : '') +
                    '<div class="print-title-line">' + title + '</div>' +
                    '<div class="print-info">' + info + '</div>' +
                    '<div class="print-line"></div>' +
                    section('主诉', r.chief_complaint) +
                    section('现病史', r.present_illness) +
                    section('既往史', r.past_history) +
                    section('过敏史', r.allergy_history) +
                    section('生命体征', vitalsText(j.data.vitals)) +
                    section('意识状态', r.consciousness) +
                    section('体格检查', r.physical_exam) +
                    section('初步诊断', r.initial_diagnosis + (r.diagnosis_code ? '（' + r.diagnosis_code + '）' : '')) +
                    section('留观', r.is_observation == 1 ? '是' : '否') +
                    section('嘱托', r.advice) +
                    '<div class="print-footer"><span>医生：' + r.doctor_name + '</span>' +
                    '<span>打印时间：' + new Date().toLocaleString() + '</span></div>';
                Clinic.print.open(html, title);
            },
        });
    }

    /**
     * 病历段落
     */
    function section(label, body) {
        return '<div class="record-section"><div class="sec-label">' + label + '</div>' +
            '<div class="sec-body">' + (body || '') + '</div></div>';
    }

    /**
     * 生命体征文本
     */
    function vitalsText(v) {
        if (!v) return '';
        var parts = [];
        if (v.bp_systolic) parts.push('血压 ' + v.bp_systolic + '/' + v.bp_diastolic + 'mmHg');
        if (v.heart_rate) parts.push('心率 ' + v.heart_rate + '次/分');
        if (v.pulse) parts.push('脉搏 ' + v.pulse + '次/分');
        if (v.spo2) parts.push('血氧 ' + v.spo2 + '%');
        if (v.respiration) parts.push('呼吸 ' + v.respiration + '次/分');
        return parts.join('；');
    }

    return {
        init: init,
        save: save,
        openTemplates: openTemplates,
        applyTemplateById: applyTemplateById,
        openCertificate: openCertificate,
        printRecord: printRecord,
        loadOrders: loadOrders,
    };
})();

/* 页面就绪后初始化 */
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('visitId')) {
        Clinic.emr.init();
    }
});

/* 全局：转科 */
function openTransfer() {
    Clinic.emr.openTransfer();
}

/* 全局：删除开单 */
function delOrder(orderId) {
    Clinic.modal.confirm('删除该开单？（仅未缴费可删，处方删除后库存恢复）', function () {
        Clinic.ajax('/api/order', { action: 'delete', order_id: orderId }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                Clinic.emr.loadOrders(document.getElementById('visitId').value);
            },
        });
    });
}

/* 全局：查看开单流程（纵向流程图） */
function viewOrderFlow(orderId) {
    Clinic.get('/api/order?action=visit_orders&visit_id=' + document.getElementById('visitId').value, null, {
        onSuccess: function (j) {
            var o = j.data.list.find(function (x) { return x.id === orderId; });
            if (!o) return;
            var typeNames = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
            var steps = [
                { k: 'open', label: '开单' },
                { k: 'paid', label: '缴费' },
                { k: 'registered', label: '登记' },
                { k: 'done', label: o.order_type === 'prescription' ? '药房发药' : (o.order_type === 'procedure' ? '处置执行' : '检查/检验') },
            ];
            // 处方流程：paid→dispensing→dispensed
            if (o.order_type === 'prescription') {
                steps = [
                    { k: 'open', label: '开单' },
                    { k: 'paid', label: '缴费' },
                    { k: 'dispensing', label: '药房处理' },
                    { k: 'dispensed', label: '发药完成' },
                ];
            }
            // 流程状态判定
            var curIdx = 0;
            if (o.status === 'done' || o.status === 'dispensed') curIdx = 3;
            else if (o.status === 'registered' || o.status === 'dispensing' || o.status === 'in_progress') curIdx = 2;
            else if (o.status === 'paid') curIdx = 1;
            else if (o.status === 'refunded' || o.status === 'cancelled') curIdx = -1;

            var flow = steps.map(function (s, i) {
                var cls = (curIdx >= 0 && i <= curIdx) ? 'var(--success)' : 'var(--border)';
                return '<div class="flex gap-8" style="align-items:center">' +
                    '<div style="width:26px;height:26px;border-radius:50%;background:' + cls + ';' +
                    'display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex-shrink:0">' +
                    (i + 1) + '</div>' +
                    '<div class="fs-13" style="color:' + (curIdx >= 0 && i <= curIdx ? 'var(--text)' : 'var(--text-muted)') + '">' +
                    s.label + '</div></div>';
            }).join('<div style="width:2px;height:18px;background:var(--border);margin-left:12px"></div>');

            var items = o.items.map(function (it) {
                return '<div class="fs-13" style="padding:3px 0">· ' + it.item_name +
                    (it.quantity > 1 ? ' ×' + it.quantity : '') + '</div>';
            }).join('');

            Clinic.modal.open(
                '<div class="flex gap-16">' +
                '  <div style="flex:1">' +
                '    <div class="fw-600 mb-8">' + (typeNames[o.order_type] || '') + '：' + o.order_no + '</div>' +
                '    ' + items +
                '    <div class="fs-13 text-muted mt-8">金额：¥' + parseFloat(o.total_amount).toFixed(2) + '</div>' +
                '    <div class="fs-13 text-muted">开单医生：' + (o.doctor_name || '—') + ' ｜ ' + o.created_at + '</div>' +
                (o.done_by ? '<div class="fs-13 text-success mt-4">执行人：' + o.done_by + '</div>' : '') +
                '  </div>' +
                '  <div style="width:160px;border-left:1px solid var(--border);padding-left:16px">' +
                '    <div class="fw-600 mb-8 fs-13">流程进度</div>' + flow + '</div>' +
                '</div>',
                { title: '开单详情', size: 'modal-lg' }
            );
        },
    });
}

/* 全局：保存生命体征 */
function saveVitals() {
    var parseBP = function (s) {
        var parts = (s || '').split('/');
        return { sys: parseInt(parts[0], 10) || 0, dia: parseInt(parts[1], 10) || 0 };
    };
    var bp = parseBP(document.getElementById('vitalBP').value);
    Clinic.ajax('/api/record', {
        action: 'save_vitals',
        visit_id: document.getElementById('visitId').value,
        bp_systolic: bp.sys,
        bp_diastolic: bp.dia,
        heart_rate: document.getElementById('vitalHR').value,
        pulse: document.getElementById('vitalPulse').value,
        spo2: document.getElementById('vitalSpO2').value,
        respiration: document.getElementById('vitalResp').value,
    }, {
        onSuccess: function (j) { Clinic.toast.success(j.msg); },
    });
}
