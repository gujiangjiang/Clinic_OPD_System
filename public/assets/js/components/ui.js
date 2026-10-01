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
 * 说明：弹窗打开/关闭、全局键盘快捷键、焦点管理与表单数据收集等。
 * 各管理页的表单弹窗逻辑已由各视图内联脚本（openXxxForm）各自实现，
 * 不再依赖本文件的历史 loadModal 自动表单（该函数已移除，0 调用方）。
 */
/**
 * 弹窗辅助：收集表单中所有 f_ 前缀控件值（含复选框/多科室/文件上传）——已内联至各视图。
 */

/**
 * 通用格式化 helper（多模块重复实现，统一收敛到 Clinic 全局）
 */
/**
 * 全局省略显示 + 悬浮完整内容提示（通用方法）：
 * 返回带 max-width 的单行省略 span，悬浮（title）显示完整文本。
 * 用于规格/厂商/名称等可能超长的展示位，与 .ellipsis 样式一致但自动带 title tip。
 * @param {string} text      完整文本
 * @param {number} maxWidth  最大宽度（px），省略截断阈值
 * @param {string} extraCls  附加 class（如 text-muted）
 * @returns {string} HTML
 */
Clinic.ellipsis = function (text, maxWidth, extraCls) {
    text = (text == null ? '' : String(text));
    maxWidth = maxWidth || 140;
    return '<span class="ellipsis ' + (extraCls || '') + '" style="max-width:' + maxWidth + 'px;vertical-align:middle"' +
        (text !== '' ? ' title="' + String(text).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '"' : '') +
        '>' + Clinic.escHtml(text) + '</span>';
};



/**
 * 模态框只读化（预览通用）：禁用全部交互控件，保留滚动，拦截点击/复制/右键。
 */
Clinic.modalReadonly = function (mask) {
    if (!mask) return;
    var body = mask.querySelector('.modal-body');
    if (!body) return;
    body.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.disabled = true;
        el.setAttribute('readonly', '');
    });
    body.querySelectorAll('button, .btn').forEach(function (el) { el.disabled = true; });
    body.querySelectorAll('[contenteditable]').forEach(function (el) { el.setAttribute('contenteditable', 'false'); });
    body.querySelectorAll('[onclick], [onmousedown]').forEach(function (el) {
        el.removeAttribute('onclick');
        el.removeAttribute('onmousedown');
    });
    body.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); }, true);
    body.style.userSelect = 'none';
    body.style.webkitUserSelect = 'none';
    body.addEventListener('contextmenu', function (e) { e.preventDefault(); return false; }, true);
    body.addEventListener('copy', function (e) { e.preventDefault(); }, true);
    body.addEventListener('cut', function (e) { e.preventDefault(); }, true);
    body.addEventListener('paste', function (e) { e.preventDefault(); }, true);
    var foot = mask.querySelector('.modal-foot');
    if (foot) {
        foot.innerHTML = '<span class="fs-12 text-muted">' + renderIconSvg('nav:lock') + ' 只读预览 — 内容不可编辑、复制，可滚动查看</span>';
    }
};

Clinic.pad3 = function (n) {
    n = parseInt(n, 10) || 0;
    return n < 10 ? '00' + n : (n < 100 ? '0' + n : '' + n);
};
/**
 * 就诊状态中文名（与后端 visit_status_name 同 map，SSOT 单一数据源）：
 * pending=待缴费 / paid=待就诊 / visiting=就诊中 / finished=就诊完毕 /
 * refunded=已退费 / cancelled=已取消。
 * @param {string} s 状态值
 * @param {object} overrides 可选文案覆盖（如打印中心 pending: '未缴费'、
 *                           候诊面板 paid: '候诊'，紧凑/语义差异经此注入）
 */
Clinic.visitStatusName = function (s, overrides) {
    var map = { pending: '待缴费', paid: '待就诊', visiting: '就诊中', finished: '就诊完毕', refunded: '已退费', cancelled: '已取消' };
    if (overrides) { for (var k in overrides) map[k] = overrides[k]; }
    return map[s] || s || '';
};
/** 开单类型中文名（与后端 order_type_name 同 map，SSOT 单一数据源） */
Clinic.orderTypeName = function (t) {
    var map = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
    return map[t] || t || '';
};
Clinic.money = function (n) {
    return '¥' + (parseFloat(n) || 0).toFixed(2);
};
Clinic.nl2br = function (s) {
    return (s || '').replace(/\n/g, '<br>');
};

