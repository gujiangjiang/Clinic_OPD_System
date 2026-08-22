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
        // 病历编辑区域禁止右键菜单：仅放行输入类控件（输入框/文本域/下拉/
        // 富文本可编辑区，粘贴等操作不受影响），其余区域一律屏蔽。
        // 作用范围限定在电子病历文档卡片（#emrCard），页面其他区域不受影响。
        var cardEl = document.getElementById('emrCard');
        if (cardEl) {
            cardEl.addEventListener('contextmenu', function (ev) {
                var t = ev.target;
                if (t && t.closest && t.closest('input, textarea, select, [contenteditable="true"]')) return;
                ev.preventDefault();
            });
        }
        // 患者资料保存后自动局部刷新本页头部（订阅 patient.js 的更新广播；
        // 只重建患者卡与文档内患者信息区，绝不触碰下方未保存的病历正文）
        Clinic.patient.onInfoUpdated(refreshPatientHead);
        loadData(visitId);
    }

    /**
     * 加载病历数据
     */
    function loadData(visitId) {
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                DATA = j.data;
                // 场景 B：前序医生病历只读查看区（1:N 多医生接诊，谁书写谁签名）
                renderPrevRecords(j.data);
                renderPatientCard(j.data);
                renderEmrCard(j.data);
                // 前序医生诊断上下文注入（诊断模态框跨医生引用查重用）
                injectPrevDiagContext();
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
        // 诊断证明入口：已开具 → 点击打开只读预览模态框（打印取服务器存档数据）；
        // 未开具 → 补开。两种就诊状态下的「已开具」文案均可点击。
        var certHtml = '';
        if (d.visit && d.visit.status === 'finished') {
            certHtml = d.has_certificate
                ? ' ｜ <a href="javascript:void(0)" onclick="Clinic.emr.certificateModal(\'' + d.visit.id + '\',\'诊断证明\')" class="text-success fw-600">已开具诊断证明（点击查看）</a>'
                : ' ｜ <a href="javascript:void(0)" onclick="Clinic.emr.openCertificate()" class="fw-600">补开诊断证明</a>';
        } else if (d.has_certificate) {
            certHtml = ' ｜ <a href="javascript:void(0)" onclick="Clinic.emr.certificateModal(\'' + d.visit.id + '\',\'诊断证明\')" class="text-success fw-600" style="cursor:pointer">已开具诊断证明（点击查看）</a>';
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
            '        <span class="badge badge-gray" style="margin-left:8px">' + v.gender + ' / ' + (v.age_fmt || '') + '</span>' +
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
     * 构建病历文档内的患者信息区 HTML（门诊两栏网格 / 急诊两行流式）。
     * 独立成函数供两处复用：整卡渲染 & 患者资料保存后的局部刷新
     * （所见即所得，与打印页版式一致）。
     * @param {Object} d          /api/record get 返回数据（record/visit/patient）
     * @param {string} vtOverride 初复诊当前值：局部刷新时保留用户已选项，传空取档案值
     */
    function patientGridHtml(d, vtOverride) {
        var r = d.record || {};
        var vv = d.visit || {};
        var p = d.patient || {};
        var vt = vtOverride || r.visit_type || '初诊';
        // 初复诊下拉（局部刷新时保留当前选择，避免打断医生操作）
        var vtSelect = '<select class="doc-cell-select" id="visitType">' +
            '<option value="初诊"' + (vt === '初诊' ? ' selected' : '') + '>初诊</option>' +
            '<option value="复诊"' + (vt === '复诊' ? ' selected' : '') + '>复诊</option></select>';
        var cellHtml = function (f) {
            // 初复诊为下拉框（可编辑），其余为纯文本展示
            var isSelect = (typeof f[1] === 'string' && f[1].indexOf('<select') === 0);
            if (isSelect) {
                return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' + f[1] + '</div>';
            }
            return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' +
                '<span class="doc-cell-value">' + f[1] + '</span></div>';
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

    /**
     * 患者资料保存成功后的局部刷新：
     * 重新拉取就诊数据后仅重建两处——
     * 1) 顶部患者信息卡 #emrHeader；
     * 2) 病历文档内的患者信息区（初复诊下拉保留医生当前选择）。
     * 病历正文编辑器、签名、页脚、已开项目一律不动，
     * 未保存内容零丢失（不做 location.reload 整页刷新）。
     */
    function refreshPatientHead() {
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                DATA = j.data;
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

    /**
     * 渲染病历编辑区（WYSIWYG）
     * 场景 A：本次挂号无任何病历 → 标准首诊编辑器（record_type=initial）
     * 场景 B：前序已有其他医生病历 → 本卡为当前医生的续写编辑器
     *         （record_type=progress，顶部必填病历续写；前序病历在上方只读区展示）
     */
    function renderEmrCard(d) {
        var r = d.record;
        var v = d.vitals || {};
        var vv = d.visit || {};   // 就诊信息（注意：v 为生命体征，患者信息网格必须用 vv）
        var p = d.patient || {};
        var isProgress = r.record_type === 'progress';
        var tplBtn = '<button type="button" class="btn btn-outline btn-sm" id="tplBtn" onclick="Clinic.emr.openTemplates()">📋 病历模板</button>';

        // 医院抬头与标题（与打印版式一致，所见即所得）；页眉归首诊文书所有，
        // 续写文书不带抬头/标题/患者信息/条形码，直接从「病历续写」开始
        var hosp = document.body.getAttribute('data-hosp') || '';
        var hosp2 = document.body.getAttribute('data-hosp2') || '';
        var docTitle = (d.visit && d.visit.dept_type === 'emergency' ? '急诊电子病历' : '门诊电子病历');

        // 患者信息区：门诊两栏网格 / 急诊两行流式（构建逻辑抽至
        // patientGridHtml，供患者资料保存后的局部刷新复用）
        var gridWrap = patientGridHtml(d, '');

        // 病历文档页头右上角条形码（与挂号凭条/打印预览一致：门诊号 flow_no，Code 128）
        var bcSrc = document.getElementById('emrBarcodeSrc');
        var bcHtml = (bcSrc && bcSrc.innerHTML)
            ? '<div class="doc-barcode">' + bcSrc.innerHTML +
              '<div class="doc-barcode-text">' + vv.visit_no + '</div></div>'
            : '';

        // 病历正文：结构化字段编辑器（[] 占位字段引擎，静态标签不可编辑，
        // 保存时仅收集字段内部文字；生命体征/意识状态两节由本函数外部构建注入。
        // 续写文书不重复录入生命体征/意识状态/主诉/现病史——归首诊文书所有）
        var vitalSec = null;
        var midNode = null;
        if (!isProgress) {
            vitalSec = document.createElement('div');
            vitalSec.className = 'doc-sec doc-sec-vital';
            vitalSec.setAttribute('onclick', 'Clinic.emr.openVitals()');
            vitalSec.setAttribute('title', '点击编辑生命体征');
            vitalSec.innerHTML = '<span class="doc-sec-label">生命体征</span>' +
                '<span class="doc-sec-body" id="vitalDisplay">' + vitalDisplayText(v) + '</span>';

            var consciousness = ['清醒', '嗜睡', '意识模糊', '昏睡', '昏迷', '谵妄'];
            midNode = document.createElement('div');
            midNode.className = 'doc-sec';
            // 意识状态：去掉「请选择」空选项，默认清醒（临床绝大多数场景）；
            // 已保存值由服务端 records 镜像表回读回显
            var curCon = r.consciousness || '清醒';
            // 与病历字段一致的 Word 式内联下拉样式（ef-select）
            midNode.innerHTML = '<span class="doc-sec-label">意识状态</span>' +
                '<span class="ef-select-wrap"><select class="ef-select" id="consciousness">' +
                consciousness.map(function (c) {
                    return '<option value="' + c + '"' + (curCon === c ? ' selected' : '') + '>' + c + '</option>';
                }).join('') + '</select></span>';
        }

        // 文档骨架：首诊文书带完整页眉（医院抬头/标题/患者信息/条形码）；
        // 续写文书无页眉，顶部仅一条「病历续写」标识带（承接上文直接续写）
        var docHtml;
        if (isProgress) {
            docHtml =
                '<div class="emr-doc">' +
                '<div class="doc-cont-head">' +
                '  <span class="doc-cont-badge">病历续写</span>' +
                '  <span class="fs-13 text-muted">续写医生：' + r.doctor_name +
                (r.doctor_emp ? '（工号 ' + r.doctor_emp + '）' : '') +
                (r.doctor_title ? ' ｜ ' + r.doctor_title : '') +
                (r.created_at ? ' ｜ 续写开始：' + r.created_at : '') + '</span>' +
                '</div>' +
                '<div class="doc-line"></div>' +
                '<div class="doc-body" id="docBody"></div>' +
                // 病历正文右下角医生签名
                '<div class="doc-body-sign">医生：' + r.doctor_name + '</div>' +
                '</div>' +
                // 页脚：左下角记录时间（未保存时隐藏），右下角医生签名
                '<div class="doc-footer">' +
                '  <span class="doc-rec-time" id="docRecTime" style="' + (r.updated_at ? '' : 'display:none') + '">记录时间：' + (r.updated_at || '') + '</span>' +
                '  <span class="doc-doctor">医生：' + r.doctor_name +
                (r.doctor_emp ? '（工号 ' + r.doctor_emp + '）' : '') +
                (r.doctor_title ? ' ｜ ' + r.doctor_title : '') + '</span>' +
                '</div>';
        } else {
            docHtml =
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
                '<div class="doc-body" id="docBody"></div>' +
                // 病历正文右下角医生签名（页脚仍保留 医生：医生（工号 0003）｜职称，互不影响）
                '<div class="doc-body-sign">医生：' + r.doctor_name + '</div>' +
                '</div>' +
                // 页脚：左下角记录时间（未保存时隐藏，保存成功后显示），右下角医生签名
                '<div class="doc-footer">' +
                '  <span class="doc-rec-time" id="docRecTime" style="' + (r.updated_at ? '' : 'display:none') + '">记录时间：' + (r.updated_at || '') + '</span>' +
                '  <span class="doc-doctor">医生：' + r.doctor_name +
                (r.doctor_emp ? '（工号 ' + r.doctor_emp + '）' : '') +
                (r.doctor_title ? ' ｜ ' + r.doctor_title : '') + '</span>' +
                '</div>';
        }
        document.getElementById('emrCard').innerHTML = docHtml;

        // 结构化字段编辑器渲染（[] 占位字段引擎；mode 决定首诊全量/续写精简模块）
        Clinic.emrEditor.render(document.getElementById('docBody'), r.emr || {}, {
            readonly: d.visit && d.visit.status === 'finished',
            beforeVitals: vitalSec,
            midNode: midNode,
            mode: isProgress ? 'progress' : 'initial',
        });

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
            // 结构化字段编辑器只读（[] 字段不可再编辑）
            Clinic.emrEditor.setReadonly(true);
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

    /* ==================== 多医生接诊：前序病历只读查看区 ====================
     * 前序医生的病历全只读展示（灰色只读背景、不可编辑），顶部标注
     * 「接诊自：XX医生，就诊时间」；当前医生只能在下方新建续写文书。
     * 展示文本格式与后端 emr_formatter.php 同规则（所见即所得）。 */

    /** HTML 转义（防 XSS：病历内容含医生手输文本） */
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

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

    /**
     * 只读患者信息网格（纯文本，与编辑页 patientGridHtml 同字段同版式，
     * 初复诊为纯文本不可交互——供前序首诊文书的只读页眉复用）
     */
    function patientGridReadonly(vtText) {
        var vv = DATA ? (DATA.visit || {}) : {};
        var p = DATA ? (DATA.patient || {}) : {};
        var cell = function (k, v) {
            return '<div class="doc-cell"><span class="doc-cell-label">' + k + '：</span>' +
                '<span class="doc-cell-value">' + escHtml(v == null || v === '' ? '—' : v) + '</span></div>';
        };
        if (vv.dept_type === 'emergency') {
            return '<div class="doc-patient-lines">' +
                '<div class="doc-line-row">' + cell('姓名', vv.name) + cell('性别', vv.gender) +
                cell('出生日期', p.birth_date) + cell('年龄', vv.age_fmt) + '</div>' +
                '<div class="doc-line-row">' + cell('患者ID', p.patient_id) + cell('就诊科室', vv.dept_name) +
                cell('就诊时间', vv.created_at) + '</div></div>';
        }
        var fields = [['姓名', vv.name], ['性别', vv.gender], ['年龄', vv.age_fmt], ['患者ID', p.patient_id],
           ['证件号码', p.id_card], ['出生日期', p.birth_date], ['民族', p.nation], ['职业', p.occupation],
           ['婚姻', p.marital], ['初复诊', vtText || ''], ['科室', vv.dept_name], ['联系方式', p.phone]];
        return '<div class="doc-patient-grid">' + fields.map(function (f) { return cell(f[0], f[1]); }).join('') + '</div>';
    }

    /**
     * 单条前序病历 → 只读文书 HTML。
     * 页眉归首诊文书：initial 渲染完整文档版式（医院抬头/标题/患者信息网格）；
     * progress 不带页眉，以「病历续写」标注条承接上文直接开始，避免空间浪费。
     * @param {Object} rec records_history 条目（含 doctor_name/emr/primary_diagnosis 等）
     */
    function prevRecordHtml(rec) {
        var e = rec.emr || {};
        var isProgress = rec.record_type === 'progress';
        var secs = [];
        var push = function (label, val, dashWhenEmpty) {
            val = val == null ? '' : String(val).trim();
            if (!val && !dashWhenEmpty) return;
            secs.push('<div class="prev-sec"><span class="doc-sec-label">' + label + '：</span>' +
                escHtml(val || '-') + '</div>');
        };
        if (isProgress) push('病历续写', (e.progress || {}).content);
        push('主诉', fmtCC(e.chief_complaint));
        push('现病史', fmtPI(e.history_present));
        push('既往史', fmtPH(e.past_history));
        push('过敏史', fmtAL(e.allergies));
        push('主要症状', fmtMS(e.main_symptoms));
        push('体格检查', fmtPE(e.physical_exam), true);
        push('初步诊断', fmtDiags(e.diagnoses));
        // 辅助检查/门诊处置按该文书医生本人的开单归属渲染（多医生接诊，
        // 项目跟随医生归档；已开项目文本与编辑器自动段同源同规则）
        var t = orderTextsFor(rec.doctor_id || 0);
        var auxParts = [];
        [e.aux_result, e.aux_external].forEach(function (x) {
            if (x && String(x).trim()) auxParts.push(escHtml(x));
        });
        t.aux.forEach(function (n) { auxParts.push(escHtml(n)); });
        push('辅助检查', auxParts.join('，'), true);
        var dispHtml = t.rxs.map(function (l) { return '<div>' + escHtml(l) + '</div>'; }).join('');
        var dispParts = t.proc.map(function (p) { return escHtml(p); });
        if (e.disposition_custom && String(e.disposition_custom).trim()) dispParts.push(escHtml(e.disposition_custom));
        if (dispParts.length) dispHtml += '<span>' + dispParts.join('，') + '</span>';
        secs.push('<div class="prev-sec"><span class="doc-sec-label">门诊处置：</span>' + (dispHtml || '-') + '</div>');
        push('是否留观', e.is_leave_hospital === '是' ? '是' : '');
        push('嘱托', e.advice);

        var typeBadge = isProgress
            ? '<span class="badge badge-primary">病历续写</span>'
            : '<span class="badge badge-gray">首诊</span>';
        var primary = rec.primary_diagnosis
            ? '<span class="fs-12 text-muted">主诊断：' + escHtml((rec.primary_icd10 || '') + ' ' + rec.primary_diagnosis) + '</span>'
            : '';
        var who = isProgress ? '✍️ 病历续写 · 接诊自：' : '📋 接诊自：';
        var headBar =
            '<div class="prev-record-head">' +
            '  <span class="fw-600">' + who + escHtml(rec.doctor_name) +
            (rec.doctor_emp ? '（工号 ' + escHtml(rec.doctor_emp) + '）' : '') +
            (rec.doctor_title ? ' ' + escHtml(rec.doctor_title) : '') + '</span>' +
            '  <span>就诊时间：' + escHtml(rec.created_at) + '</span>' +
            typeBadge + primary +
            '</div>';
        var bodyHtml = '<div class="prev-record-body">' +
            (secs.length ? secs.join('') : '<div class="text-muted fs-13">（该文书暂无内容）</div>') + '</div>';

        // 首诊文书：完整文档版式（页眉归首诊）；续写文书：无页眉直接续写
        if (!isProgress) {
            var hosp = document.body.getAttribute('data-hosp') || '';
            var hosp2 = document.body.getAttribute('data-hosp2') || '';
            var docTitle = (DATA && DATA.visit && DATA.visit.dept_type === 'emergency') ? '急诊电子病历' : '门诊电子病历';
            return '<div class="emr-doc prev-doc-full">' +
                (hosp ? '<div class="doc-hosp">' + escHtml(hosp) + '</div>' : '') +
                (hosp2 ? '<div class="doc-sub">' + escHtml(hosp2) + '</div>' : '') +
                '<div class="doc-title-bar"><span class="doc-title">' + docTitle + '</span></div>' +
                patientGridReadonly('') +
                '<div class="doc-line"></div>' +
                headBar + bodyHtml +
                '<div class="doc-body-sign">医生：' + escHtml(rec.doctor_name) + '</div>' +
                '</div>';
        }
        return '<div class="prev-record">' + headBar + bodyHtml +
            '<div class="prev-record-sign">医生：' + escHtml(rec.doctor_name) + '</div>' +
            '</div>';
    }

    /**
     * 渲染前序病历只读查看区（插入在当前医生编辑卡片之上）。
     * 仅展示【其他医生】的文书；当前医生本人的草稿/病历在下方编辑器回显。
     */
    function renderPrevRecords(d) {
        var mineId = d.record && d.record.doctor_id;
        var others = (d.records_history || []).filter(function (r) { return r.doctor_id !== mineId; });
        var host = document.getElementById('prevRecords');
        if (!host) {
            host = document.createElement('div');
            host.id = 'prevRecords';
            var card = document.getElementById('emrCard');
            if (card && card.parentNode) card.parentNode.insertBefore(host, card);
        }
        host.innerHTML = others.length ? others.map(prevRecordHtml).join('') : '';
    }

    /**
     * 收集前序【其他医生】已添加的诊断（含医生姓名），注入编辑器供
     * 诊断模态框跨医生引用查重；本人已选列表不参与提示。
     */
    function injectPrevDiagContext() {
        if (!DATA || !DATA.records_history) return;
        var mineId = DATA.record && DATA.record.doctor_id;
        var flat = [];
        DATA.records_history.forEach(function (r) {
            if (r.doctor_id === mineId) return;
            ((r.emr && r.emr.diagnoses) || []).forEach(function (dg) {
                if (dg && dg.name) {
                    flat.push({
                        code: dg.code || '', name: dg.name,
                        part: dg.part || '', note: dg.note || '',
                        suspected: dg.suspected || '',
                        doctor_name: r.doctor_name || '前序医生',
                    });
                }
            });
        });
        Clinic.emrEditor.setPrevDiagnoses(flat);
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

    /** 当前登录医生 id（DATA.record 由后端按会话返回） */
    function myDoctorId() {
        return DATA && DATA.record ? DATA.record.doctor_id : 0;
    }

    /**
     * 按开单医生过滤已开项目并生成病历正文文本（辅助检查/处方行/处置项）。
     * 多医生接诊下各医生文书只呈现本人开具的项目——谁开单归属谁的病历。
     */
    function orderTextsFor(doctorId) {
        var aux = [];
        var proc = [];
        var rxs = [];
        (ORDERS || []).forEach(function (o) {
            if ((o.doctor_id || 0) !== doctorId) return;
            // 已退费/已取消的开单不再计入病历内容
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            o.items.forEach(function (it) {
                if (o.order_type === 'lab' || o.order_type === 'imaging') {
                    aux.push(it.item_name);
                } else if (o.order_type === 'procedure') {
                    proc.push(it.item_name + '×' + it.quantity);
                } else if (o.order_type === 'prescription') {
                    // 处方直显：名称　剂量　用法　途径　×数量（不加提示词，简洁直观）
                    var parts = [];
                    if (it.single_dose) parts.push(it.single_dose);
                    if (it.frequency_name) parts.push(it.frequency_name);
                    if (it.route_name) parts.push(it.route_name);
                    rxs.push(it.item_name + (parts.length ? '　' + parts.join('　') : '') + '　×' + it.quantity);
                }
            });
        });
        return { aux: aux, proc: proc, rxs: rxs };
    }

    /**
     * 渲染病历正文 辅助检查 / 门诊处置（所见即所得，与打印版式一致）
     * 仅渲染当前登录医生本人开具的项目（多医生接诊，项目跟随医生归档）
     */
    function renderDocOrders() {
        var t = orderTextsFor(myDoctorId());
        // 结构化编辑器自动段：已开检验/检查名（逗号分隔）、处方行（一行一个）、处置项（含数量）
        Clinic.emrEditor.setAuto('aux_orders', t.aux.join('，'), t.aux.length > 0);
        Clinic.emrEditor.setAuto('rx_lines', t.rxs.map(function (l) { return '<div class="ef-rx-line">' + l + '</div>'; }).join(''), t.rxs.length > 0);
        Clinic.emrEditor.setAuto('disp_items', t.proc.join('，'), t.proc.length > 0);
    }

    /**
     * 加载患者已开项目（病历处置区 + 病历正文所见即所得区）
     */
    function loadOrders(visitId) {
        Clinic.get('/api/order?action=visit_orders&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                ORDERS = j.data.list || [];
                renderDocOrders();
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
                var myId = myDoctorId();
                var multi = DATA && DATA.records_history && DATA.records_history.length > 1;
                box.innerHTML = j.data.list.map(function (o) {
                    var items = o.items.map(function (it) {
                        return '<div class="fs-13" style="padding:2px 0">· ' + it.item_name +
                            (it.quantity > 1 ? ' ×' + it.quantity : '') + '</div>';
                    }).join('');
                    // 检查申请单标题动态化：优先使用分类名称快照（如 CT / MR / DR（数字化X线））
                    var title = (o.order_type === 'imaging' && o.cat_name && o.cat_name !== '检查')
                        ? o.cat_name : (typeNames[o.order_type] || o.order_type);
                    // 删除仅限：未缴费或已退费，且为当前医生本人开具（后端硬拦截兜底）
                    var canDel = (o.status === 'open' || o.status === 'refunded') && (o.doctor_id || 0) === myId;
                    // stopPropagation：阻止事件冒泡到卡片 onclick（viewOrderFlow），
                    // 否则会同时弹出开单详情弹窗与删除确认弹窗，删除确认被覆盖
                    var delBtn = canDel
                        ? ' <button class="btn btn-outline btn-sm" style="padding:1px 8px" ' +
                          'onclick="event.stopPropagation();delOrder(' + o.id + ')">✕</button>'
                        : '';
                    // 多医生接诊：卡片标注开单医生（非本人时高亮提示归属）
                    var docLabel = multi
                        ? '<span class="fs-12 ' + ((o.doctor_id || 0) === myId ? 'text-muted' : 'text-primary') +
                          '">开单医生：' + (o.doctor_name || '—') + ((o.doctor_id || 0) === myId ? '（本人）' : '') + '</span>'
                        : '';
                    return '<div style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px;cursor:pointer" ' +
                        'onclick="viewOrderFlow(' + o.id + ')">' +
                        '<div class="flex-between">' +
                        '  <span class="fw-600 fs-13">' + title + ' ' + o.order_no + '</span>' +
                        delBtn +
                        '</div>' +
                        items +
                        '<div class="mt-4 flex-between">' + docLabel +
                        '  <span>' + (statusMap[o.status] || o.status) + ' ' +
                        '  <span class="fs-12 text-muted">¥' + parseFloat(o.total_amount).toFixed(2) + '</span></span></div>' +
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
     * 保存病历（结构化：仅提交完整 emr_data JSON 对象）
     * @param {boolean} finish 是否诊毕
     */
    function save(finish) {
        var emr = Clinic.emrEditor.collect();
        var cc = emr.chief_complaint || {};
        var pi = emr.history_present || {};
        // 按文书类型分支校验（与后端 save 同规则）：
        // 首诊=主诉/现病史/诊断；续写=病历续写内容/诊断
        var isProgress = DATA && DATA.record && DATA.record.record_type === 'progress';
        if (isProgress) {
            if (!((emr.progress || {}).content || '').trim()) { Clinic.toast.warning('请填写病历续写内容（必填，可快捷填入「病史同上」）'); return; }
            if (!emr.diagnoses || !emr.diagnoses.length) { Clinic.toast.warning('请添加初步诊断（必填）'); return; }
        } else {
            if (!(cc.symptom || '').trim()) { Clinic.toast.warning('请填写主诉（必填）'); return; }
            if (!(pi.content || '').trim()) { Clinic.toast.warning('请填写现病史（必填）'); return; }
            if (!emr.diagnoses || !emr.diagnoses.length) { Clinic.toast.warning('请添加初步诊断（必填）'); return; }
        }

        var data = {
            action: 'save',
            visit_id: document.getElementById('visitId').value,
            emr_data: JSON.stringify(emr),
            consciousness: document.getElementById('consciousness') ? document.getElementById('consciousness').value : '',
            visit_type: document.getElementById('visitType') ? document.getElementById('visitType').value : '初诊',
        };
        if (finish) data.finish = 1;
        Clinic.ajax('/api/record', data, {
            loading: true,
            onSuccess: function (j) {
                document.getElementById('saveStatus').textContent = '已保存 ' + new Date().toLocaleTimeString();
                // 同步本地缓存：保存成功后无需刷新页面，开检验/检查/处置/处方与打印病历立即生效
                if (DATA) {
                    DATA.record.emr = emr;
                    DATA.record.consciousness = data.consciousness;
                    DATA.record.visit_type = data.visit_type;
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
     * 应用模板内容到编辑器（旧模板为扁平 HTML 文本，剥离标签后
     * 填入对应结构化字段：主诉症状/现病史内容/既往史细节/过敏史）
     */
    function applyTemplate(t) {
        var c = {};
        try { c = JSON.parse(t.content || '{}'); } catch (e) { c = {}; }
        var strip = function (s) {
            var d = document.createElement('div');
            d.innerHTML = s || '';
            return d.innerText.trim();
        };
        var patch = {};
        var ccText = strip(c.chief_complaint);
        if (ccText) patch.chief_complaint = { symptom: ccText };
        var piText = strip(c.present_illness);
        if (piText) patch.history_present = { content: piText };
        var phText = strip(c.past_history);
        if (phText) patch.past_history = { type: '承认', detail: phText };
        var ahText = strip(c.allergy_history);
        if (ahText) patch.allergies = { type: '承认', detail: ahText };
        // 合并进当前数据并重渲染字段值
        var cur = Clinic.emrEditor.collect();
        Object.keys(patch).forEach(function (k) {
            if (typeof patch[k] === 'object') {
                cur[k] = Object.assign(cur[k] || {}, patch[k]);
            } else {
                cur[k] = patch[k];
            }
        });
        Clinic.emrEditor.set(cur);
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
     * 打开转科弹窗（复用通用科室选择组件 transfer 模式：
     * 服务端已排除当前科室，弹窗内不显示挂号相关信息）
     */
    function openTransfer() {
        var visitId = document.getElementById('visitId').value;
        var curDept = DATA ? DATA.visit.current_dept_id : 0;
        Clinic.deptPicker.open({
            mode: 'transfer',
            fetchUrl: '/api/transfer?action=targets&dept_id=' + curDept,
            currentId: curDept,
            onSelect: function (d) {
                Clinic.modal.confirm(
                    '确定将患者转往【' + d.name + '】吗？转科后就诊序号、首次挂号科室等信息均保持不变。',
                    function () {
                        Clinic.ajax('/api/transfer', {
                            action: 'do', visit_id: visitId, target_dept: d.id,
                        }, {
                            onSuccess: function (j) {
                                Clinic.toast.success(j.msg);
                                setTimeout(function () { location.href = '/doctor/dashboard'; }, 900);
                            },
                        });
                    },
                    { title: '确认转科', okText: '确认转科' }
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
     * 诊断证明弹窗（开具/补开/查看共用同一套代码，方便维护）
     * ——区别仅是模态框标题与入参就诊 ID：
     * · 开具：visitId = 当前编辑页就诊（本次就诊的病历）
     * · 补开：visitId = 就诊历史中的目标就诊（那一次的病历）
     * 三种形态：
     * · 未开具 → 可编辑：病历概要 + 医生建议输入 +「开具并打印」
     * · 已开具 → 只读：概要含证明号/开具时间，医生建议只读，
     *   按钮显示为「打印」——打印内容始终由服务器 certificate_print
     *   从数据库重新渲染（前端只读区域仅作展示，改不了真实数据）。
     */
    function certificateModal(visitId, title, onIssued) {
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var r = j.data.record || {};
                var issued = !!j.data.has_certificate;
                var cert = j.data.certificate || {};
                var text = function (html) {
                    var t = document.createElement('div');
                    t.innerHTML = html || '';
                    return t.textContent.trim();
                };
                var esc = function (s) {
                    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                };
                var cc = text(r.chief_complaint);
                var pi = text(r.present_illness);
                var diag = (r.initial_diagnosis || '').trim();

                // 病历概要区（两种形态共用；已开具时附证明号与开具时间）。
                // 行间距统一规则：首行无边距，其余每行 mt-4——
                // 修复「开具时间与主诉贴在一起」的缺失行距问题。
                var rows = [];
                if (issued) {
                    rows.push('<div><strong>证明号：</strong>' + esc(cert.cert_no || '') + '</div>');
                    rows.push('<div class="mt-4"><strong>开具时间：</strong>' + esc(cert.created_at || '') + '</div>');
                }
                rows.push('<div' + (rows.length ? ' class="mt-4"' : '') + '><strong>主诉：</strong>' + esc(cc) + '</div>');
                rows.push('<div class="mt-4"><strong>现病史：</strong>' + esc(pi) + '</div>');
                rows.push('<div class="mt-4"><strong>初步诊断：</strong>' + esc(diag) + '</div>');
                var summary =
                    '<div class="fs-13 mb-8" style="border:1px solid var(--border);border-radius:8px;padding:10px">' +
                    rows.join('') +
                    '</div>';

                /* ---- 已开具：查看 + 打印（打印取服务器存档数据） ---- */
                if (issued) {
                    Clinic.toast.warning('该次就诊已开具过诊断证明');
                    Clinic.modal.open(
                        summary +
                        '<div class="form-group"><label class="form-label">医生建议</label>' +
                        // 纯展示只读框：灰底、禁用、去掉右下角拖拽手柄、不显示文本光标
                        '<textarea class="textarea" rows="3" disabled ' +
                        'style="background:var(--bg);cursor:default;resize:none;">' +
                        esc(cert.content || '') + '</textarea></div>',
                        {
                            title: title,
                            size: 'modal-sm',
                            buttons: [
                                { text: '关闭', cls: 'btn-outline' },
                                {
                                    // 打印走 certificate_print：由服务器重新渲染存档数据
                                    text: '🖨️ 打印', cls: 'btn-success',
                                    onClick: function () {
                                        Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                    },
                                },
                            ],
                        }
                    );
                    return;
                }

                /* ---- 未开具：可编辑开具 ---- */
                if (!cc || !pi || !diag) {
                    Clinic.toast.warning('该次就诊病历不完整（缺少主诉/现病史/初步诊断），无法开具诊断证明');
                    return;
                }
                Clinic.modal.open(
                    '<div class="fs-13 text-muted mb-8">将自动引用该次就诊病历，医生建议请手动填写：</div>' +
                    summary +
                    '<div class="form-group"><label class="form-label">医生建议</label>' +
                    '<textarea class="textarea" id="certContent" rows="3" placeholder="如：建议休息3天，清淡饮食，不适随诊"></textarea></div>',
                    {
                        title: title,
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
                                            Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                            if (typeof onIssued === 'function') onIssued();
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
     * 开具诊断证明（本次就诊，单次就诊仅一次）
     * 与「补开诊断证明」共用 certificateModal，仅标题不同；
     * visitId 固定取当前编辑页的就诊 ID——引用的是本次就诊病历。
     */
    function openCertificate() {
        var visitId = document.getElementById('visitId').value;
        var emr = Clinic.emrEditor.collect();
        // 本地预校验未保存的编辑器内容；若已开具则直接进入只读预览
        // （certificateModal 内部会识别已开具状态并切换为「打印」形态，
        //   打印内容始终以服务器存档数据为准）
        if (!(DATA && DATA.has_certificate)) {
            var cc, pi, diag;
            if (DATA && DATA.record && DATA.record.record_type === 'progress') {
                // 续写文书：主诉/现病史归首诊医生所有，本地不拦截——
                // 交由 certificateModal 以服务端投影（含前序文书回退）判定
                cc = 'x'; pi = 'x';
                diag = (emr.diagnoses || []).length;
            } else {
                cc = (emr.chief_complaint && emr.chief_complaint.symptom || '').trim();
                pi = (emr.history_present && emr.history_present.content || '').trim();
                diag = (emr.diagnoses || []).length;
            }
            if (!cc || !pi || !diag) {
                Clinic.toast.warning('请先完善病历（主诉、现病史、诊断）');
                return;
            }
        }
        certificateModal(visitId, '开具诊断证明');
    }

    /**
     * 病历是否已完善并保存（主诉/现病史/初步诊断均为必填，任一缺失视为未完善）
     * 开检验/检查/处置/处方与打印病历的前置条件（前端拦截，后端亦有同样校验）
     * @returns {boolean}
     */
    function isRecordComplete() {
        if (!DATA || !DATA.record) return false;
        // 结构化病历：校验 emr_data 投影（主诉症状/现病史内容/诊断列表）
        var e = DATA.record.emr;
        if (!e) return false;
        // 续写文书：病历续写内容 + 诊断（主诉/现病史归首诊文书，不参与判定）
        if (DATA.record.record_type === 'progress') {
            var pc = ((e.progress || {}).content || '').trim();
            return !!(pc && (e.diagnoses || []).length);
        }
        var cc = ((e.chief_complaint || {}).symptom || '').trim();
        var pi = ((e.history_present || {}).content || '').trim();
        return !!(cc && pi && (e.diagnoses || []).length);
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
        certificateModal: certificateModal,
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
 * openHistoryCertificate：未开具时补开——与开具共用
 * Clinic.emr.certificateModal，仅标题不同；visitId 为就诊历史中
 * 目标那一次就诊的 ID，引用的是该次就诊的病历内容。
 * printHistoryCertificate：已开具时查看/再次打印
 * ============================================================ */
function openHistoryCertificate(visitId) {
    Clinic.emr.certificateModal(visitId, '补开诊断证明');
}

/* 查看已开具的诊断证明（弹窗打印预览，可再次打印） */
function printHistoryCertificate(visitId) {
    Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
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

            var catTitle = (o.order_type === 'imaging' && o.cat_name && o.cat_name !== '检查') ? o.cat_name : (typeNames[o.order_type] || '');
            var printBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px" ' +
                'onclick="Clinic.print.load(\'/api/print?action=order&order_id=' + o.id + '\',null,\'a5\')">🖨️ 打印' +
                catTitle + '单</button>';

            // 删除 / 毁方按钮：处方称「毁方」，其余称「删除」；
            // 仅开单医生本人可见可用（多医生接诊权责隔离，后端亦有硬拦截）；
            // 仅未缴费（open）或已退费（refunded）可删，其余点击提示到收费处退费
            var delLabel = o.order_type === 'prescription' ? '毁方' : '删除';
            var mine = (o.doctor_id || 0) === myDoctorId();
            var delBtn;
            if (!mine) {
                delBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px;margin-left:8px" ' +
                    'onclick="Clinic.toast.warning(\'仅开单医生本人可' + delLabel + '（开单医生：' + (o.doctor_name || '—') + '）\')">🗑️ ' + delLabel + '</button>';
            } else if (o.status === 'open' || o.status === 'refunded') {
                delBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px;margin-left:8px" ' +
                    'onclick="delOrderFlow(' + o.id + ',\'' + delLabel + '\')">🗑️ ' + delLabel + '</button>';
            } else {
                delBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px;margin-left:8px" ' +
                    'onclick="Clinic.toast.warning(\'' + delLabel + '仅限未缴费或已退费的开单，已进入执行流程的项目如需撤销请到收费处办理退费\')">🗑️ ' + delLabel + '</button>';
            }

            Clinic.modal.open(
                '<div class="flex gap-16">' +
                '  <div style="flex:1">' +
                '    <div class="fw-600 mb-8">' + catTitle + '：' + o.order_no + '</div>' +
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


