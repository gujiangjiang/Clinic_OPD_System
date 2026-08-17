/**
 * ============================================================
 * print.js v1.0.0 — 统一打印模块
 * ============================================================
 * 说明：所有单据打印（挂号凭条、病历、处方、申请单、
 * 检验检查报告、诊断证明、缴费凭条）统一走此模块：
 * 1. 将内容渲染到 #print-area（服务端返回 HTML 片段）
 * 2. 显示打印预览层（含打印/关闭按钮）
 * 3. 点击打印调用 window.print()，print.css 保证只打印单据
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.print = (function () {
    /** 预览层元素 */
    let preview = null;

    /**
     * 打印指定 HTML 内容
     * @param {string} html      单据 HTML（含 print-area 内部结构）
     * @param {string} title     预览标题（可空）
     */
    function open(html, title) {
        // 关闭已有预览
        close();

        preview = document.createElement('div');
        preview.className = 'print-preview';
        preview.innerHTML =
            '<div class="print-toolbar">' +
            '  <button type="button" class="btn btn-outline" data-act="close">关闭</button>' +
            '  <button type="button" class="btn btn-primary" data-act="do">🖨️ 打印</button>' +
            '</div>' +
            '<div id="print-area" class="print-area">' + html + '</div>';
        document.body.appendChild(preview);

        // 绑定工具栏
        preview.querySelector('[data-act="close"]').addEventListener('click', close);
        preview.querySelector('[data-act="do"]').addEventListener('click', function () {
            window.print();
        });

        // 允许 ESC 关闭
        document.addEventListener('keydown', escHandler);
        return preview;
    }

    /**
     * 从接口加载单据内容并打印
     * @param {string} url  接口地址
     * @param {object} data 参数
     */
    function load(url, data) {
        Clinic.ajax(url, data, {
            loading: true,
            onSuccess: function (json) {
                if (json.data && json.data.html) {
                    open(json.data.html, json.data.title || '');
                } else {
                    Clinic.toast.error('打印内容获取失败');
                }
            },
        });
    }

    /**
     * 关闭打印预览
     */
    function close() {
        if (preview) {
            preview.remove();
            preview = null;
            document.removeEventListener('keydown', escHandler);
        }
    }

    /**
     * Esc 关闭
     */
    function escHandler(e) {
        if (e.key === 'Escape') close();
    }

    return { open: open, load: load, close: close };
})();
