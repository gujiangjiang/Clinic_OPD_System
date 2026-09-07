/**
 * ============================================================
 * emr_ctxmenu.js v1.0.0 — 病历输入框自定义右键菜单
 * ============================================================
 * 说明：病历书写页（#emrCard）输入框（input / textarea / 富文本
 * contenteditable 字段）的右键菜单自定义组件，替代浏览器原生右键菜单，
 * 防止用户在病历界面调出系统菜单：
 * 1. 普通输入框：复制 / 剪切 / 粘贴 / 清空
 * 2. 嘱托输入框（data-k="advice"）：额外提供「模板」项（预留，后期完善）
 * 3. 菜单项点击即执行并自动关闭；点菜单外部 / Esc / 滚动即关闭
 * 4. 颜色全部走 CSS 变量（--bg-card/--border/--primary/--text-*），
 *    自动适配明暗主题
 * 触发方式：emr.js 的 #emrCard contextmenu 委托命中输入框后调用
 * Clinic.emrMenu.show(ev)，本组件内部 preventDefault 屏蔽原生菜单。
 * 依赖：Clinic.toast（可选，仅提示用）。
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.emrMenu = (function () {

    var menu = null;      // 当前菜单元素
    var field = null;     // 当前右键目标输入框

    /* ==================== 文本读取 / 选区 ==================== */

    /** 读取输入框当前全文（contenteditable 以 innerText 为准） */
    function fieldText(el) {
        if (el.isContentEditable) return String(el.innerText || '').replace(/\u00a0/g, ' ');
        return String(el.value || '');
    }

    /**
     * 读取输入框内当前选区：返回 {start, end}（字符偏移，全文坐标系）。
     * 输入框（input/textarea）用 selectionStart/selectionEnd；
     * 富文本字段用 Selection + 前置 Range 推算偏移。无选区返回 null。
     */
    function fieldRange(el) {
        if (!el.isContentEditable) {
            try {
                var s = el.selectionStart, e = el.selectionEnd;
                if (s == null || e == null) return null;
                return { start: s, end: e };
            } catch (err) { return null; }
        }
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return null;
        var r = sel.getRangeAt(0);
        if (!el.contains(r.commonAncestorContainer)) return null;
        var pre = document.createRange();
        pre.selectNodeContents(el);
        pre.setEnd(r.startContainer, r.startOffset);
        return { start: pre.toString().length, end: pre.toString().length + r.toString().length };
    }

    /* ==================== 剪贴板 ==================== */

    function copyText(text) {
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {}, function () { fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    /* ==================== 输入框操作 ==================== */

    /** 聚焦输入框；无选区时把光标放到末尾 */
    function focusField(el) {
        el.focus();
        var r = fieldRange(el);
        if (!r || r.end <= r.start) {
            if (el.isContentEditable) {
                var range = document.createRange();
                range.selectNodeContents(el);
                range.collapse(false);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            } else {
                try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {}
            }
        }
    }

    /** 删除输入框当前选区（无选区则不动） */
    function deleteSelection(el) {
        el.focus();
        if (!el.isContentEditable) {
            var r = fieldRange(el);
            if (!r) return;
            var v = el.value;
            el.value = v.slice(0, r.start) + v.slice(r.end);
            try { el.setSelectionRange(r.start, r.start); } catch (e) {}
            return;
        }
        try { document.execCommand('delete'); } catch (e) {}
    }

    /** 清空输入框内容 */
    function clearField(el) {
        if (el.isContentEditable) el.innerText = '';
        else el.value = '';
        markChanged(el);
    }

    /** 光标处插入纯文本（contenteditable 用 execCommand insertText 以触发
     *  既有输入事件；失败则手动插入文本节点兜底） */
    function insertPlain(el, text) {
        text = String(text).replace(/[\r\n]+/g, '');
        if (!el.isContentEditable) {
            var v = el.value;
            var s = el.selectionStart, e = el.selectionEnd;
            if (s == null || e == null) s = e = v.length;
            el.value = v.slice(0, s) + text + v.slice(e);
            try { el.setSelectionRange(s + text.length, s + text.length); } catch (err) {}
        } else {
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || !el.contains(sel.getRangeAt(0).commonAncestorContainer)) {
                var range = document.createRange();
                range.selectNodeContents(el);
                range.collapse(false);
                sel.removeAllRanges();
                sel.addRange(range);
            }
            var ok = false;
            try { ok = document.execCommand('insertText', false, text); } catch (err) {}
            if (!ok) {
                var s2 = window.getSelection();
                if (s2 && s2.rangeCount) {
                    var r2 = s2.getRangeAt(0);
                    r2.deleteContents();
                    var node = document.createTextNode(text);
                    r2.insertNode(node);
                    r2.setStartAfter(node);
                    r2.collapse(true);
                    s2.removeAllRanges();
                    s2.addRange(r2);
                }
            }
        }
        markChanged(el);
    }

    /** 内容变更标记：派发 input（contenteditable）/ input+change（原生输入框），
     *  触发病历编辑器脏标记与既有业务监听 */
    function markChanged(el) {
        el.dispatchEvent(new Event('input', { bubbles: true }));
        if (!el.isContentEditable) el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /* ==================== 菜单操作分发 ==================== */

    function doCopy(el) {
        if (!el) return;
        var r = fieldRange(el);
        var full = fieldText(el);
        copyText((r && r.end > r.start) ? full.slice(r.start, r.end) : full);
    }

    function doCut(el) {
        if (!el) return;
        var r = fieldRange(el);
        var full = fieldText(el);
        var hasSel = !!(r && r.end > r.start);
        copyText(hasSel ? full.slice(r.start, r.end) : full);
        if (hasSel) deleteSelection(el);
        else clearField(el);
        markChanged(el);
        focusField(el);
    }

    function doPaste(el) {
        if (!el) return;
        focusField(el);
        if (navigator.clipboard && navigator.clipboard.readText) {
            navigator.clipboard.readText().then(function (text) {
                if (!text) return;
                insertPlain(el, text);
            }).catch(function () { tipUnavailable(); });
        } else {
            tipUnavailable();
        }
    }

    function doClear(el) {
        if (!el) return;
        clearField(el);
        focusField(el);
    }

    /** 嘱托「模板」：预留，后期完善 */
    function doTemplate() {
        if (window.Clinic && Clinic.toast) Clinic.toast.info('嘱托模板功能建设中，敬请期待');
    }

    function tipUnavailable() {
        if (window.Clinic && Clinic.toast) Clinic.toast.warning('无法读取剪贴板，请使用 Ctrl+V 粘贴');
    }

    function run(act, el) {
        switch (act) {
            case 'copy': doCopy(el); break;
            case 'cut': doCut(el); break;
            case 'paste': doPaste(el); break;
            case 'clear': doClear(el); break;
            case 'template': doTemplate(); break;
        }
    }

    /* ==================== 菜单生命周期 ==================== */

    /** 在 (x, y) 处弹出菜单（fixed 视口坐标，四边夹紧 4px） */
    function build(ev) {
        var isAdvice = field && field.getAttribute('data-k') === 'advice';
        menu = document.createElement('div');
        menu.className = 'emr-ctxmenu';
        menu.setAttribute('role', 'menu');
        var html = '';
        ['copy', 'cut', 'paste', 'clear'].forEach(function (act) {
            var labels = { copy: '复制', cut: '剪切', paste: '粘贴', clear: '清空' };
            html += '<div class="emr-ctxmenu-item" role="menuitem" data-act="' + act + '">' + labels[act] + '</div>';
        });
        if (isAdvice) {
            html += '<div class="emr-ctxmenu-sep"></div>';
            html += '<div class="emr-ctxmenu-item" role="menuitem" data-act="template">模板</div>';
        }
        menu.innerHTML = html;
        document.body.appendChild(menu);

        // 菜单项点击执行（mousedown 先于 click，避免抢焦点；
        // 先捕获字段引用再关闭菜单，防止 close 清空 field）
        menu.addEventListener('mousedown', function (e) {
            var it = e.target.closest ? e.target.closest('.emr-ctxmenu-item') : null;
            if (!it) return;
            e.preventDefault();
            e.stopPropagation();
            var act = it.getAttribute('data-act');
            var f = field;
            close();
            run(act, f);
        });

        // 定位：视口内夹紧，避免溢出
        menu.style.left = '0px';
        menu.style.top = '0px';
        var w = menu.offsetWidth, h = menu.offsetHeight, m = 4;
        var left = Math.max(m, Math.min(ev.clientX, window.innerWidth - w - m));
        var top = Math.max(m, Math.min(ev.clientY, window.innerHeight - h - m));
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    }

    /**
     * 输入框右键入口（emr.js 的 #emrCard contextmenu 委托调用）：
     * 命中输入框则阻止原生菜单并弹出自定义菜单；非输入框直接忽略。
     */
    function show(ev) {
        var t = ev.target;
        if (!t || !t.closest) return;
        var el = t.closest('input, textarea, [contenteditable="true"]');
        if (!el) return;
        ev.preventDefault();
        // 隐藏输入框（type=hidden / 视觉隐藏）不弹自定义菜单
        if (el.tagName === 'INPUT' && (el.type === 'hidden' || el.style.display === 'none')) return;
        close();
        field = el;
        build(ev);
    }

    function close() {
        if (menu) { menu.remove(); menu = null; }
        field = null;
    }

    /* ==================== 全局关闭（Esc / 外部点击 / 滚动） ==================== */
    document.addEventListener('keydown', function (e) {
        if (menu && e.key === 'Escape') { e.preventDefault(); close(); }
    });
    document.addEventListener('mousedown', function (e) {
        if (menu && !menu.contains(e.target)) close();
    });
    document.addEventListener('scroll', function () { close(); }, true);

    return { show: show, close: close };
})();
