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
     * @param {string} sheet     纸张类型：'a5' = 病历纸 A5 竖版（窄长条），其他不传
     */
    function open(html, title, sheet) {
        // 关闭已有预览
        close();

        preview = document.createElement('div');
        preview.className = 'print-preview' + (sheet ? ' sheet-' + sheet : '');
        preview.innerHTML =
            '<div class="print-toolbar">' +
            '  <button type="button" class="btn btn-outline" data-act="close">关闭</button>' +
            '  <button type="button" class="btn btn-primary" data-act="do">🖨️ 打印</button>' +
            '</div>' +
            '<div id="print-area" class="print-area">' + html + '</div>';
        document.body.appendChild(preview);

        // 按纸张类型注入打印页面尺寸（A5 病历纸：148×210mm 竖版）
        applyPageSize(sheet);

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
     * @param {string} url   接口地址
     * @param {object} data  参数
     * @param {string} sheet 纸张类型：'a5' = 病历纸 A5 竖版，其他不传
     */
    function load(url, data, sheet) {
        Clinic.ajax(url, data, {
            loading: true,
            onSuccess: function (json) {
                if (json.data && json.data.html) {
                    open(json.data.html, json.data.title || '', sheet);
                } else {
                    Clinic.toast.error('打印内容获取失败');
                }
            },
        });
    }

    /**
     * 按纸张类型注入 / 移除打印页面尺寸规则
     * @param {string} sheet 'a5' 时使用 A5 竖版纸张，其他情况用默认纸张
     */
    function applyPageSize(sheet) {
        var st = document.getElementById('printPageSize');
        if (st) st.remove();
        if (sheet === 'a5') {
            st = document.createElement('style');
            st.id = 'printPageSize';
            st.textContent = '@page { size: A5 portrait; margin: 10mm; }';
            document.head.appendChild(st);
        }
    }

    /**
     * 关闭打印预览
     */
    function close() {
        if (preview) {
            preview.remove();
            preview = null;
            document.removeEventListener('keydown', escHandler);
            var st = document.getElementById('printPageSize');
            if (st) st.remove();
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
