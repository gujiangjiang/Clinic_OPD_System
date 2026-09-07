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

    function doUndo(el) {
        if (!el) return;
        if (!window.Clinic || !Clinic.emrEditor || !Clinic.emrEditor.undo) return;
        if (!Clinic.emrEditor.undo(el)) {
            if (Clinic.toast) Clinic.toast.warning('没有可撤销的操作');
        }
    }

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

    /** 嘱托「模板」：打开嘱托模板选择模态框（左侧搜索+列表，右侧预览，覆盖/续写/关闭） */
    function doTemplate(el) {
        if (!el) return;
        if (!window.Clinic || !Clinic.modal) return;
        openAdviceTplModal(el);
    }

    /** 将模板文本写入嘱托字段：overwrite 覆盖 / append 保留原有内容续写在后 */
    function applyAdvice(el, mode, text) {
        if (!el || !text) return;
        var cur = fieldText(el).trim();
        var val = mode === 'overwrite' ? text : (cur ? cur + text : text);
        el.innerText = val;
        markChanged(el);
        try { el.focus(); } catch (e) {}
    }

    /**
     * 嘱托模板选择模态框（与护理记录/影像报告模板统一交互）：
     * 左侧搜索 + 模板列表，右侧预览选中模板内容；覆盖 = 清空后完全按模板填充，
     * 续写 = 保留嘱托原有内容、模板内容插入到后面，关闭 = 关闭模态框。
     * 模板类型 order_note（医生个人免审 / 科室全院经管理员审核）。
     */
    function openAdviceTplModal(fieldEl) {
        Clinic.modal.open(
            '<div class="flex" style="gap:14px;height:460px">' +
            '  <div style="width:300px;flex-shrink:0;display:flex;flex-direction:column;border-right:1px solid var(--border);padding-right:14px;min-height:0">' +
            '    <div class="form-group"><label class="form-label">嘱托模板</label>' +
            '    <input class="input" id="atSearch" placeholder="🔍 搜索模板" autocomplete="off"></div>' +
            '    <div id="atTplList" style="flex:1;overflow-y:auto;min-height:0"></div>' +
            '  </div>' +
            '  <div style="flex:1;min-width:0;display:flex;flex-direction:column">' +
            '    <div class="form-group" style="flex:1;display:flex;flex-direction:column;min-height:0">' +
            '      <label class="form-label">模板内容</label>' +
            '      <div id="atPreview" class="textarea" readonly style="flex:1;min-height:0;white-space:pre-wrap;overflow-y:auto;cursor:text">点击左侧模板查看内容</div>' +
            '    </div>' +
            '    <div class="flex gap-8" style="margin-top:10px">' +
            '      <button type="button" class="btn btn-primary btn-sm" style="flex:1" id="atOverwrite">覆盖</button>' +
            '      <button type="button" class="btn btn-outline btn-sm" style="flex:1" id="atAppend">续写</button>' +
            '      <button type="button" class="btn btn-outline btn-sm" style="flex:1" onclick="Clinic.modal.close()">关闭</button>' +
            '    </div>' +
            '  </div>' +
            '</div>',
            { title: '📝 选择嘱托模板', size: 'modal-lg', buttons: [] }
        );
        var search = document.getElementById('atSearch');
        var list = document.getElementById('atTplList');
        var preview = document.getElementById('atPreview');
        var all = [];
        var cur = null;   // 当前选中的模板

        function render() {
            var kw = (search.value || '').trim().toLowerCase();
            var items = all.filter(function (t) {
                return !kw || (t.title || '').toLowerCase().indexOf(kw) !== -1;
            });
            list.innerHTML = items.length ? items.map(function (t) {
                return '<div class="at-tpl-item" data-id="' + t.id + '" style="cursor:pointer;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px">' +
                    '<div class="fw-600 fs-13">' + Clinic.escHtml(t.title) + '</div>' +
                    '<div class="fs-12 text-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' +
                    Clinic.escHtml((t.content && t.content.content) || '') + '</div></div>';
            }).join('') : '<div class="fs-12 text-muted">暂无嘱托模板（可自由书写）</div>';
            list.querySelectorAll('.at-tpl-item').forEach(function (it) {
                it.addEventListener('click', function () {
                    var id = parseInt(it.getAttribute('data-id'), 10);
                    all.forEach(function (t) { if (t.id === id) cur = t; });
                    preview.textContent = (cur && cur.content && cur.content.content) ? cur.content.content : '';
                    list.querySelectorAll('.at-tpl-item').forEach(function (x) {
                        x.style.borderColor = 'var(--border)';
                    });
                    it.style.borderColor = 'var(--primary)';
                });
            });
        }
        search.addEventListener('input', render);

        document.getElementById('atOverwrite').addEventListener('click', function () {
            var c = cur && cur.content && cur.content.content;
            if (!c) { Clinic.toast.warning('请先在左侧选择一个模板'); return; }
            applyAdvice(fieldEl, 'overwrite', c);
            Clinic.modal.close();
            Clinic.toast.success('已用模板覆盖嘱托内容');
        });
        document.getElementById('atAppend').addEventListener('click', function () {
            var c = cur && cur.content && cur.content.content;
            if (!c) { Clinic.toast.warning('请先在左侧选择一个模板'); return; }
            applyAdvice(fieldEl, 'append', c);
            Clinic.modal.close();
            Clinic.toast.success('已续写嘱托内容');
        });

        Clinic.get('/api/template?action=list&type=order_note', null, {
            loading: false,
            onSuccess: function (j) {
                all = j.data.list || [];
                render();
            },
            onError: function () {
                list.innerHTML = '<div class="fs-12 text-muted">加载模板失败，请重试或前往「模板管理」创建</div>';
            },
        });
    }

    function tipUnavailable() {
        if (window.Clinic && Clinic.toast) Clinic.toast.warning('无法读取剪贴板，请使用 Ctrl+V 粘贴');
    }

    function run(act, el) {
        switch (act) {
            case 'undo': doUndo(el); break;
            case 'copy': doCopy(el); break;
            case 'cut': doCut(el); break;
            case 'paste': doPaste(el); break;
            case 'clear': doClear(el); break;
            case 'template': doTemplate(el); break;
        }
    }

    /* ==================== 菜单生命周期 ==================== */

    /** 在 (x, y) 处弹出菜单（fixed 视口坐标，四边夹紧 4px） */
    function build(ev) {
        var isAdvice = field && field.getAttribute('data-k') === 'advice';
        var canUndo = !!(window.Clinic && Clinic.emrEditor && Clinic.emrEditor.canUndo &&
            field && Clinic.emrEditor.canUndo(field));
        menu = document.createElement('div');
        menu.className = 'emr-ctxmenu';
        menu.setAttribute('role', 'menu');
        var html = '';
        var labels = { undo: '撤销', copy: '复制', cut: '剪切', paste: '粘贴', clear: '清空' };
        // 撤销：仅当字段存在可回退的编辑历史时显示
        if (canUndo) html += '<div class="emr-ctxmenu-item" role="menuitem" data-act="undo">' + labels.undo + '</div>';
        ['copy', 'cut', 'paste', 'clear'].forEach(function (act) {
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
