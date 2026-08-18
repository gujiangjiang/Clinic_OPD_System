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
    /** 已开项目缓存（病历正文 辅助检查/门诊处置 所见即所得展示用） */
    var ORDERS = [];

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
        // 诊断证明入口：已诊毕患者 - 已开具可点击查看/打印，未开具可补开
        var certHtml = '';
        if (d.visit && d.visit.status === 'finished') {
            certHtml = d.has_certificate
                ? ' ｜ <a href="javascript:void(0)" onclick="Clinic.emr.viewCertificate()" class="text-success fw-600">已开诊断证明（点击查看/打印）</a>'
                : ' ｜ <a href="javascript:void(0)" onclick="Clinic.emr.openCertificate()" class="fw-600">补开诊断证明</a>';
        } else if (d.has_certificate) {
            certHtml = ' ｜ <span class="text-success">已开诊断证明</span>';
        }
        // 患者一栏只保留基本信息（就诊医生右上角已有展示，记录时间在病历文档左下角，均不在此重复）
        // 条形码位于病历文档页头右上角（与打印预览一致），不在此处显示
        // 修改入口：点击上方头像或患者姓名弹出「修改患者信息」弹窗（病历文档内的患者信息区保持纯净）
        var editModal = "Clinic.patient.editModal('" + p.patient_id + "')";
        document.getElementById('emrHeader').innerHTML =
            '<div class="card" style="background:var(--bg-card)">' +
            '<div class="flex-between">' +
            '  <div class="flex gap-12" style="align-items:center">' +
            '    <div class="emr-patient-avatar" onclick="' + editModal + '" title="点击修改患者信息（除姓名/性别/身份证外均可修改）">👤</div>' +
            '    <div>' +
            '      <div class="fs-18 fw-700 emr-patient-name" onclick="' + editModal + '" title="点击修改患者信息">' + v.name +
            '        <span class="badge badge-gray" style="margin-left:8px">' + v.gender + ' / ' + v.age + '岁</span>' +
            '        <span class="badge ' + (v.dept_type === 'emergency' ? 'badge-danger' : 'badge-primary') +
            '" style="margin-left:4px">' + (v.dept_type === 'emergency' ? '急诊' : '门诊') + '</span>' +
            '      </div>' +
            '      <div class="text-muted fs-13">患者ID：' + p.patient_id + ' ｜ 流水号：' + v.visit_no +
            ' ｜ ' + v.dept_name + ' 第' + String(v.visit_seq).padStart(3, '0') + '号' +
            certHtml + '</div>' +
            '    </div>' +
            '  </div>' +
            '</div></div>';
    }

    /**
     * 渲染病历编辑区（WYSIWYG）
     */
    function renderEmrCard(d) {
        var r = d.record;
        var v = d.vitals || {};
        var vv = d.visit || {};   // 就诊信息（注意：v 为生命体征，患者信息网格必须用 vv）
        var p = d.patient || {};
        var tplBtn = '<button type="button" class="btn btn-outline btn-sm" id="tplBtn" onclick="Clinic.emr.openTemplates()">📋 病历模板</button>';
        var consciousness = ['清醒', '嗜睡', '意识模糊', '昏睡', '昏迷', '谵妄']
            .map(function (c) { return '<option value="' + c + '"' + (r.consciousness === c ? ' selected' : '') + '>' + c + '</option>'; })
            .join('');

        // 医院抬头与标题（与打印版式一致，所见即所得）
        var hosp = document.body.getAttribute('data-hosp') || '';
        var hosp2 = document.body.getAttribute('data-hosp2') || '';
        var docTitle = (d.visit && d.visit.dept_type === 'emergency') ? '急诊电子病历' : '门诊电子病历';

        // 初复诊下拉（默认初诊）
        var vt = r.visit_type || '初诊';
        var vtSelect = '<select class="doc-cell-select" id="visitType">' +
            '<option value="初诊"' + (vt === '初诊' ? ' selected' : '') + '>初诊</option>' +
            '<option value="复诊"' + (vt === '复诊' ? ' selected' : '') + '>复诊</option></select>';

        // 患者信息：门诊为两栏网格；急诊为两行流式排版（第一行 姓名/性别/出生日期/年龄，
        // 第二行 患者ID/就诊科室/就诊时间），编辑页与打印页完全一致（所见即所得）
        var cellHtml = function (f) {
            // 初复诊为下拉框（可编辑），其余为纯文本展示
            var isSelect = (typeof f[1] === 'string' && f[1].indexOf('<select') === 0);
            if (isSelect) {
                return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' + f[1] + '</div>';
            }
            return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' +
                '<span class="doc-cell-value">' + f[1] + '</span></div>';
        };
        // 患者信息区：仅展示（不再整块可点击；修改入口已移到上方患者姓名/头像）
        var gridWrap;
        if (vv.dept_type === 'emergency') {
            var lines = [
                [['姓名', vv.name], ['性别', vv.gender], ['出生日期', p.birth_date], ['年龄', vv.age + '岁']],
                [['患者ID', p.patient_id], ['就诊科室', vv.dept_name], ['就诊时间', vv.created_at]],
            ];
            var lineHtml = lines.map(function (row) {
                return '<div class="doc-line-row">' + row.map(cellHtml).join('') + '</div>';
            }).join('');
            gridWrap = '<div class="doc-patient-lines">' + lineHtml + '</div>';
        } else {
            var fields = [['姓名', vv.name], ['性别', vv.gender], ['年龄', vv.age + '岁'], ['患者ID', p.patient_id],
               ['证件号码', p.id_card], ['出生日期', p.birth_date], ['民族', p.nation || '—'],
               ['职业', p.occupation || '—'], ['婚姻', p.marital || '—'], ['初复诊', vtSelect],
               ['科室', vv.dept_name], ['联系方式', p.phone || '—']];
            gridWrap = '<div class="doc-patient-grid">' + fields.map(cellHtml).join('') + '</div>';
        }

        // 留观下拉（是/否，与意识状态下拉样式一致）
        var obsOpts = '<option value="0"' + (r.is_observation == 1 ? '' : ' selected') + '>否</option>' +
                      '<option value="1"' + (r.is_observation == 1 ? ' selected' : '') + '>是</option>';

        // 病历文档页头右上角条形码（与挂号凭条/打印预览一致：门诊号 flow_no，Code 128）
        var bcSrc = document.getElementById('emrBarcodeSrc');
        var bcHtml = (bcSrc && bcSrc.innerHTML)
            ? '<div class="doc-barcode">' + bcSrc.innerHTML +
              '<div class="doc-barcode-text">' + vv.visit_no + '</div></div>'
            : '';

        document.getElementById('emrCard').innerHTML =
            '<div class="emr-doc">' +
            bcHtml +
            (hosp ? '<div class="doc-hosp">' + hosp + '</div>' : '') +
            (hosp2 ? '<div class="doc-sub">' + hosp2 + '</div>' : '') +
            // 病历模板按钮：文档页头左上角（顶部与医院名称齐平、左侧与左边距齐平）
            '<span class="doc-tpl">' + tplBtn + '</span>' +
            '<div class="doc-title-bar">' +
            '  <span class="doc-title">' + docTitle + '</span>' +
            '</div>' +
            gridWrap +
            '<div class="doc-line"></div>' +
            '<div class="doc-body">' +

            // 病历正文：所有小节纵向排列（每节一行），输入框接在标题后方：主诉：XXXX（所见即所得，与打印版式一致）
            '<div class="doc-sec"><span class="doc-sec-label">主诉<span class="req">*</span></span>' +
            '  <div class="rich-editor" id="ccEditor"></div></div>' +
            '<div class="doc-sec"><span class="doc-sec-label">现病史<span class="req">*</span></span>' +
            '  <div class="rich-editor" id="piEditor"></div></div>' +
            '<div class="doc-sec"><span class="doc-sec-label">既往史</span>' +
            '  <div class="rich-editor" id="phEditor"></div></div>' +
            '<div class="doc-sec"><span class="doc-sec-label">过敏史</span>' +
            '  <div class="rich-editor" id="ahEditor"></div></div>' +

            // 生命体征：位于过敏史下方、意识状态上方；点击弹出编辑（与护士站双向同步），无多余提示/按钮
            '<div class="doc-sec doc-sec-vital" onclick="Clinic.emr.openVitals()" title="点击编辑生命体征">' +
            '  <span class="doc-sec-label">生命体征</span>' +
            '  <span class="doc-sec-body" id="vitalDisplay">' + vitalDisplayText(v) + '</span></div>' +

            '<div class="doc-sec"><span class="doc-sec-label">意识状态</span>' +
            '  <select class="select" id="consciousness"><option value="">请选择</option>' + consciousness + '</select></div>' +
            '<div class="doc-sec"><span class="doc-sec-label">体格检查</span>' +
            '  <div class="rich-editor" id="peEditor"></div></div>' +

            '<div class="doc-sec"><span class="doc-sec-label">初步诊断<span class="req">*</span></span>' +
            '  <div class="flex gap-8" style="flex:1;min-width:0">' +
            '    <input type="text" class="input" id="diagInput" style="flex:1" placeholder="点击输入，支持疾病名称/ICD编码/拼音检索" autocomplete="off">' +
            '    <input type="text" class="input" id="diagCode" style="width:130px" placeholder="ICD10编码" readonly>' +
            '  </div></div>' +

            // 已开项目所见即所得：辅助检查（检验/检查）+ 门诊处置（处置/处方），与打印版式一致，
            // 点击项目弹出流程弹窗；内容由 loadOrders 拉取后填充
            '<div class="doc-sec"><span class="doc-sec-label">辅助检查</span>' +
            '  <div class="doc-sec-body" id="docAuxExam">—</div></div>' +
            '<div class="doc-sec"><span class="doc-sec-label">门诊处置</span>' +
            '  <div class="doc-sec-body" id="docClinicTreat">—</div></div>' +

            '<div class="doc-sec"><span class="doc-sec-label">留观</span>' +
            '  <select class="select" id="isObs">' + obsOpts + '</select></div>' +
            '<div class="doc-sec"><span class="doc-sec-label">嘱托</span>' +
            '  <div class="rich-editor" id="advEditor"></div></div>' +

            // 病历正文右下角医生签名（页脚仍保留 医生：医生（工号 0003）｜职称，互不影响）
            '<div class="doc-body-sign">医生：' + r.doctor_name + '</div>' +
            '</div>' +
            // 页脚：左下角记录时间（未保存时隐藏，保存成功后显示），右下角医生签名
            '<div class="doc-footer">' +
            '  <span class="doc-rec-time" id="docRecTime" style="' + (r.updated_at ? '' : 'display:none') + '">记录时间：' + (r.updated_at || '') + '</span>' +
            '  <span class="doc-doctor">医生：' + r.doctor_name +
            (r.doctor_emp ? '（工号 ' + r.doctor_emp + '）' : '') +
            (r.doctor_title ? ' ｜ ' + r.doctor_title : '') + '</span>' +
            '</div>' +
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

        // 诊毕：整份病历置为只读（所有输入框禁用 + 编辑器不可编辑 + 写操作按钮隐藏）
        if (d.visit && d.visit.status === 'finished') {
            setReadonlyUI();
        }
    }

    /**
     * 诊毕只读：禁用病历所有输入控件，避免误解为可继续编辑
     */
    function setReadonlyUI() {
        var card = document.getElementById('emrCard');
        if (card) {
            card.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = true;
            });
            card.querySelectorAll('.rich-editor').forEach(function (el) {
                el.contentEditable = 'false';
                el.classList.add('readonly');
            });
            // 关闭诊断搜索下拉
            if (window.__diagSelector) window.__diagSelector.close();
            // 隐藏病历模板按钮（写操作）
            var tpl = document.getElementById('tplBtn');
            if (tpl) tpl.style.display = 'none';
        }
        // 隐藏工具栏写操作按钮（开单/保存/诊毕/转科/诊断证明），保留查看类（打印/历史/患者信息）
        document.querySelectorAll('.emr-write').forEach(function (b) { b.style.display = 'none'; });
        var status = document.getElementById('saveStatus');
        if (status) {
            status.textContent = '该患者已诊毕，病历为只读状态';
            status.style.color = 'var(--text-muted)';
        }
    }

    /**
     * 生命体征紧凑显示文本：全部为空显示 -，有数据则只展示已有项
     */
    function vitalDisplayText(v) {
        v = v || {};
        var parts = [];
        if (v.bp_systolic) parts.push('血压 ' + v.bp_systolic + '/' + (v.bp_diastolic || '—') + 'mmHg');
        if (v.heart_rate) parts.push('心率 ' + v.heart_rate + '次/分');
        if (v.pulse) parts.push('脉搏 ' + v.pulse + '次/分');
        if (v.spo2) parts.push('血氧 ' + v.spo2 + '%');
        if (v.respiration) parts.push('呼吸 ' + v.respiration + '次/分');
        return parts.length ? parts.join(' ｜ ') : '—';
    }

    /**
     * 打开生命体征编辑弹窗（6 个输入框：收缩压/舒张压/心率/脉搏/血氧/呼吸，与护士站共用接口）
     */
    function openVitals() {
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var v = j.data.vitals || {};
                var val = function (x) { return x || ''; };
                Clinic.modal.open(
                    '<div class="form-row">' +
                    '<div class="form-group"><label class="form-label">收缩压（mmHg）</label>' +
                    '<input class="input" id="vSys" type="number" min="0" value="' + val(v.bp_systolic) + '"></div>' +
                    '<div class="form-group"><label class="form-label">舒张压（mmHg）</label>' +
                    '<input class="input" id="vDia" type="number" min="0" value="' + val(v.bp_diastolic) + '"></div></div>' +
                    '<div class="form-row">' +
                    '<div class="form-group"><label class="form-label">心率（次/分）</label>' +
                    '<input class="input" id="vHR" value="' + val(v.heart_rate) + '"></div>' +
                    '<div class="form-group"><label class="form-label">脉搏（次/分）</label>' +
                    '<input class="input" id="vPulse" value="' + val(v.pulse) + '"></div></div>' +
                    '<div class="form-row">' +
                    '<div class="form-group"><label class="form-label">血氧饱和度（%）</label>' +
                    '<input class="input" id="vSpO2" value="' + val(v.spo2) + '"></div>' +
                    '<div class="form-group"><label class="form-label">呼吸（次/分）</label>' +
                    '<input class="input" id="vResp" value="' + val(v.respiration) + '"></div></div>' +
                    '<div class="fs-12 text-muted">保存后护士站将同步显示。</div>',
                    {
                        title: '生命体征编辑',
                        size: 'modal-sm',
                        buttons: [
                            { text: '取消', cls: 'btn-outline' },
                            {
                                text: '保存', cls: 'btn-primary', autoClose: false,
                                onClick: function () {
                                    var data = {
                                        action: 'save_vitals',
                                        visit_id: visitId,
                                        bp_systolic: parseInt(document.getElementById('vSys').value, 10) || 0,
                                        bp_diastolic: parseInt(document.getElementById('vDia').value, 10) || 0,
                                        heart_rate: document.getElementById('vHR').value.trim(),
                                        pulse: document.getElementById('vPulse').value.trim(),
                                        spo2: document.getElementById('vSpO2').value.trim(),
                                        respiration: document.getElementById('vResp').value.trim(),
                                    };
                                    Clinic.ajax('/api/record', data, {
                                        onSuccess: function (json) {
                                            Clinic.toast.success(json.msg);
                                            Clinic.modal.close();
                                            refreshVitalDisplay();
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
     * 刷新生命体征紧凑显示（保存后 / 护士站同步后调用）
     */
    function refreshVitalDisplay() {
        var el = document.getElementById('vitalDisplay');
        if (!el) return;
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var v = j.data.vitals || {};
                if (DATA) DATA.vitals = v;
                el.textContent = vitalDisplayText(v);
            },
        });
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

        // 输入时动态搜索 ICD10（码/名称/拼音，模糊搜索）
        // 请求序号：只采纳最后一次请求的结果，丢弃过期响应，
        // 避免快速输入时旧关键字（如「健」）的响应后到覆盖新关键字（如「健康」）的结果。
        var timer = null;
        var seq = 0;
        input.addEventListener('input', function () {
            var kw = input.value.trim();
            if (kw === '') {
                codeInput.value = '';   // 删空则编码清空
                seq++;                  // 使在途请求全部失效
                if (window.__diagSelector) window.__diagSelector.close();
                return;
            }
            clearTimeout(timer);
            var mySeq = ++seq;
            timer = setTimeout(function () {
                Clinic.get('/api/icd10?action=search&kw=' + encodeURIComponent(kw), null, {
                    onSuccess: function (j) {
                        if (mySeq !== seq) return;   // 过期响应，丢弃
                        var opts = j.data.list.map(function (x) {
                            return { label: x.diagnosis_name, value: x.diagnosis_code,
                                     sub: x.diagnosis_code + ' ' + x.pinyin };
                        });
                        // setOptions 会在面板打开时立即重绘，搜索结果即时显示
                        window.__diagSelector.setOptions(opts);
                    },
                });
            }, 250);
        });
    }

    /**
     * 渲染病历正文 辅助检查 / 门诊处置（所见即所得，与打印版式一致）
     * 辅助检查：检验+检查项目，仅显示名称，点击弹出流程弹窗
     * 门诊处置：处置项目不换行显示名称+数量；处方每行一个药品（名称/剂量/用法/途径/数量）
     */
    function renderDocOrders(list) {
        var auxExam = document.getElementById('docAuxExam');
        var clinicTreat = document.getElementById('docClinicTreat');
        if (!auxExam && !clinicTreat) return;
        var chip = function (orderId, text) {
            return '<span class="doc-order-chip" onclick="viewOrderFlow(' + orderId + ')" title="点击查看流程">' + text + '</span>';
        };
        var aux = [];
        var proc = [];
        var rxs = [];
        list.forEach(function (o) {
            // 已退费/已取消的开单不再计入病历内容
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            var oid = o.id;
            o.items.forEach(function (it) {
                if (o.order_type === 'lab' || o.order_type === 'imaging') {
                    aux.push(chip(oid, it.item_name));
                } else if (o.order_type === 'procedure') {
                    proc.push(chip(oid, it.item_name + '×' + it.quantity));
                } else if (o.order_type === 'prescription') {
                    // 处方直显：名称　剂量　用法　途径　×数量（不加提示词，简洁直观）
                    var parts = [];
                    if (it.single_dose) parts.push(it.single_dose);
                    if (it.frequency_name) parts.push(it.frequency_name);
                    if (it.route_name) parts.push(it.route_name);
                    rxs.push('<div class="doc-rx-line" onclick="viewOrderFlow(' + oid + ')" title="点击查看流程">' +
                        it.item_name + (parts.length ? '　' + parts.join('　') : '') + '　×' + it.quantity + '</div>');
                }
            });
        });
        if (auxExam) auxExam.innerHTML = aux.length ? aux.join(' ') : '—';
        if (clinicTreat) {
            var inner = '';
            if (proc.length) inner += '<div class="doc-treat-proc">' + proc.join(' ') + '</div>';
            if (rxs.length) inner += rxs.join('');
            clinicTreat.innerHTML = inner || '—';
        }
    }

    /**
     * 加载患者已开项目（病历处置区 + 病历正文所见即所得区）
     */
    function loadOrders(visitId) {
        Clinic.get('/api/order?action=visit_orders&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                ORDERS = j.data.list || [];
                renderDocOrders(ORDERS);
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
                    // 未缴费或已退费的处方/开单可删除（退费后可删除并恢复库存）
                    var canDel = (o.status === 'open' || o.status === 'refunded');
                    // stopPropagation：阻止事件冒泡到卡片 onclick（viewOrderFlow），
                    // 否则会同时弹出开单详情弹窗与删除确认弹窗，删除确认被覆盖
                    var delBtn = canDel
                        ? ' <button class="btn btn-outline btn-sm" style="padding:1px 8px" ' +
                          'onclick="event.stopPropagation();delOrder(' + o.id + ')">✕</button>'
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
     * 当前时间 YYYY-MM-DD HH:mm:ss（用于记录时间展示）
     */
    function fmtDateTime() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
            ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
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
            is_observation: document.getElementById('isObs').value === '1' ? 1 : 0,
            visit_type: document.getElementById('visitType') ? document.getElementById('visitType').value : '初诊',
            advice: document.getElementById('advEditor').innerHTML,
        };
        if (finish) data.finish = 1;
        Clinic.ajax('/api/record', data, {
            loading: true,
            onSuccess: function (j) {
                document.getElementById('saveStatus').textContent = '已保存 ' + new Date().toLocaleTimeString();
                // 同步本地缓存：保存成功后无需刷新页面，开检验/检查/处置/处方与打印病历立即生效
                if (DATA) {
                    DATA.record.chief_complaint = data.chief_complaint;
                    DATA.record.present_illness = data.present_illness;
                    DATA.record.past_history = data.past_history;
                    DATA.record.allergy_history = data.allergy_history;
                    DATA.record.physical_exam = data.physical_exam;
                    DATA.record.consciousness = data.consciousness;
                    DATA.record.initial_diagnosis = diag;
                    DATA.record.diagnosis_code = data.diagnosis_code;
                    DATA.record.is_observation = data.is_observation;
                    DATA.record.visit_type = data.visit_type;
                    DATA.record.advice = data.advice;
                    DATA.record.status = finish ? 'done' : 'draft';
                    var now = fmtDateTime();
                    DATA.record.updated_at = now;
                    if (!DATA.record.created_at) DATA.record.created_at = now;
                    var rt = document.getElementById('docRecTime');
                    if (rt) {
                        rt.textContent = '记录时间：' + now;
                        rt.style.display = '';
                    }
                }
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
     * 查看已开具的诊断证明（弹出打印预览，可再次打印）
     */
    function viewCertificate() {
        var visitId = document.getElementById('visitId').value;
        Clinic.print.load('/api/print?action=certificate&visit_id=' + visitId, null);
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
     * 病历是否已完善并保存（主诉/现病史/初步诊断均为必填，任一缺失视为未完善）
     * 开检验/检查/处置/处方与打印病历的前置条件（前端拦截，后端亦有同样校验）
     * @returns {boolean}
     */
    function isRecordComplete() {
        if (!DATA || !DATA.record) return false;
        var r = DATA.record;
        var text = function (html) {
            var t = document.createElement('div');
            t.innerHTML = html || '';
            return t.textContent.trim();
        };
        return !!(text(r.chief_complaint) && text(r.present_illness) && (r.initial_diagnosis || '').trim());
    }

    /**
     * 打印电子病历
     */
    function printRecord() {
        // 前置条件：病历已完善并保存
        if (!isRecordComplete()) {
            Clinic.toast.warning('请先在病历中完善主诉、现病史与初步诊断并保存，再打印病历');
            return;
        }
        var visitId = document.getElementById('visitId').value;
        // 直接使用统一打印模板（print.php?action=record），与屏幕所见即所得病历版式一致；
        // A5 病历纸（竖版窄条，宽度受限、可向下延伸）
        Clinic.print.load('/api/print?action=record&visit_id=' + visitId, null, 'a5');
    }

    return {
        init: init,
        save: save,
        openTemplates: openTemplates,
        applyTemplateById: applyTemplateById,
        openTransfer: openTransfer,
        openCertificate: openCertificate,
        viewCertificate: viewCertificate,
        openVitals: openVitals,
        printRecord: printRecord,
        isRecordComplete: isRecordComplete,
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

/* ============================================================
 * 就诊历史入口（patient.php history 弹窗内按钮调用）
 * openHistoryCertificate：未开具时补开（校验病历完整性 + 弹窗填写医生建议）
 * printHistoryCertificate：已开具时查看/再次打印
 * ============================================================ */
function openHistoryCertificate(visitId) {
    Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
        onSuccess: function (j) {
            if (j.data.has_certificate) {
                Clinic.toast.warning('该次就诊已开具过诊断证明，可直接查看打印');
                return;
            }
            var r = j.data.record || {};
            var text = function (html) {
                var t = document.createElement('div');
                t.innerHTML = html || '';
                return t.textContent.trim();
            };
            var cc = text(r.chief_complaint);
            var pi = text(r.present_illness);
            var diag = (r.initial_diagnosis || '').trim();
            if (!cc || !pi || !diag) {
                Clinic.toast.warning('该次就诊病历不完整（缺少主诉/现病史/初步诊断），无法补开诊断证明');
                return;
            }
            var esc = function (s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            };
            Clinic.modal.open(
                '<div class="fs-13 text-muted mb-8">将自动引用该次就诊病历，医生建议请手动填写：</div>' +
                '<div class="fs-13 mb-8" style="border:1px solid var(--border);border-radius:8px;padding:10px">' +
                '  <div><strong>主诉：</strong>' + esc(cc) + '</div>' +
                '  <div class="mt-4"><strong>现病史：</strong>' + esc(pi) + '</div>' +
                '  <div class="mt-4"><strong>初步诊断：</strong>' + esc(diag) + '</div></div>' +
                '<div class="form-group"><label class="form-label">医生建议</label>' +
                '<textarea class="textarea" id="certContent" rows="3" placeholder="如：建议休息3天，清淡饮食，不适随诊"></textarea></div>',
                {
                    title: '补开诊断证明',
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

/* 查看已开具的诊断证明（弹窗打印预览，可再次打印） */
function printHistoryCertificate(visitId) {
    Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null);
}

/* 全局：开单详情弹窗内 删除 / 毁方（处方） */
function delOrderFlow(orderId, label) {
    var isRx = label === '毁方';
    Clinic.modal.confirm(isRx
        ? '确定毁方该处方？（仅未缴费或已退费的处方可毁方，未缴费毁方后药品库存自动恢复）'
        : '确定删除该开单？（仅未缴费或已退费可删除，处方删除后库存恢复）', function () {
        Clinic.ajax('/api/order', { action: 'delete', order_id: orderId }, {
            onSuccess: function (j) {
                Clinic.toast.success(j.msg);
                Clinic.emr.loadOrders(document.getElementById('visitId').value);
            },
        });
    });
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

            var printBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px" ' +
                'onclick="Clinic.print.load(\'/api/print?action=order&order_id=' + o.id + '\',null,\'a5\')">🖨️ 打印' +
                (typeNames[o.order_type] || '') + '单</button>';

            // 删除 / 毁方按钮：处方称「毁方」，其余称「删除」；
            // 仅未缴费（open）或已退费（refunded）可删，其余点击提示到收费处退费
            var delLabel = o.order_type === 'prescription' ? '毁方' : '删除';
            var delBtn = (o.status === 'open' || o.status === 'refunded')
                ? '<button class="btn btn-outline btn-sm" style="margin-top:8px;margin-left:8px" ' +
                  'onclick="delOrderFlow(' + o.id + ',\'' + delLabel + '\')">🗑️ ' + delLabel + '</button>'
                : '<button class="btn btn-outline btn-sm" style="margin-top:8px;margin-left:8px" ' +
                  'onclick="Clinic.toast.warning(\'' + delLabel + '仅限未缴费或已退费的开单，已进入执行流程的项目如需撤销请到收费处办理退费\')">🗑️ ' + delLabel + '</button>';

            Clinic.modal.open(
                '<div class="flex gap-16">' +
                '  <div style="flex:1">' +
                '    <div class="fw-600 mb-8">' + (typeNames[o.order_type] || '') + '：' + o.order_no + '</div>' +
                '    ' + items +
                '    <div class="fs-13 text-muted mt-8">金额：¥' + parseFloat(o.total_amount).toFixed(2) + '</div>' +
                '    <div class="fs-13 text-muted">开单医生：' + (o.doctor_name || '—') + ' ｜ ' + o.created_at + '</div>' +
                (o.done_by ? '<div class="fs-13 text-success mt-4">执行人：' + o.done_by + '</div>' : '') +
                printBtn + delBtn +
                '  </div>' +
                '  <div style="width:160px;border-left:1px solid var(--border);padding-left:16px">' +
                '    <div class="fw-600 mb-8 fs-13">流程进度</div>' + flow + '</div>' +
                '</div>',
                { title: '开单详情', size: 'modal-lg' }
            );
        },
    });
}


