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

        // A5 固定纸张：手动分页——每页固定「页眉+正文+页脚」，预览即所得
        if (sheet === 'a5') {
            paginateSheetA5();
        }

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

    /**
     * A5 固定纸张分页器：
     * 把单据文档拆为 页眉区（医院抬头/标题/患者信息）+ 正文内容 + 页脚区（签名/时间），
     * 按可打印版心高度逐节点分配到固定大小的 A5 页；
     * 每一页都带完整页眉与页脚，末尾追加「第 X 页 / 共 Y 页」。
     * 屏幕预览与打印输出使用同一套分页结果（所见即所得）。
     */
    function paginateSheetA5() {
        try {
            var area = document.getElementById('print-area');
            var doc = area.querySelector('.print-record-doc') || area.firstElementChild;
            if (!doc || !doc.children.length) return;

            var kids = Array.prototype.slice.call(doc.children);
            var headRe = /^(print-hosp|print-sub|print-title-line|print-header|print-record-barcode|print-line|print-info-grid|print-info-lines)$/;
            var footRe = /^(print-record-sign|print-record-foot|print-line)$/;

            // ---- 头部：前缀连续命中头部类名 ----
            var hi = 0;
            while (hi < kids.length && headRe.test((kids[hi].className || '').trim())) hi++;

            // ---- 尾部：后缀连续命中页脚类名（必须含签名或时间行才算有效）----
            var fi = kids.length;
            while (fi > 0 && footRe.test((kids[fi - 1].className || '').trim())) fi--;
            var footNodes = kids.slice(fi);
            var validFoot = footNodes.some(function (n) {
                return /^(print-record-sign|print-record-foot)$/.test((n.className || '').trim());
            });
            if (!validFoot) { footNodes = []; }
            var headNodes = kids.slice(0, hi);
            var contentNodes = kids.slice(validFoot ? hi : hi, validFoot ? fi : kids.length);

            // ---- 测量：宽度取打印可打印区 128mm ----
            var MM = 3.779527559;
            var meas = document.createElement('div');
            meas.style.cssText = 'position:absolute;left:-99999px;top:0;width:128mm;visibility:hidden';
            area.appendChild(meas);

            var mHead = document.createElement('div');
            mHead.className = 'a5-head';
            headNodes.forEach(function (n) { mHead.appendChild(n.cloneNode(true)); });
            meas.appendChild(mHead);
            var headH = mHead.offsetHeight;
            meas.innerHTML = '';

            var mFoot = document.createElement('div');
            mFoot.className = 'a5-foot';
            footNodes.forEach(function (n) { mFoot.appendChild(n.cloneNode(true)); });
            var mPg = document.createElement('div');
            mPg.className = 'a5-page-no';
            mPg.textContent = '第 1 页 / 共 1 页';
            mFoot.appendChild(mPg);
            meas.appendChild(mFoot);
            var footH = mFoot.offsetHeight;
            meas.innerHTML = '';

            // 正文可用高度：187mm 页面（留 3mm 安全余量）− 页眉 − 页脚
            var availH = Math.floor(187 * MM) - headH - footH - 6;

            var heights = [];
            contentNodes.forEach(function (n) {
                meas.appendChild(n);
                heights.push(n.offsetHeight);
                meas.removeChild(n);
            });
            meas.remove();

            // ---- 分配：整节点原子分页，不拆行 ----
            var pages = [[]];
            var used = 0;
            contentNodes.forEach(function (n, i) {
                var h = heights[i] || 0;
                if (used + h > availH && pages[pages.length - 1].length) {
                    pages.push([]);
                    used = 0;
                }
                pages[pages.length - 1].push(n);
                used += h;
            });

            // ---- 组装页面：每页 = 页眉 + 正文 + 页脚 + 页码 ----
            area.innerHTML = '';
            area.classList.add('paginated');
            pages.forEach(function (pageNodes, i) {
                var sheet = document.createElement('div');
                sheet.className = 'a5-sheet';

                var hd = document.createElement('div');
                hd.className = 'a5-head';
                headNodes.forEach(function (n) { hd.appendChild(n.cloneNode(true)); });

                var bd = document.createElement('div');
                bd.className = 'a5-body';
                pageNodes.forEach(function (n) { bd.appendChild(n.cloneNode(true)); });

                var ft = document.createElement('div');
                ft.className = 'a5-foot';
                footNodes.forEach(function (n) { ft.appendChild(n.cloneNode(true)); });
                var pg = document.createElement('div');
                pg.className = 'a5-page-no';
                pg.textContent = '第 ' + (i + 1) + ' 页 / 共 ' + pages.length + ' 页';
                ft.appendChild(pg);

                sheet.appendChild(hd);
                sheet.appendChild(bd);
                sheet.appendChild(ft);
                area.appendChild(sheet);
            });
        } catch (e) {
            // 分页失败时保持原始单页渲染，不影响打印
        }
    }

    return { open: open, load: load, close: close };
})();
