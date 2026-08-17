/**
 * ============================================================
 * editor.js v1.0.0 — 病历所见即所得编辑器
 * ============================================================
 * 说明：病历书写采用内容可编辑区域（contenteditable），
 * 而非简单输入框：
 * 1. 支持加粗/斜体/下划线/项目符号等格式
 * 2. 支持占位符（空内容时显示灰色提示文字）
 * 3. 提供 get()/set() 读取与写入内容
 * 4. 医院名称、患者信息等区域不可编辑（非 contenteditable）
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.editor = (function () {
    /**
     * 初始化一个可编辑区域
     * @param {string} selector    元素选择器
     * @param {string} placeholder 占位文字
     * @returns {object} 编辑器控制接口
     */
    function create(selector, placeholder) {
        const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!el) return null;

        el.setAttribute('contenteditable', 'true');
        el.classList.add('rich-editor');
        el.setAttribute('data-placeholder', placeholder || '请输入内容…');
        el.setAttribute('spellcheck', 'false');

        // 空内容时显示占位符
        function checkPlaceholder() {
            el.classList.toggle('is-empty', el.textContent.trim() === '');
        }
        el.addEventListener('input', checkPlaceholder);
        el.addEventListener('blur', checkPlaceholder);
        checkPlaceholder();

        return {
            /** 获取 HTML 内容 */
            get: function () { return el.innerHTML; },
            /** 设置 HTML 内容 */
            set: function (html) {
                el.innerHTML = html || '';
                checkPlaceholder();
            },
            /** 清空 */
            clear: function () { el.innerHTML = ''; checkPlaceholder(); },
            /** 校验是否为空（去除标签后判断） */
            isEmpty: function () {
                return el.textContent.trim() === '';
            },
            /** 获取纯文本 */
            text: function () { return el.textContent.trim(); },
            /** 获取元素 */
            el: el,
        };
    }

    /**
     * 初始化带工具栏的编辑器组
     * @param {string} toolbarSel 工具栏选择器
     * @param {string} targetSel  内容区选择器
     */
    function initToolbar(toolbarSel, targetSel) {
        const toolbar = document.querySelector(toolbarSel);
        const target = document.querySelector(targetSel);
        if (!toolbar || !target) return;
        toolbar.setAttribute('contenteditable', 'false');

        toolbar.addEventListener('mousedown', function (e) {
            e.preventDefault();   // 防止工具栏点击丢失焦点
            const btn = e.target.closest('[data-cmd]');
            if (!btn) return;
            const cmd = btn.getAttribute('data-cmd');
            const val = btn.getAttribute('data-value') || null;
            document.execCommand(cmd, false, val);
            target.focus();
        });
    }

    /**
     * 工具栏按钮 HTML 生成
     * @param {Array} items [{cmd, label, title, value}]
     * @returns {string} HTML
     */
    function toolbarHtml(items) {
        return items.map(function (it) {
            return '<button type="button" class="ed-btn" data-cmd="' + it.cmd + '"' +
                (it.value ? ' data-value="' + it.value + '"' : '') +
                ' title="' + (it.title || it.label) + '">' + it.label + '</button>';
        }).join('');
    }

    return { create: create, initToolbar: initToolbar, toolbarHtml: toolbarHtml };
})();
