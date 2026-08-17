/**
 * ============================================================
 * notify.js v1.0.0 — 站内消息组件
 * ============================================================
 * 说明：顶部栏消息铃铛：定时轮询未读消息数，
 * 点击弹出消息列表，支持标记已读、跳转相关页面。
 * 审批结果、医嘱执行反馈等通过站内消息提醒。
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
     * 打开消息列表面板
     */
    function openPanel() {
        Clinic.get('/api/message?action=list', null, {
            onSuccess: function (json) {
                const list = (json.data && json.data.list) || [];
                const html = list.map(function (m) {
                    return '<div class="msg-item ' + (m.is_read ? '' : 'unread') +
                        '" data-id="' + m.id + '">' +
                        '<div class="msg-title">' + m.title + '</div>' +
                        '<div class="msg-time text-muted fs-12">' + m.created_at + '</div>' +
                        '</div>';
                }).join('') || '<div class="dd-empty">暂无消息</div>';

                const pop = document.createElement('div');
                pop.className = 'dropdown-panel msg-panel';
                pop.innerHTML = html;
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

                // 点击消息标记已读
                pop.querySelectorAll('.msg-item').forEach(function (el) {
                    el.addEventListener('click', function () {
                        const id = el.getAttribute('data-id');
                        Clinic.ajax('/api/message', { action: 'read', id: id }, { loading: false });
                        el.classList.remove('unread');
                        refresh();
                    });
                });

                // 点击外部关闭
                setTimeout(function () {
                    document.addEventListener('click', function closeHandler(e) {
                        if (!pop.contains(e.target) && !bell.contains(e.target)) {
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
