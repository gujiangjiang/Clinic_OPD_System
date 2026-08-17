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
                '<button class="btn btn-outline btn-sm" onclick="markAll()">全部已读</button></div>' +
                list.map(function (m) {
                    var btn = '';
                    if (m.print_url) {
                        btn = '<button class="btn btn-outline btn-sm" onclick="event.stopPropagation();Clinic.print.load(\'' + m.print_url + '\',null)">🖨️ 打印</button>';
                    }
                    return '<div class="msg-item ' + (m.is_read ? '' : 'unread') + '" style="display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid var(--border);cursor:pointer" data-id="' + m.id + '">' +
                        '<div style="flex:1;min-width:0">' +
                        '  <div class="fw-600 fs-14">' + m.title + '</div>' +
                        '  <div class="fs-13 text-muted">' + m.content + '</div>' +
                        '  <div class="fs-12 text-muted mt-4">' + m.created_at + ' ｜ 来自 ' + m.from_name + '</div>' +
                        '</div>' + btn + '</div>';
                }).join('');
            box.querySelectorAll('.msg-item').forEach(function (el) {
                el.addEventListener('click', function () {
                    Clinic.ajax('/api/message', { action: 'read', id: el.getAttribute('data-id') }, { loading: false });
                    el.classList.remove('unread');
                });
            });
        },
    });
}
function markAll() {
    document.querySelectorAll('.msg-item.unread').forEach(function (el) {
        Clinic.ajax('/api/message', { action: 'read', id: el.getAttribute('data-id') }, { loading: false });
        el.classList.remove('unread');
    });
    Clinic.toast.success('已全部标记为已读');
    Clinic.notify.refresh();
}
loadMsgs();
</script>
