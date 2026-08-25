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
    <div class="flex gap-8">
        <button class="btn btn-primary btn-sm" onclick="openSendMsg()">✉️ 发送消息</button>
        <button class="btn btn-outline btn-sm" onclick="openSent()">📤 已发送</button>
        <button class="btn btn-outline btn-sm" onclick="loadMsgs()">刷新</button>
    </div>
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
                        : (m.msg_type === 'user'
                            ? '<span class="msg-type msg-type-user">用户</span>'
                            : '<span class="msg-type msg-type-system">系统</span>');
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

/* ==================== 发送消息 ====================
 * 管理员：三级分类多选（全院 → 角色组 → 个人），全院/角色勾选自动联动全选
 * 普通用户：同结构单选（仅可选一位用户），后端 30 秒限流 */
var SEND_GROUPS = [];
var SEND_ADMIN = false;
function openSendMsg() {
    Clinic.get('/api/message?action=contacts', null, {
        onSuccess: function (json) {
            SEND_GROUPS = json.data.groups || [];
            SEND_ADMIN = !!json.data.is_admin;
            var pickType = SEND_ADMIN ? 'checkbox' : 'radio';
            /* 可折叠三级树：L1 全院（仅管理员）→ L2 角色组 → L3 用户；
               默认全部折叠，点击 +/− 按需展开 */
            var tree = '';
            if (SEND_ADMIN) {
                var roleBlocks = SEND_GROUPS.map(function (g) {
                    var users = g.users.map(function (u2) {
                        return '<label class="send-user"><input type="checkbox" name="smUser" class="sm-user" data-role="' + g.role + '" value="' + u2.id + '" onchange="smUserChange(this)">' + u2.name +
                            ' <span class="fs-12 text-muted">' + (u2.emp_no || '') + '</span></label>';
                    }).join('');
                    return '<div class="send-grp">' +
                        '<div class="send-grp-head-row">' +
                        '<button type="button" class="tree-toggle" onclick="treeToggle(this)" data-toggle="smG_' + g.role + '">+</button>' +
                        '<label class="send-grp-head"><input type="checkbox" class="sm-role" data-role="' + g.role + '" onchange="smToggleRole(\'' + g.role + '\', this.checked)"> <b>' + g.role_name + '</b>（' + g.users.length + ' 人）</label>' +
                        '</div>' +
                        '<div class="send-grp-children send-tree-level-3" id="smG_' + g.role + '" style="display:none">' + users + '</div>' +
                        '</div>';
                }).join('');
                tree =
                    '<div class="send-grp">' +
                    '<div class="send-grp-head-row">' +
                    '<button type="button" class="tree-toggle" onclick="treeToggle(this)" data-toggle="smL2">+</button>' +
                    '<label class="send-grp-head"><input type="checkbox" id="smAll" onchange="smToggleAll(this.checked)"> <b>全院（全部用户）</b></label>' +
                    '</div>' +
                    '<div class="send-grp-children send-tree-level-2" id="smL2" style="display:none">' + roleBlocks + '</div>' +
                    '</div>';
            } else {
                tree = SEND_GROUPS.map(function (g) {
                    var users = g.users.map(function (u2) {
                        return '<label class="send-user"><input type="radio" name="smUser" value="' + u2.id + '">' + u2.name +
                            ' <span class="fs-12 text-muted">' + (u2.emp_no || '') + '</span></label>';
                    }).join('');
                    return '<div class="send-grp">' +
                        '<div class="send-grp-head-row">' +
                        '<button type="button" class="tree-toggle" onclick="treeToggle(this)" data-toggle="smG_' + g.role + '">+</button>' +
                        '<div class="send-grp-head"><b>' + g.role_name + '</b>（' + g.users.length + ' 人）</div>' +
                        '</div>' +
                        '<div class="send-grp-children send-tree-level-3" id="smG_' + g.role + '" style="display:none">' + users + '</div>' +
                        '</div>';
                }).join('');
            }
            var html =
                '<div class="send-msg-box">' +
                '  <div class="fs-13 text-muted mb-8">' + (SEND_ADMIN ? '可多选群发（全院 / 按角色 / 指定用户）' : '仅可发送给一位用户，两次发送间隔 30 秒') + '</div>' +
                '  <input class="input" id="smSearch" placeholder="🔍 搜索用户 / 工号，可定位到列表" autocomplete="off">' +
                '  <div id="smSearchRes" class="tree-search-res" style="display:none"></div>' +
                '  <div class="send-tree" id="sendMsgTree">' + tree + '</div>' +
                '  <div class="form-group mt-12"><label class="form-label">标题</label>' +
                '    <input class="input" id="smTitle" maxlength="50" placeholder="请输入标题（50 字以内）"></div>' +
                '  <div class="form-group"><label class="form-label">内容</label>' +
                '    <textarea class="textarea" id="smContent" rows="4" maxlength="500" placeholder="请输入内容（500 字以内）"></textarea></div>' +
                '</div>';
            Clinic.modal.open(html, {
                title: '✉️ 发送消息',
                size: 'modal-sm',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    { text: '发送', cls: 'btn-primary', autoClose: false, onClick: doSendMsg },
                ],
            });
            Clinic.treeSearch({ input: 'smSearch', res: 'smSearchRes', tree: '#sendMsgTree' });
        },
    });
}
function smToggleAll(checked) {
    document.querySelectorAll('.send-msg-box .sm-role, .send-msg-box .sm-user').forEach(function (c) { c.checked = checked; });
    document.querySelectorAll('.send-msg-box .sm-role').forEach(function (c) { c.indeterminate = false; });
}
function smToggleRole(role, checked) {
    document.querySelectorAll('.send-msg-box .sm-user[data-role="' + role + '"]').forEach(function (c) { c.checked = checked; });
}
function smUserChange() {
    // 角色组复选框联动：全选=勾选 / 部分选=半选态 / 全不选=空
    document.querySelectorAll('.send-msg-box .sm-role').forEach(function (rc) {
        var role = rc.getAttribute('data-role');
        var users = document.querySelectorAll('.send-msg-box .sm-user[data-role="' + role + '"]');
        var n = 0; users.forEach(function (u2) { if (u2.checked) n++; });
        rc.checked = n === users.length;
        rc.indeterminate = n > 0 && n < users.length;
    });
    // 任一个人勾选 → 取消全院主勾选（避免语义冲突）
    var anyUser = document.querySelector('.send-msg-box .sm-user:checked');
    var all = document.getElementById('smAll');
    if (all && anyUser) { all.checked = false; all.indeterminate = false; }
}
function doSendMsg() {
    var title = document.getElementById('smTitle').value.trim();
    var content = document.getElementById('smContent').value.trim();
    if (!title) { Clinic.toast.warning('请填写标题'); return; }
    if (!content) { Clinic.toast.warning('请填写内容'); return; }
    var ids = [];
    if (SEND_ADMIN) {
        document.querySelectorAll('.send-msg-box .sm-user:checked').forEach(function (c) { ids.push(parseInt(c.value, 10)); });
        if (!ids.length) { Clinic.toast.warning('请勾选接收者（全院 / 角色组 / 个人）'); return; }
    } else {
        var r = document.querySelector('.send-msg-box input[name="smUser"]:checked');
        if (!r) { Clinic.toast.warning('请选择一位接收用户'); return; }
        ids.push(parseInt(r.value, 10));
    }
    Clinic.ajax('/api/message', {
        action: 'send',
        title: title,
        content: content,
        recipients: JSON.stringify(ids),
    }, {
        onSuccess: function (json) {
            Clinic.modal.close();
            Clinic.toast.success(json.msg);
            loadMsgs();
            Clinic.notify.refresh();
        },
    });
}
/* ==================== 已发送（发送日志，删除不影响接收者） ==================== */
function openSent() {
    Clinic.get('/api/message?action=sent_list', null, {
        onSuccess: function (json) {
            var list = json.data.list || [];
            var body = list.length
                ? '<div class="flex-between mb-8"><span class="fs-13 text-muted">共 ' + list.length + ' 条发送记录</span>' +
                  '<button class="btn btn-outline btn-sm" onclick="sentClear()">🗑 一键清空</button></div>' +
                  list.map(function (m) {
                      return '<div class="sent-item" style="display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid var(--border)">' +
                          '<input type="checkbox" class="sent-check" value="' + m.id + '">' +
                          '<div style="flex:1;min-width:0">' +
                          '  <div class="fw-600 fs-14 ellipsis">' + m.title + ' <span class="fs-12 text-muted fw-400">（' + (m.recipient_count || 1) + ' 人）</span></div>' +
                          '  <div class="fs-13 text-muted ellipsis">接收者：' + m.recipients + '</div>' +
                          '  <div class="fs-13 text-muted ellipsis">' + m.content + '</div>' +
                          '  <div class="fs-12 text-muted mt-4">' + m.created_at + '</div>' +
                          '</div></div>';
                  }).join('')
                : '<div class="empty"><div class="empty-ico">📤</div>暂无发送记录</div>';
            var foot = list.length
                ? '<div class="flex gap-8 mt-12"><button class="btn btn-danger btn-sm" onclick="sentDelChecked()">🗑 删除选中</button></div>'
                : '';
            Clinic.modal.open(
                '<div class="sent-list-box">' + body + foot + '</div>',
                { title: '📤 已发送的消息', size: 'modal-lg' }
            );
        },
    });
}
function sentDelChecked() {
    var ids = [];
    document.querySelectorAll('.sent-list-box .sent-check:checked').forEach(function (c) { ids.push(parseInt(c.value, 10)); });
    if (!ids.length) { Clinic.toast.warning('请先勾选要删除的记录'); return; }
    Clinic.ajax('/api/message', { action: 'sent_delete', ids: JSON.stringify(ids) }, {
        onSuccess: function (json) {
            Clinic.toast.success(json.msg);
            Clinic.modal.close();
            openSent();
        },
    });
}
function sentClear() {
    Clinic.modal.confirm('确定清空所有发送记录？接收者已收到的消息不受影响。', function () {
        Clinic.ajax('/api/message', { action: 'sent_clear' }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.modal.close();
            },
        });
    }, { title: '清空发送记录', okText: '全部清空' });
}
loadMsgs();
</script>
