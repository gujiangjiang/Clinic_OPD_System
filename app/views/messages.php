<?php
/**
 * messages.php — 站内消息中心
 * 说明：通知方式为【纯站内消息 + 打印提醒】：
 * 开单、报告完成、处置完成、发药完成等事件均通过站内消息通知，
 * 含打印类型的消息附带【打印】按钮（如报告/申请单补打）。
 */
Router::title('站内消息');
?>
<div class="page-head">
    <div><div class="page-title">💬 站内消息</div><div class="page-desc">系统内所有业务提醒与打印提醒</div></div>
    <button class="btn btn-outline btn-sm" onclick="loadMsgs()">刷新</button>
</div>
<div class="card" id="msgBox"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></div>

<script>
function loadMsgs() {
    Clinic.get('/api/message?action=all', null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var box = document.getElementById('msgBox');
            if (!list.length) {
                box.innerHTML = '<div class="empty"><div class="empty-ico">📭</div>暂无消息</div>';
                return;
            }
            box.innerHTML = '<div class="flex-between mb-12"><span class="fs-13 text-muted">共 ' + list.length + ' 条消息</span>' +
                '<div class="flex gap-4">' +
                '<button class="btn btn-outline btn-sm" onclick="markAll()">全部已读</button>' +
                '<button class="btn btn-outline btn-sm" onclick="clearAll()">🗑 一键清空</button>' +
                '</div></div>' +
                list.map(function (m) {
                    var btn = '';
                    if (m.print_type === 'pwd_reset') {
                        // 密码重置：管理员审核通过后，无需原密码直接设置新密码
                        btn = '<button class="btn btn-warning btn-sm" onclick="event.stopPropagation();openResetPwd()">🔑 设置新密码</button>';
                    } else if (m.print_url) {
                        // 纸张路由：凭条类=窄条凭条纸，其余默认
                        var psheet = m.print_url.indexOf('action=receipt') !== -1 || m.print_url.indexOf('action=payment') !== -1 ? 'ticket' : '';
                        btn = '<button class="btn btn-outline btn-sm" onclick="event.stopPropagation();Clinic.print.load(\'' + m.print_url + '\',null,\'' + psheet + '\')">🖨️ 打印</button>';
                    }
                    var isPatient = m.msg_type === 'patient';
                    var typeBadge = isPatient
                        ? '<span class="msg-type msg-type-patient">患者</span>'
                        : '<span class="msg-type msg-type-system">系统</span>';
                    var who = isPatient && m.patient_name
                        ? '<span class="msg-who">👤 ' + m.patient_name + '</span>' : '';
                    var jump = '';
                    if (m.link_url) jump = m.link_url;
                    else if (m.visit_id) jump = '/doctor/emr?visit_id=' + encodeURIComponent(m.visit_id);
                    return '<div class="msg-item ' + (m.is_read ? '' : 'unread') + '" style="display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid var(--border);cursor:pointer" data-id="' + m.id + '" data-jump="' + jump + '">' +
                        '<div style="flex:1;min-width:0">' +
                        '  <div class="msg-title-row">' + typeBadge + who +
                        '    <div class="fw-600 fs-14 ellipsis">' + m.title + '</div></div>' +
                        '  <div class="fs-13 text-muted">' + m.content + '</div>' +
                        '  <div class="fs-12 text-muted mt-4">' + m.created_at + ' ｜ 来自 ' + m.from_name + '</div>' +
                        '</div>' +
                        '<div class="flex gap-4">' + btn +
                        '<button class="btn btn-outline btn-sm" title="删除" onclick="event.stopPropagation();delMsg(' + m.id + ')">🗑</button>' +
                        '</div></div>';
                }).join('');
            box.querySelectorAll('.msg-item').forEach(function (el) {
                el.addEventListener('click', function () {
                    Clinic.ajax('/api/message', { action: 'read', id: el.getAttribute('data-id') }, { loading: false });
                    el.classList.remove('unread');
                    Clinic.notify.refresh();
                    var jump = el.getAttribute('data-jump');
                    if (jump) location.href = jump;
                });
            });
        },
    });
}
/* 密码重置：无需验证原密码，直接设置新密码 */
function openResetPwd() {
    Clinic.modal.open(
        '<div class="form-group"><label class="form-label">新密码（至少6位）<span class="req">*</span></label>' +
        '<input type="password" class="input" id="rpNew" autocomplete="new-password"></div>' +
        '<div class="form-group"><label class="form-label">确认新密码 <span class="req">*</span></label>' +
        '<input type="password" class="input" id="rpNew2" autocomplete="new-password"></div>' +
        '<div class="fs-12 text-muted">管理员已审核通过您的密码重置申请，无需验证原密码。</div>',
        {
            title: '设置新密码',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '确认重置', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var n1 = document.getElementById('rpNew').value;
                        var n2 = document.getElementById('rpNew2').value;
                        if (n1.length < 6) { Clinic.toast.warning('新密码不能少于6位'); return; }
                        if (n1 !== n2) { Clinic.toast.warning('两次输入的新密码不一致'); return; }
                        Clinic.ajax('/api/auth', { action: 'reset_password', new_password: n1 }, {
                            onSuccess: function (json) {
                                Clinic.toast.success(json.msg);
                                Clinic.modal.close();
                                loadMsgs();
                            },
                        });
                    },
                },
            ],
        }
    );
}

function markAll() {
    // 一次性标记全部已读（后端原子操作），避免逐个异步请求导致角标计数竞态
    Clinic.ajax('/api/message', { action: 'read_all' }, {
        onSuccess: function (json) {
            document.querySelectorAll('.msg-item.unread').forEach(function (el) {
                el.classList.remove('unread');
            });
            Clinic.toast.success(json.msg);
            Clinic.notify.refresh();
        },
    });
}

/* 删除单条消息 */
function delMsg(id) {
    Clinic.modal.confirm('确定删除这条消息？', function () {
        Clinic.ajax('/api/message', { action: 'delete', id: id }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadMsgs();
                Clinic.notify.refresh();
            },
        });
    }, { title: '删除消息' });
}

/* 一键清空所有消息 */
function clearAll() {
    Clinic.modal.confirm('确定清空所有消息？删除后不可恢复。', function () {
        Clinic.ajax('/api/message', { action: 'clear_all' }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                loadMsgs();
                Clinic.notify.refresh();
            },
        });
    }, { title: '一键清空', okText: '全部清空' });
}
loadMsgs();
</script>
