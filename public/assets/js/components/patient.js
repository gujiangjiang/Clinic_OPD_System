/**
 * ============================================================
 * patient.js v1.1.0 — 患者信息修改弹窗
 * ============================================================
 * 说明：三处共用（挂号管理、医生工作站病历主页、
 * 护士站护理记录页面），点击患者姓名后弹出：
 * 可修改除姓名、性别、身份证号、出生年月外的其他信息
 * （手机号、职业、单位、婚姻、民族等）。
 * 表单由服务端渲染（字典来自 options_data.php）。
 * 新增：轻量发布/订阅——保存成功后广播更新事件，
 * 病历编辑页借此局部刷新患者头（不做整页刷新，
 * 避免下方未保存的病历内容丢失）。
 * ============================================================ */

window.Clinic = window.Clinic || {};

/**
 * 患者查询弹窗（医生工作站工具箱 / 医技工作台工具箱共用）。
 * 输入患者ID/身份证号/姓名 → /api/patient search → 点击条目查看全部就诊历史。
 * @param {object} opts { idPrefix } 元素 ID 前缀（doctor_tools=ps / deptwork=dwPs）
 */
Clinic.patientSearch = {
    open: function (opts) {
        opts = opts || {};
        var prefix = opts.idPrefix || 'ps';
        var kwId = prefix + 'Kw';
        Clinic.modal.open(
            '<div class="form-group"><label class="form-label">患者ID / 身份证号 / 姓名</label>' +
            '<input class="input" id="' + kwId + '" placeholder="请输入患者ID / 身份证号 / 姓名" ' +
            'onkeydown="if(event.key===\'Enter\')Clinic.patientSearch.doSearch(\'' + prefix + '\')"></div>' +
            '<div id="' + prefix + 'Result" class="fs-13"></div>',
            {
                title: opts.title || '患者查询',
                size: 'modal-sm',
                buttons: [
                    { text: '关闭', cls: 'btn-outline' },
                    { text: '查 询', cls: 'btn-primary', autoClose: false, onClick: function () { Clinic.patientSearch.doSearch(prefix); } },
                ],
            }
        );
        setTimeout(function () {
            var el = document.getElementById(kwId);
            if (el) el.focus();
        }, 80);
    },

    doSearch: function (prefix) {
        var kwEl = document.getElementById(prefix + 'Kw');
        var kw = (kwEl ? kwEl.value : '').trim();
        if (!kw) { Clinic.toast.warning('请输入患者ID / 身份证号 / 姓名'); return; }
        var box = document.getElementById(prefix + 'Result');
        if (!box) return;
        box.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:24px;height:24px;margin:10px auto"></div>';
        Clinic.get('/api/patient?action=search&kw=' + encodeURIComponent(kw), null, {
            onSuccess: function (json) {
                var list = json.data.list || [];
                if (!list.length) { box.innerHTML = '<div class="text-muted">未检索到该患者</div>'; return; }
                box.innerHTML = '<div class="fs-13 text-muted mb-8">检索到 ' + list.length + ' 位患者，点击查看全部就诊历史</div>' +
                    list.map(function (p) {
                        return '<div class="dd-item" style="cursor:pointer" onclick="showPatientHistory(\'' + Clinic.escHtml(p.patient_no) + '\')">' +
                            '<div class="flex-between"><span class="fw-600">' + Clinic.escHtml(p.name) + '</span>' +
                            '<span class="text-muted fs-12">' + Clinic.escHtml(p.patient_no) + ' ｜ ' + Clinic.escHtml(p.gender) + '/' + Clinic.escHtml(p.age_fmt || (Clinic.validate && Clinic.validate.formatAge(p.birth_date)) || '') + '</span></div></div>';
                    }).join('');
            },
        });
    },
};

Clinic.patient = {
    /** 患者资料更新订阅者列表 */
    _subs: [],

    /**
     * 订阅患者资料更新事件
     * @param {Function} fn 回调 (patientNo:string) => void
     */
    onInfoUpdated: function (fn) {
        if (typeof fn === 'function') this._subs.push(fn);
    },

    /**
     * 取消订阅患者资料更新事件（SPA 局部刷新重复初始化时防累积）
     */
    offInfoUpdated: function (fn) {
        this._subs = this._subs.filter(function (f) { return f !== fn; });
    },

    /**
     * 广播患者资料已更新（保存成功后调用）
     * @param {string} patientNo 患者 ID
     */
    emitInfoUpdated: function (patientNo) {
        this._subs.forEach(function (fn) {
            try { fn(patientNo); } catch (e) { /* 单个订阅者异常不影响其余 */ }
        });
    },

    /**
     * 打开患者信息修改弹窗
     * @param {string} kw 患者ID / 身份证号
     */
    editModal: function (kw) {
        Clinic.get('/api/patient?action=edit_form&kw=' + encodeURIComponent(kw || ''), null, {
            onSuccess: function (json) {
                if (!json.data || !json.data.html) {
                    Clinic.toast.warning('未找到患者档案');
                    return;
                }
                Clinic.modal.open(json.data.html, {
                    title: '修改患者信息',
                    size: 'modal-lg',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        {
                            text: '保存修改', cls: 'btn-primary', autoClose: false,
                            onClick: function () {
                                var patientNo = document.getElementById('pmNo').value;
                                Clinic.ajax('/api/patient', {
                                    action: 'update',
                                    patient_no: patientNo,
                                    phone: document.getElementById('pmPhone').value.trim(),
                                    ethnicity: document.getElementById('pmEth').value,
                                    marital: document.getElementById('pmMarital').value,
                                    occupation: document.getElementById('pmOcc').value,
                                    work_unit: document.getElementById('pmWork').value.trim(),
                                    address: document.getElementById('pmAddr').value.trim(),
                                }, {
                                    onSuccess: function (j) {
                                        Clinic.toast.success(j.msg);
                                        Clinic.modal.close();
                                        // 广播更新：病历编辑页等订阅方局部刷新患者头
                                        Clinic.patient.emitInfoUpdated(patientNo);
                                    },
                                });
                            },
                        },
                    ],
                });
            },
        });
    },
};
