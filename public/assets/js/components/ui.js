/**
 * ============================================================
 * ui.js v1.0.0 — 通用表单弹窗助手
 * ============================================================
 * 说明：管理端 CRUD 复用：
 * formModal(url, data, title, fields, onSaved)
 *   url     接口地址（如 /api/admin）
 *   data    打开表单的参数（如 {action:'dept_form', id:0}）
 *   title   弹窗标题
 *   fields  需要收集并提交的表单字段 id 列表
 *   onSaved 保存成功后的回调（通常用于刷新列表）
 * 表单由服务端渲染（字典/选项统一来自 options_data.php）。
 * ============================================================ */

window.Clinic = window.Clinic || {};

/**
 * 全局 loadModal —— 管理端列表「编辑」按钮通用入口
 * 说明：admin 列表（科室/用户/项目/药品/处置）的编辑按钮调用
 *       loadModal('/api/admin', {action:'xxx_form', id:1}, '标题')，
 *       本函数负责：
 *   1. AJAX 加载服务端渲染的表单到弹窗（modal:loaded 后绑定保存）
 *   2. 保存时自动派生 action（xxx_form → xxx_save）
 *   3. 收集表单中所有 f_ 前缀控件值（含复选框/多科室/文件上传），
 *      字段名做特殊映射（如 f_am → am_quota）后以 FormData 提交
 *   4. 保存成功后自动刷新对应列表（loadXxxList）
 */
function loadModal(url, data, title) {
    data = data || {};
    var action = data.action || '';
    var saveAction = action.replace(/_form$/, '_save');
    var reloadFn = {
        dept_form: 'loadDeptList',
        user_form: 'loadUserList',
        item_form: 'loadItemList',
        lab_group_form: 'loadItemList',
        drug_form: 'loadDrugList',
        disposal_form: 'loadDispList',
    }[action] || '';
    // 表单控件 ID → 提交字段名 的映射（id 前缀 f_ 去掉后不直接等于字段名的部分）
    var FIELD_MAP = {
        f_am: 'am_quota', f_pm: 'pm_quota',
        f_generic: 'generic_name', f_pkg: 'package_unit', f_dose: 'single_dose',
        f_freq: 'frequency', f_route: 'route',
        f_rx: 'is_rx', f_limited: 'is_limited', f_nurse: 'is_nurse',
        f_skin_test: 'is_skin_test', f_skin_item: 'skin_test_item_id',
        f_normal: 'normal_range', f_clow: 'critical_low', f_chigh: 'critical_high',
    };

    var mask = Clinic.modal.load(url, data, { title: title });
    mask.querySelector('.modal-body').addEventListener('modal:loaded', function (e) {
        var body = mask.querySelector('.modal-body');
        mask.querySelector('.modal-foot').innerHTML =
            '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
            '<button type="button" class="btn btn-primary" id="fSave">保存</button>';

        // 药品编辑：途径 → 需护士站处理 自动勾选（与 openDrugForm 行为一致）
        var nurseChk = body.querySelector('#f_nurse');
        if (nurseChk && body.querySelector('#f_route')) {
            var routeMap = (e.detail && e.detail.route_nurse) || {};
            window.syncNurse = function () {
                var route = body.querySelector('#f_route').value;
                if (routeMap[route] === 1) nurseChk.checked = true;
            };
        }

        // 药品编辑：皮试联动（syncSkinBox/pickSkinDisposal/clearSkinDisposal）
        // 已由文件末尾的全局 modal:loaded 监听器统一绑定（覆盖所有弹窗路径），
        // 此处不再重复定义，避免同一弹窗双份绑定。

        document.getElementById('fSave').addEventListener('click', function () {
            var fd = new FormData();
            fd.append('csrf_token', document.body.getAttribute('data-csrf'));
            fd.append('action', saveAction);
            fd.append('id', data.id || 0);
            if (data.type) fd.append('type', data.type);
            // 收集表单中所有 f_ 前缀控件（隐藏的 f_id 除外，统一以 id 参数提交）
            body.querySelectorAll('input[id^="f_"], select[id^="f_"], textarea[id^="f_"]').forEach(function (el) {
                if (el.id === 'f_id') return;
                var key = FIELD_MAP[el.id] || el.id.substring(2);
                if (el.type === 'checkbox') {
                    fd.append(key, el.checked ? '1' : '0');
                } else if (el.type === 'file') {
                    if (el.files[0]) fd.append(key, el.files[0]);
                } else {
                    fd.append(key, el.value);
                }
            });
            // 医生所属科室多选框（用户管理）
            var deptIds = [];
            body.querySelectorAll('.deptChk:checked').forEach(function (c) { deptIds.push(c.value); });
            fd.append('dept_ids', deptIds.join(','));
            // 检验组合成员多选框（检验组合表单）
            var memberIds = [];
            body.querySelectorAll('.grpMem:checked').forEach(function (c) { memberIds.push(c.value); });
            if (memberIds.length) fd.append('member_ids', memberIds.join(','));

            fetch(url, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json.ok) {
                        Clinic.toast.success(json.msg);
                        Clinic.modal.close();
                        if (reloadFn && window[reloadFn]) window[reloadFn]();
                    } else {
                        Clinic.toast.error(json.msg || '保存失败');
                    }
                })
                .catch(function () { Clinic.toast.error('网络请求失败'); });
        });
    });
}

