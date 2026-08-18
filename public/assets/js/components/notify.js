/**
 * ============================================================
 * notify.js v1.1.0 — 站内消息组件
 * ============================================================
 * 说明：顶部栏消息铃铛：定时轮询未读消息数，
 * 点击弹出消息列表（区分【患者消息/系统消息】，患者消息显示患者姓名，
 * 点击患者消息跳转到关联的那次电子病历；审核驳回等带跳转链接的消息
 * 点击回到添加页面回填修改），支持标记已读。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.notify = (function () {
    /** 轮询定时器 */
    let timer = null;
    /** 铃铛角标元素 */
    let badge = null;
    /** 消息面板元素 */
    let panel = null;

    /**
     * 初始化消息中心
     * @param {string} badgeSel 角标选择器
     */
    function init(badgeSel) {
        badge = document.querySelector(badgeSel || '[data-msg-badge]');
        if (!badge) return;
        // 立即查询一次，然后每 30 秒轮询
        refresh();
        timer = setInterval(refresh, 30000);
    }

    /**
     * 查询未读消息数
     */
    function refresh() {
        Clinic.get('/api/message?action=unread_count', null, {
            onSuccess: function (json) {
                const n = json.data && json.data.count ? json.data.count : 0;
                if (badge) {
                    badge.textContent = n > 99 ? '99+' : n;
                    badge.style.display = n > 0 ? 'inline-flex' : 'none';
                }
            },
        });
    }

    /**
     * 消息点击：标记已读并跳转
     * 患者消息 → 该次就诊电子病历；带 link_url（审核驳回）→ 回填页面；
     * 其他带 visit_id → 病历页；仅打印类型 → 弹出打印预览
     * @param {object} m 消息记录
     * @param {HTMLElement} el 消息行元素
     */
    function onMsgClick(m, el) {
        Clinic.ajax('/api/message', { action: 'read', id: m.id }, { loading: false });
        if (el) el.classList.remove('unread');
        refresh();
        if (m.link_url) { location.href = m.link_url; return; }
        if (m.visit_id > 0) { location.href = '/doctor/emr?visit_id=' + m.visit_id; return; }
        if (m.print_url) { Clinic.print.load(m.print_url, null); return; }
    }

    /**
     * 单条消息 HTML
     * @param {object} m 消息记录
     */
    function itemHtml(m) {
        const isPatient = m.msg_type === 'patient';
        const typeBadge = isPatient
            ? '<span class="msg-type msg-type-patient">患者</span>'
            : '<span class="msg-type msg-type-system">系统</span>';
        const who = isPatient && m.patient_name
            ? '<span class="msg-who">👤 ' + m.patient_name + '</span>' : '';
        return '<div class="msg-item ' + (m.is_read ? '' : 'unread') +
            '" data-id="' + m.id + '" data-msg=\'' +
            JSON.stringify({ id: m.id, link_url: m.link_url || '', visit_id: m.visit_id || 0, print_url: m.print_url || '' }).replace(/'/g, '&#39;') +
            '\'>' +
            '<div class="msg-title-row">' + typeBadge + who +
            '<div class="msg-title ellipsis">' + m.title + '</div></div>' +
            '<div class="msg-content">' + m.content + '</div>' +
            '<div class="msg-time">' + m.created_at + '</div>' +
            '</div>';
    }

    /**
     * 打开消息列表面板
     */
    function openPanel() {
        Clinic.get('/api/message?action=list', null, {
            onSuccess: function (json) {
                const list = (json.data && json.data.list) || [];
                const bodyHtml = list.map(itemHtml).join('') || '<div class="dd-empty">暂无消息</div>';

                const pop = document.createElement('div');
                pop.className = 'dropdown-panel msg-panel';
                pop.innerHTML =
                    '<div class="msg-panel-head">' +
                    '<span class="msg-panel-title">🔔 消息通知</span>' +
                    '<a class="fs-12" href="/messages" style="color:var(--primary)">查看全部</a>' +
                    '</div>' +
                    '<div class="msg-panel-body">' + bodyHtml + '</div>';
                document.body.appendChild(pop);

                // 定位到铃铛下方
                const bell = document.querySelector('[data-msg-bell]');
                if (bell) {
                    const rect = bell.getBoundingClientRect();
                    pop.style.top = (rect.bottom + 6) + 'px';
                    pop.style.right = (window.innerWidth - rect.right) + 'px';
                } else {
                    pop.style.top = '64px';
                    pop.style.right = '20px';
                }

                // 点击消息：标记已读 + 跳转
                pop.querySelectorAll('.msg-item').forEach(function (el) {
                    el.addEventListener('click', function () {
                        let m = { id: el.getAttribute('data-id') };
                        try { m = JSON.parse(el.getAttribute('data-msg')); } catch (e) { /* ignore */ }
                        onMsgClick(m, el);
                    });
                });

                // 点击外部关闭
                setTimeout(function () {
                    document.addEventListener('click', function closeHandler(e) {
                        if (!pop.contains(e.target) && (!bell || !bell.contains(e.target))) {
                            pop.remove();
                            document.removeEventListener('click', closeHandler);
                        }
                    });
                }, 50);
            },
        });
    }

    return { init: init, refresh: refresh, openPanel: openPanel };
})();
