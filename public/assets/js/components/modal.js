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
    /** 模态框栈：支持层叠（如诊断选择 → 二级编辑弹窗），close 只关闭栈顶 */
    let masks = [];

    /**
     * 创建遮罩结构
     */
    function createMask(html, opts) {
        opts = opts || {};
        const mask = document.createElement('div');
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
        masks.push(mask);

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

        // 键盘 Esc 关闭（全局单监听，按栈深启停）
        syncEsc();

        // 显示动画
        requestAnimationFrame(function () { mask.classList.add('show'); });
        return mask;
    }

    /**
     * Esc 键关闭（栈顶）
     */
    function escHandler(e) {
        if (e.key === 'Escape') close();
    }

    /** 按栈深启停唯一的全局 Esc 监听（避免多层时一次 Esc 全部关闭） */
    function syncEsc() {
        if (masks.length) document.addEventListener('keydown', escHandler);
        else document.removeEventListener('keydown', escHandler);
    }

    /**
     * 打开一个静态内容模态框
     * @param {string} html 内容 HTML
     * @param {object} opts { title, size, buttons }
     */
    function open(html, opts) {
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
     * 关闭栈顶模态框
     * 说明：模态框支持层叠（如 诊断选择弹窗 → 二级编辑弹窗），
     * close 只弹出栈顶；下层弹窗保持可见可交互。
     */
    function close() {
        if (!masks.length) return;
        const el = masks.pop();
        el.classList.remove('show');
        syncEsc();
        setTimeout(function () {
            el.remove();
        }, 180);
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