Clinic.ui = {
    /**
     * 打开服务端渲染的表单弹窗并绑定保存
     */
    formModal: function (url, data, title, fields, onSaved) {
        var mask = Clinic.modal.load(url, data, { title: title });
        mask.querySelector('.modal-body').addEventListener('modal:loaded', function () {
            var foot = mask.querySelector('.modal-foot');
            foot.innerHTML =
                '<button type="button" class="btn btn-outline" onclick="Clinic.modal.close()">取消</button>' +
                '<button type="button" class="btn btn-primary" id="fSave">保存</button>';
            document.getElementById('fSave').addEventListener('click', function () {
                var payload = { action: 'save' };
                fields.forEach(function (f) {
                    var el = document.getElementById(f);
                    if (el) payload[f] = el.value;
                });
                Clinic.ajax(url, payload, {
                    onSuccess: function (json) {
                        Clinic.toast.success(json.msg);
                        Clinic.modal.close();
                        if (onSaved) onSaved(json);
                    },
                });
            });
        });
    },
};

/* ============================================================
 * 全局弹窗钩子：绑定药品编辑皮试联动（syncSkinBox /
 * pickSkinDisposal / clearSkinDisposal）
 * 说明：openDrugForm 直接走 Clinic.modal.load（不经 loadModal），
 * 因此必须用事件捕获在 document 级监听 modal:loaded，
 * 对所有弹窗生效；无皮试字段时定义空函数兜底，避免按钮报错。
 * ============================================================ */
document.addEventListener('modal:loaded', function (e) {
    var body = e.target;
    if (!body || typeof body.querySelector !== 'function') return;
    var skinChk = body.querySelector('#f_skin_test');
    var skinBox = body.querySelector('#skin_box');

    window.syncSkinBox = function () {
        if (!skinBox) return;
        skinBox.style.display = (skinChk && skinChk.checked) ? '' : 'none';
        if (skinChk && !skinChk.checked) {
            var it = body.querySelector('#f_skin_item');
            var nm = body.querySelector('#f_skin_item_name');
            if (it) it.value = '0';
            if (nm) nm.value = '';
        }
    };
    window.pickSkinDisposal = function () {
        if (!skinBox) { Clinic.toast.warning('当前表单不支持皮试关联'); return; }
        Clinic.universalSelector.open({
            title: '选择关联皮试处置项目',
            searchAction: 'disposal_search',
            allowCreate: true,
            createAction: 'disposal_quick_create',
            createContext: (body.querySelector('#f_name') && body.querySelector('#f_name').value
                ? '在维护药品[' + body.querySelector('#f_name').value + ']时快捷创建皮试处置'
                : '快捷创建皮试处置'),
            onSelect: function (item) {
                var it = body.querySelector('#f_skin_item');
                var nm = body.querySelector('#f_skin_item_name');
                if (it) it.value = item.id;
                if (nm) nm.value = item.name;
            },
        });
    };
    window.clearSkinDisposal = function () {
        var it = body.querySelector('#f_skin_item');
        var nm = body.querySelector('#f_skin_item_name');
        if (it) it.value = '0';
        if (nm) nm.value = '';
    };

    if (skinChk) {
        skinChk.addEventListener('change', function () { if (window.syncSkinBox) window.syncSkinBox(); });
        if (skinChk.checked && !(parseInt(body.querySelector('#f_skin_item').value, 10) > 0)) {
            Clinic.toast.warning('该药品标记了需皮试，请关联皮试处置项目');
        }
    }
    if (window.syncSkinBox) window.syncSkinBox();
}, true);