Clinic.ui = {
    /**
     * 打开服务端渲染的表单弹窗并绑定保存
     */
    formModal: function (url, data, title, fields, onSaved) {        var mask = Clinic.modal.load(url, data, { title: title });
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
 * 收费处共用：退费 / 取消挂号（缴费管理 paymanage 与挂号管理 regmanage 共用，
 * 成功后的局部刷新差异由 onDone 注入）
 * @param {string}   visitId 就诊混淆串
 * @param {string}   status  'paid'=退费 / 其他=取消
 * @param {function} onDone  成功后回调（刷新列表/详情）
 */
Clinic.cashier = {
    cancelVisit: function (visitId, status, onDone) {
        var tip = status === 'paid' ? '确定为该挂号退费？退费后该患者可在同一首次科室重新挂号。' : '确定取消该挂号？';
        Clinic.modal.confirm(tip, function () {
            Clinic.modal.prompt({
                title: status === 'paid' ? '退费原因' : '取消原因',
                label: '请填写' + (status === 'paid' ? '退费' : '取消') + '原因（可留空）',
                placeholder: status === 'paid' ? '如：患者自愿退号' : '如：患者信息有误，需重新挂号',
                required: false,
                onOk: function (reason) {
                    Clinic.ajax('/api/cashier', { action: 'cancel_visit', visit_id: visitId, reason: reason }, {
                        onSuccess: function (json) {
                            Clinic.toast.success(json.msg);
                            if (onDone) onDone();
                        },
                    });
                },
            });
        }, { title: status === 'paid' ? '退费确认' : '取消确认' });
    },
};

/**
 * 支付方式选择模态框（挂号缴费 / 缴费管理共用）
 * 说明：现金始终可用；「移动支付」为微信/支付宝的统一入口，二者任一启用即可点击，
 * 点击后弹二级模态框选择微信/支付宝（仅列已启用项）；「银行卡」按接口管理
 * 银行卡刷卡配置启用（演示刷卡流程）；医保卡需独立接入，未开通。
 * @param {string}   title  弹窗标题（如「挂号费缴费」「批量缴费」）
 * @param {function} onDone 选择有效支付方式后的回调（method = 现金/微信/支付宝/银行卡）
 */
Clinic.payMethod = {
    open: function (title, onDone) {
        var bd = document.body;
        var canWechat = bd.getAttribute('data-paywechat') === '1';
        var canAlipay = bd.getAttribute('data-payalipay') === '1';
        var canBank = bd.getAttribute('data-paybank') === '1';
        var canMedicare = bd.getAttribute('data-paymedicare') === '1';
        var methods = [
            { k: '现金', icon: renderIconSvg('action:money'), name: '现金', desc: '现金支付（支持找零）', avail: 1 },
            { k: 'mobile', icon: renderIconSvg('nav:mobile'), name: '移动支付', desc: '微信 / 支付宝扫码', avail: (canWechat || canAlipay) ? 1 : 0 },
            { k: '银行卡', icon: renderIconSvg('nav:card'), name: '银行卡', desc: '刷卡 / 插卡（POS）', avail: canBank ? 1 : 0 },
            { k: '医保卡', icon: renderIconSvg('action:id-card'), name: '医保卡', desc: '医保卡实时结算', avail: canMedicare ? 1 : 0 },
        ];
        var render = function (list) {
            return '<div class="pay-methods">' + list.map(function (m) {
                return '<div class="pay-method' + (m.avail ? '' : ' disabled') + '" data-k="' + m.k + '">' +
                    '<div class="pay-method-icon">' + m.icon + '</div>' +
                    '<div class="pay-method-name">' + m.name + '</div>' +
                    '<div class="pay-method-desc">' + m.desc + '</div></div>';
            }).join('') + '</div>';
        };
        Clinic.modal.open(render(methods),
            { title: title + ' · 选择支付方式', size: 'modal-md', buttons: [{ text: '取消', cls: 'btn-outline' }] }
        );
        document.querySelectorAll('.pay-method').forEach(function (el) {
            el.addEventListener('click', function () {
                var k = el.getAttribute('data-k');
if (el.classList.contains('disabled')) {
                    var tip = (k === 'mobile')
                        ? '「移动支付」未启用（请在接口管理→医保与支付中启用微信或支付宝支付）'
                        : (k === '银行卡'
                            ? '「银行卡」未开通（请在接口管理→医保与支付中启用银行卡刷卡支付）'
                            : (k === '医保卡'
                                ? '「医保卡」未开通（请在接口管理→医保与支付中启用医保卡支付）'
                                : '「' + k + '」未开通（需独立接入，暂不可用）'));
                    Clinic.toast.info(tip);
                    return;
                }
                if (k === 'mobile') {
                    // 二级模态框：仅列已启用的移动支付渠道
                    var subs = [];
                    if (canWechat) subs.push({ k: '微信', icon: renderIconSvg('nav:mobile'), name: '微信支付', desc: '微信扫码支付', avail: 1 });
                    if (canAlipay) subs.push({ k: '支付宝', icon: renderIconSvg('nav:card'), name: '支付宝', desc: '支付宝扫码支付', avail: 1 });
                    Clinic.modal.open(render(subs),
                        { title: '移动支付 · 选择渠道', size: 'modal-md', buttons: [{ text: '返回', cls: 'btn-outline' }] }
                    );
                    document.querySelectorAll('.pay-method').forEach(function (s) {
                        s.addEventListener('click', function () {
                            Clinic.modal.close();
                            if (onDone) onDone(s.getAttribute('data-k'));
                        });
                    });
                    return;
                }
                if (k === '银行卡') {
                    // 刷卡演示：输入卡号模拟刷卡
                    Clinic.modal.open(
                        '<div class="form-group"><label class="form-label">银行卡号 <span class="req">*</span></label>' +
                        '<input class="input" id="bankCardNo" placeholder="请输入/刷卡读取卡号" autocomplete="off" style="font-family:monospace"></div>' +
                        '<div class="fs-12 text-muted">演示模式：录入卡号后确认即完成刷卡支付。</div>',
                        {
                            title: '银行卡刷卡',
                            size: 'modal-sm',
                            buttons: [
                                { text: '返回', cls: 'btn-outline' },
                                {
                                    text: '确认刷卡', cls: 'btn-primary',
                                    onClick: function () {
                                        var no = (document.getElementById('bankCardNo') || {}).value || '';
                                        no = no.replace(/\s+/g, '');
                                        if (!/^\d{12,19}$/.test(no)) { Clinic.toast.warning('请输入有效的银行卡号（12-19 位数字）'); return; }
                                        Clinic.modal.close();
                                        if (onDone) onDone('银行卡');
                                    },
                                },
                            ],
                        });
                    return;
                }
                Clinic.modal.close();
                if (onDone) onDone(k);
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

    /**
     * 退费申请详情 HTML（共享渲染：站内消息弹窗 / 独立审批页两处复用）：
     * 三张卡片（患者信息 / 审批进度 / 项目执行状态；原两份实现的间距漂移
     * 收敛为一份；流程步骤含已退费/已驳回关闭图标标记、退药数量带开立单位——
     * 取较新实现口径）。
     * @param {object} d { request, approvals, orders }
     * @returns {string}
     */
    refundDetailHtml: function (d) {
        var r = d.request || {}, approvals = d.approvals || [], orders = d.orders || [];
        var statusMap = {
            open: ['badge-warning', '待缴费'], paid: ['badge-primary', '已缴费'],
            reviewed: ['badge-warning', '审方通过待发药'], registered: ['badge-info', '已登记'], dispensing: ['badge-warning', '发药中'],
            dispensed: ['badge-success', '已发药'], done: ['badge-success', '已完成'],
            rejected: ['badge-danger', '已驳回'], refunded: ['badge-gray', '已退费'], cancelled: ['badge-gray', '已取消'],
        };
        // ---------- 卡片一：患者信息 ----------
        var html =
            '<div class="card">' +
            '<div class="flex-between"><div class="fw-700 fs-16">' + Clinic.escHtml(r.patient.name) +
            ' <span class="fs-12 text-muted fw-400">患者ID ' + Clinic.escHtml(r.patient.patient_no) +
            ' ｜ 流水号 ' + Clinic.escHtml(r.patient.flow_no) + '</span></div>' +
            '<span class="badge badge-warning">' + Clinic.visitStatusName(r.patient.visit_status) + '</span></div>' +
            '<div class="fs-13 mt-4">缴费批次：' + Clinic.escHtml(r.payment_no) + '</div>' +
            '<div class="fs-13 text-muted mt-4">申请时间：' + Clinic.escHtml(r.created_at) + '</div>' +
            (r.reason ? '<div class="fs-13 mt-4">申请理由：' + Clinic.escHtml(r.reason) + '</div>' : '') +
            '<div class="mt-4">状态：' +
            (r.status === 'approved' ? '<span class="badge badge-success">已全部同意</span>' :
                (r.status === 'rejected' ? '<span class="badge badge-danger">已拒绝</span>' : '<span class="badge badge-warning">待审批</span>')) + '</div>' +
            '</div>';
        // ---------- 卡片二：审批进度 ----------
        html += '<div class="card"><div class="fs-14 fw-700 mb-8">审批进度</div>';
        approvals.forEach(function (a) {
            var cls = a.verdict === 'approve' ? 'badge-success' : (a.verdict === 'reject' ? 'badge-danger' : 'badge-gray');
            var txt = a.verdict === 'approve' ? '已同意' : (a.verdict === 'reject' ? '已拒绝' : '待审批');
            html += '<div class="flex-between" style="padding:6px 0;border-top:1px dashed var(--border)">' +
                '<span class="fs-13">' + Clinic.escHtml(a.user_name) + ' <span class="fs-12 text-muted">（' + Clinic.escHtml(a.role) + '）</span>' +
                (a.note ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(a.note) + '</span>' : '') + '</span>' +
                '<span><span class="badge ' + cls + ' badge-xs">' + txt + '</span></span></div>';
        });
        html += '</div>';
        // ---------- 卡片三：项目执行状态 ----------
        html += '<div class="card"><div class="fs-14 fw-700 mb-8">项目执行状态</div>';
        orders.forEach(function (o) {
            html += '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px">' +
                '<div class="fs-13 fw-600">' + (Clinic.orderTypeName(o.order_type)) + ' ' + Clinic.escHtml(o.order_no) +
                ' ｜ 开单医生 ' + Clinic.escHtml(o.doctor_name) + '</div>';
            // 流程步骤：action:check 完成 / ○ 待执行 / action:close 已退费或已驳回（红色）
            var steps = (o.flow || []).map(function (s) {
                var refund = s.refunded;
                var cls = refund ? 'var(--danger)' : (s.done ? 'var(--success)' : 'var(--border)');
                if (s.rejected) cls = 'var(--danger)';
                return '<span style="color:' + cls + ';font-size:12px;white-space:nowrap">' +
                    (refund || s.rejected ? renderIconSvg('action:close') + ' ' : (s.done ? renderIconSvg('action:check') + ' ' : '○ ')) + Clinic.escHtml(s.label) + '</span>';
            }).join('<span style="color:var(--border)"> ' + renderIconSvg('action:next') + ' </span>');
            html += '<div style="margin:6px 0;overflow-x:auto;white-space:nowrap">' + steps + '</div>';
            (o.items || []).forEach(function (it) {
                var st = statusMap[it.status] || ['badge-gray', it.status || ''];
                // 退药数量带开立单位（2盒 / 3支），审批人核对拆零退药准确
                var itUnit = it.unit || '';
                html += '<div class="flex-between" style="padding:4px 0;border-top:1px dashed var(--border)">' +
                    '<span class="fs-13">· ' + Clinic.escHtml(it.name) + ' ×' + it.quantity + Clinic.escHtml(itUnit) + '</span>' +
                    '<span><span class="badge ' + st[0] + ' badge-xs">' + st[1] + '</span>' +
                    (it.executed_by ? ' <span class="fs-12 text-muted">' + Clinic.escHtml(it.executed_by) + '</span>' : '') + '</span></div>';
            });
            html += '</div>';
        });
        html += '</div>';
        return html;
    },

    _render: function (d) {
        var r = d.request || {}, approvals = d.approvals || [], orders = d.orders || [];
        var myName = document.body.getAttribute('data-name') || '';
        var myRole = document.body.getAttribute('data-role') || '';

        var html = this.refundDetailHtml(d);
        var canAct = r.status === 'pending' && approvals.some(function (a) { return a.user_name === myName; });
        if (r.status === 'pending' && (canAct || myRole === 'admin')) {
            html += '<div class="form-group" style="margin-top:14px"><label class="form-label">意见（可选）</label>' +
                '<textarea class="textarea" id="rapNote" rows="2" placeholder="如：患者已完成该检查，同意退费"></textarea></div>' +
                '<div class="flex gap-8 mt-8">' +
                '<button class="btn btn-danger" onclick="Clinic.refundApproval.vote(\'' + r.id + '\',\'reject\')">' + renderIconSvg('action:close') + ' 拒绝退费</button>' +
                '<button class="btn btn-primary" onclick="Clinic.refundApproval.vote(\'' + r.id + '\',\'approve\')">' + renderIconSvg('action:check') + ' 同意退费</button></div>';
        } else if (r.status === 'pending') {
            html += '<div class="fs-12 text-muted">您不是该申请的审批人，无法操作</div>';
        }

        Clinic.modal.open(
            '<div style="max-height:70vh;overflow-y:auto;padding-right:4px">' + html + '</div>',
            { title: renderIconSvg('emr:receipt') + ' 退费申请审批', size: 'modal-lg' }
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
