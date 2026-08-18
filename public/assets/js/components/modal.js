/**
 * ============================================================
 * modal.js v1.0.0 — 模态对话框组件
 * ============================================================
 * 说明：网站 AJAX 局部刷新的核心 UI：
 * 1. open(html, opts)      打开一个静态内容模态框
 * 2. load(url, opts)       通过 AJAX 加载内容到模态框
 * 3. confirm(msg, onOk)    确认对话框
 * 4. close()               关闭当前模态框
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.modal = (function () {
    /** 当前打开的遮罩元素 */
    let mask = null;

    /**
     * 创建遮罩结构
     */
    function createMask(html, opts) {
        opts = opts || {};
        mask = document.createElement('div');
        mask.className = 'modal-mask';
        mask.innerHTML =
            '<div class="modal ' + (opts.size || '') + '">' +
            '  <div class="modal-head">' +
            '    <div class="modal-title"></div>' +
            '    <button type="button" class="modal-close" aria-label="关闭">&times;</button>' +
            '  </div>' +
            '  <div class="modal-body"></div>' +
            '  <div class="modal-foot"></div>' +
            '</div>';
        document.body.appendChild(mask);

        // 标题与内容
        mask.querySelector('.modal-title').textContent = opts.title || '提示';
        mask.querySelector('.modal-body').innerHTML = html;

        // 底部按钮
        const foot = mask.querySelector('.modal-foot');
        if (opts.buttons) {
            opts.buttons.forEach(function (btn) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn ' + (btn.cls || 'btn-primary');
                b.textContent = btn.text || '确定';
                b.addEventListener('click', function () {
                    if (btn.onClick) btn.onClick(mask);
                    else if (btn.autoClose !== false) Clinic.modal.close();
                });
                foot.appendChild(b);
            });
        }

        // 关闭按钮与遮罩点击（点击遮罩空白处关闭）
        mask.querySelector('.modal-close').addEventListener('click', close);
        mask.addEventListener('click', function (e) {
            if (e.target === mask && opts.maskClose !== false) close();
        });

        // 键盘 Esc 关闭
        document.addEventListener('keydown', escHandler);

        // 显示动画
        requestAnimationFrame(function () { mask.classList.add('show'); });
        return mask;
    }

    /**
     * Esc 键关闭
     */
    function escHandler(e) {
        if (e.key === 'Escape') close();
    }

    /**
     * 打开一个静态内容模态框
     * @param {string} html 内容 HTML
     * @param {object} opts { title, size, buttons }
     */
    function open(html, opts) {
        close();
        return createMask(html, opts);
    }

    /**
     * 通过 AJAX 加载页面片段到模态框
     * @param {string} url  接口地址
     * @param {object} data 参数
     * @param {object} opts { title, size }
     */
    function load(url, data, opts) {
        opts = opts || {};
        const m = createMask('<div class="text-center" style="padding:30px"><div class="spinner" style="border-top-color:var(--primary)"></div></div>', opts);
        Clinic.ajax(url, data, {
            loading: false,
            onSuccess: function (json) {
                m.querySelector('.modal-body').innerHTML = json.data && json.data.html
                    ? json.data.html : (json.msg || '');
                // 触发内容加载完成事件（供页面绑定交互）
                m.querySelector('.modal-body').dispatchEvent(new CustomEvent('modal:loaded', { detail: json.data }));
            },
            onError: function () {
                setTimeout(close, 900);
            },
        });
        return m;
    }

    /**
     * 关闭当前模态框
     * 说明：必须先把 mask 置空并只移除「本次要关闭的弹窗」，
     * 否则在弹窗基础上再开新弹窗（如就诊历史 → 新增诊断证明）时，
     * 旧弹窗的延时移除回调会误删新弹窗（弹窗闪现后消失），
     * 且旧遮罩残留在页面上挡住所有点击（页面像死掉一样）。
     */
    function close() {
        if (mask) {
            var el = mask;
            mask = null;
            el.classList.remove('show');
            document.removeEventListener('keydown', escHandler);
            setTimeout(function () {
                el.remove();
            }, 180);
        }
    }

    /**
     * 确认对话框
     * @param {string}   msg    提示内容
     * @param {Function} onOk   确认回调
     * @param {object}   opts   { title, okText }
     */
    function confirm(msg, onOk, opts) {
        opts = opts || {};
        const html =
            '<div class="confirm-box">' +
            '  <div class="confirm-ico">!</div>' +
            '  <div class="confirm-text">' + msg + '</div>' +
            '</div>';
        open(html, {
            title: opts.title || '操作确认',
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline', onClick: close },
                {
                    text: opts.okText || '确定',
                    cls: 'btn-primary',
                    autoClose: false,
                    onClick: function () {
                        close();
                        if (onOk) onOk();
                    },
                },
            ],
        });
    }

    return { open: open, load: load, close: close, confirm: confirm };
})();