/**
 * 支付方式选择模态框（挂号缴费 / 缴费管理共用，优化6）
 * 说明：现金完整可用（选择后回调继续缴费并打印凭条）；
 * 医保卡/银行卡/扫码支付演示环境未开通，选择时提示开发中。
 * @param {string}   title  弹窗标题（如「挂号费缴费」「批量缴费」）
 * @param {function} onDone 选择有效支付方式后的回调（当前仅现金）
 */
Clinic.payMethod = {
    open: function (title, onDone) {
        var methods = [
            { k: '现金', icon: '💵', name: '现金', desc: '现金支付（支持找零）', avail: 1 },
            { k: '医保', icon: '🪪', name: '医保卡', desc: '医保卡实时结算', avail: 0 },
            { k: 'bank', icon: '💳', name: '银行卡', desc: '银联 / VISA / MasterCard / AE', avail: 0 },
            { k: 'scan', icon: '📱', name: '扫码支付', desc: '微信 / 支付宝 / 云闪付', avail: 0 },
        ];
        Clinic.modal.open(
            '<div class="pay-methods">' + methods.map(function (m) {
                return '<div class="pay-method' + (m.avail ? '' : ' disabled') + '" data-k="' + m.k + '">' +
                    '<div class="pay-method-icon">' + m.icon + '</div>' +
                    '<div class="pay-method-name">' + m.name + '</div>' +
                    '<div class="pay-method-desc">' + m.desc + '</div></div>';
            }).join('') + '</div>' +
            '<div class="fs-12 text-muted mt-8">当前演示环境仅支持现金支付，其余支付方式即将上线。</div>',
            { title: title + ' · 选择支付方式', size: 'modal-md', buttons: [{ text: '取消', cls: 'btn-outline' }] }
        );
        document.querySelectorAll('.pay-method').forEach(function (el) {
            el.addEventListener('click', function () {
                var k = el.getAttribute('data-k');
                if (el.classList.contains('disabled')) {
                    Clinic.toast.info('「' + k + '」支付方式正在开发中，请选择现金');
                    return;
                }
                Clinic.modal.close();
                if (onDone) onDone('现金');
            });
        });
    },
};

/**
 * 退费申请审批（模态框，站内消息点击直达，优化：避免页面跳转）
 * 说明：消息 link_url=/refund/approve?id=xxx 时，前端识别后调本组件
 * 打开模态框展示患者/执行状态/审批进度，当前审批人可直接同意或拒绝。
 */
Clinic.refundApproval = {
    /** 是否为退费审批链接 */
    isApproveLink: function (url) {
        return !!url && url.indexOf('/refund/approve') !== -1;
    },

    /** 从链接提取 request_id */
    reqIdFromLink: function (url) {
        var m = /[?&]id=([^&]+)/.exec(url || '');
        return m ? m[1] : '';
    },

    /** 打开退费审批模态框（id 为混淆串 request_id） */
    open: function (id) {
        Clinic.get('/api/refund?action=detail&id=' + encodeURIComponent(id), null, {
            onSuccess: function (json) {
                Clinic.refundApproval._render(json.data || {});
            },
            onError: function () {
                Clinic.modal.open('<div class="fs-13 text-muted text-center" style="padding:30px">退费申请不存在或已失效</div>',
                    { title: '退费申请审批', size: 'modal-md' });
            },
        });
    },

    _render: function (d) {
        var r = d.request || {}, approvals = d.approvals || [], orders = d.orders || [];
        var typeNames = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
        var statusMap = {
            open: ['badge-warning', '待缴费'], paid: ['badge-primary', '已缴费'],
            registered: ['badge-info', '已登记'], dispensing: ['badge-warning', '发药中'],
            dispensed: ['badge-success', '已发药'], done: ['badge-success', '已完成'],
            rejected: ['badge-danger', '已驳回'], refunded: ['badge-gray', '已退费'], cancelled: ['badge-gray', '已取消'],
        };
        var visitStatusMap = { pending: '待缴费', paid: '待就诊', visiting: '就诊中', finished: '已诊毕', refunded: '已退费', cancelled: '已取消' };
        var myName = document.body.getAttribute('data-name') || '';
        var myRole = document.body.getAttribute('data-role') || '';

        var html =
            '<div class="fs-15 fw-700">患者：' + Clinic.escHtml(r.patient.name) +
            ' <span class="fs-12 text-muted fw-400">' + Clinic.escHtml(r.patient.patient_no) + '</span></div>' +
            '<div class="fs-13 text-muted mt-2">就诊状态：<span class="badge badge-warning" style="font-size:11px">' +
            (visitStatusMap[r.patient.visit_status] || r.patient.visit_status) + '</span> ｜ 缴费批次：' + Clinic.escHtml(r.payment_no) + '</div>' +
            '<div class="fs-13 text-muted mt-2">申请时间：' + Clinic.escHtml(r.created_at) + '</div>' +
            (r.reason ? '<div class="fs-13 mt-2">申请理由：' + Clinic.escHtml(r.reason) + '</div>' : '') +
            '<div class="mt-2">状态：' +
            (r.status === 'approved' ? '<span class="badge badge-success">已全部同意</span>' :
                (r.status === 'rejected' ? '<span class="badge badge-danger">已拒绝</span>' : '<span class="badge badge-warning">待审批</span>')) + '</div>';

        html += '<div class="fs-14 fw-700 mt-4 mb-2">审批进度</div>';
        approvals.forEach(function (a) {
            var cls = a.verdict === 'approve' ? 'badge-success' : (a.verdict === 'reject' ? 'badge-danger' : 'badge-gray');
            var txt = a.verdict === 'approve' ? '已同意' : (a.verdict === 'reject' ? '已拒绝' : '待审批');
            html += '<div class="flex-between" style="padding:4px 0;border-top:1px dashed var(--border)">' +
                '<span class="fs-13">' + Clinic.escHtml(a.user_name) + ' <span class="fs-12 text-muted">（' + Clinic.escHtml(a.role) + '）</span>' +
                (a.note ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(a.note) + '</span>' : '') + '</span>' +
                '<span class="badge ' + cls + '" style="font-size:11px">' + txt + '</span></div>';
        });

        html += '<div class="fs-14 fw-700 mt-4 mb-2">项目执行状态</div>';
        orders.forEach(function (o) {
            html += '<div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:6px">' +
                '<div class="fs-13 fw-600">' + (typeNames[o.order_type] || '') + ' ' + Clinic.escHtml(o.order_no) +
                ' ｜ ' + Clinic.escHtml(o.doctor_name) + '</div>';
            var steps = (o.flow || []).map(function (s) {
                var cls = s.done ? 'var(--success)' : 'var(--border)';
                if (s.rejected) cls = 'var(--danger)';
                return '<span style="color:' + cls + ';font-size:11px;white-space:nowrap">' + (s.done ? '✓ ' : '○ ') + Clinic.escHtml(s.label) + '</span>';
            }).join('<span style="color:var(--border)"> → </span>');
            html += '<div style="margin:4px 0;overflow-x:auto;white-space:nowrap">' + steps + '</div>';
            (o.items || []).forEach(function (it) {
                var st = statusMap[it.status] || ['badge-gray', it.status || ''];
                html += '<div class="flex-between" style="padding:3px 0;border-top:1px dashed var(--border)">' +
                    '<span class="fs-12">· ' + Clinic.escHtml(it.name) + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</span>' +
                    '<span><span class="badge ' + st[0] + '" style="font-size:10px">' + st[1] + '</span>' +
                    (it.executed_by ? ' <span class="fs-11 text-muted">' + Clinic.escHtml(it.executed_by) + '</span>' : '') + '</span></div>';
            });
            html += '</div>';
        });

        var canAct = r.status === 'pending' && approvals.some(function (a) { return a.user_name === myName; });
        if (r.status === 'pending' && (canAct || myRole === 'admin')) {
            html += '<div class="form-group mt-4"><label class="form-label">意见（可选）</label>' +
                '<textarea class="textarea" id="rapNote" rows="2" placeholder="如：患者已完成该检查，同意退费"></textarea></div>' +
                '<div class="flex gap-8 mt-2">' +
                '<button class="btn btn-danger" onclick="Clinic.refundApproval.vote(\'' + r.id + '\',\'reject\')">✕ 拒绝退费</button>' +
                '<button class="btn btn-primary" onclick="Clinic.refundApproval.vote(\'' + r.id + '\',\'approve\')">✓ 同意退费</button></div>';
        } else if (r.status === 'pending') {
            html += '<div class="fs-12 text-muted mt-4">您不是该申请的审批人，无法操作。</div>';
        }

        Clinic.modal.open(
            '<div style="max-height:70vh;overflow-y:auto;padding-right:4px">' + html + '</div>',
            { title: '🧾 退费申请审批', size: 'modal-lg' }
        );
    },

    vote: function (id, verdict) {
        var note = (document.getElementById('rapNote') || {}).value || '';
        Clinic.ajax('/api/refund', { action: 'approve', id: id, verdict: verdict, note: note.trim() }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.modal.close();
            },
        });
    },
};
