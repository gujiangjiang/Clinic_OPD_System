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
    let previewEl = null;

    /** 当前自动打印偏好：内存态实时读写；
     *  初始值来自服务端注入的 body[data-print-auto]，
     *  勾选变更时立即同步内存与该属性——同一页面后续预览无需刷新即生效 */
    var autoPref = document.body.getAttribute('data-print-auto') === '1';

    function readAutoPref() {
        return autoPref;
    }

    /** 保存自动打印偏好到服务器（users.print_auto，跟随用户跨设备生效） */
    function saveAutoPref(checked) {
        autoPref = checked;   // 先行生效，再异步持久化
        try { document.body.setAttribute('data-print-auto', checked ? '1' : '0'); } catch (e) { /* 忽略 */ }
        Clinic.ajax('/api/auth', { action: 'print_auto', value: checked ? 1 : 0 }, {
            loading: false,
            onSuccess: function (json) {
                if (window.Clinic && Clinic.toast) Clinic.toast.success(json.msg || (checked ? '已开启自动打印' : '已关闭自动打印'));
            },
        });
    }

    /**
     * 打印指定 HTML 内容
     * @param {string} html      单据 HTML（含 print-area 内部结构）
     * @param {string} title     预览标题（可空）
     * @param {string} sheet     纸张类型：'a5'=病历纸 A5 竖版，'ticket'=窄条凭条纸，其他不传
     */
    function open(html, title, sheet) {
        // 关闭已有预览
        close();

        previewEl = document.createElement('div');
        previewEl.className = 'print-preview' + (sheet ? ' sheet-' + sheet : '');
        // 打印内容防复制：整个预览层（含单据正文与工具栏）禁右键/
        // 选择/拖拽/复制——单据含患者隐私，预览层外不受影响
        previewEl.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        previewEl.addEventListener('selectstart', function (e) { e.preventDefault(); });
        previewEl.addEventListener('dragstart', function (e) { e.preventDefault(); });
        previewEl.addEventListener('copy', function (e) { e.preventDefault(); });
        // 结构说明：遮罩（含 backdrop-filter 虚化）与滚动内容分层、工具栏为兄弟节点。
        // 关键：backdrop-filter 会把后代 position:fixed 的定位基准降级为该祖先，
        // 若工具栏放在带虚化的元素内部就会跟着内容一起滚动——
        // 因此虚化只放在兄弟层 .pp-backdrop 上，工具栏祖先链无任何 filter，
        // 其 fixed 定位始终相对视口，实现真正固定悬浮。
        previewEl.innerHTML =
            '<div class="pp-backdrop"></div>' +
            '<div class="pp-scroll">' +
            '<div id="print-area" class="print-area">' + html + '</div>' +
            '</div>' +
            '<div class="print-toolbar">' +
            '  <label class="print-auto" title="勾选后每次弹出预览会自动调起系统打印，打印后自动关闭本预览（偏好按账号记忆；如需关闭可在【个人信息】页的打印偏好中取消勾选）">' +
            '    <input type="checkbox" data-act="auto"> 自动打印</label>' +
            '  <button type="button" class="btn btn-outline" data-act="close">关闭</button>' +
            '  <button type="button" class="btn btn-primary" data-act="do">🖨️ 打印</button>' +
            '</div>';
        document.body.appendChild(previewEl);

        // 按纸张类型注入打印页面尺寸（A5 病历纸 / 凭条按实测尺寸动态生成）
        applyPageSize(sheet);

        // A5 固定纸张：手动分页——每页固定「页眉+正文+页脚」，预览即所得
        if (sheet === 'a5') {
            paginateSheetA5(document.getElementById('print-area'));
        }
        // 检验报告单横向 A5：识别 .lr-doc 自动启用横版画布 + 分列分页 + 横向 A5 打印纸张
        if (previewEl.querySelector('#print-area .lr-doc')) {
            previewEl.classList.add('sheet-lr');
            applyPageSize('lr');
            try { paginateLabReport(document.getElementById('print-area')); } catch (e) { /* 分列失败保持原样 */ }
        }

        // 绑定工具栏
        previewEl.querySelector('[data-act="close"]').addEventListener('click', close);
        previewEl.querySelector('[data-act="do"]').addEventListener('click', function () {
            window.print();
        });

        // 自动打印偏好（服务端 users.print_auto，跟随用户跨设备生效）
        var autoChk = previewEl.querySelector('[data-act="auto"]');
        autoChk.checked = readAutoPref();
        autoChk.addEventListener('change', function () {
            saveAutoPref(autoChk.checked);
        });
        // 勾选了自动打印：预览渲染完成后自动调起系统打印。
        // window.print() 在主流浏览器为同步阻塞调用——打印对话框关闭
        // （无论完成或取消）后自动收起预览层。
        if (autoChk.checked) {
            setTimeout(function () {
                try { window.print(); } finally { close(); }
            }, 100);
        }

        // 允许 ESC 关闭
        document.addEventListener('keydown', escHandler);
        return previewEl;
    }

    /**
     * 检验报告单横向 A5 分列分页：
     * · 固定画布 210mm×148mm，每页大小恒定；
     * · 结果行单列容量不足时自动双列（中间虚线分隔、隐藏序号）；
     * · 双列仍放不下则自动分页，页脚填充 第x页/共x页。
     * @param {HTMLElement} areaEl 打印容器
     */
    function paginateLabReport(areaEl) {
        var doc = areaEl.querySelector('.lr-doc');
        if (!doc) return;
        var MM = 3.779527559;
        var sheetW = 210, sheetH = 148, padT = 7, padB = 6, padLR = 9;
        var innerW = (sheetW - padLR * 2) * MM;
        var innerH = (sheetH - padT - padB) * MM;

        var meas = document.createElement('div');
        meas.style.cssText = 'position:absolute;left:-99999px;top:0;width:' + innerW + 'px;visibility:hidden';
        areaEl.appendChild(meas);
        function measure(el) {
            meas.appendChild(el);
            var cs = window.getComputedStyle(el);
            var r = el.offsetHeight + (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
            meas.removeChild(el);
            return r;
        }
        var headSel = '.lr-titleline, .lr-patgrid';
        var footSel = '.lr-note, .lr-solid, .lr-footgrid';
        var headH = 0, footH = 0, rowH = 0;
        doc.querySelectorAll(headSel).forEach(function (n) { headH += measure(n.cloneNode(true)); });
        doc.querySelectorAll(footSel).forEach(function (n) { footH += measure(n.cloneNode(true)); });
        var rows = Array.prototype.slice.call(doc.querySelectorAll('.lr-row'));
        if (rows.length) rowH = measure(rows[0].cloneNode(true));
        var colhead = doc.querySelector('.lr-colhead');
        var colheadH = colhead ? measure(colhead.cloneNode(true)) : 0;
        meas.remove();

        var avail = innerH - headH - footH;
        if (avail <= 0) avail = 40;
        var capPerCol = rowH > 0 ? Math.max(1, Math.floor((avail - colheadH) / rowH)) : 1;
        // 单列放不下 → 双列（每页容量 = 2×capPerCol）
        var colCount = rows.length > capPerCol ? 2 : 1;
        var perPage = colCount * capPerCol;
        var pageCount = rows.length ? Math.max(1, Math.ceil(rows.length / perPage)) : 1;

        var sheets = [];
        for (var pi = 0; pi < pageCount; pi++) {
            var sheet = document.createElement('div');
            sheet.className = 'lr-sheet';
            // 抬头
            doc.querySelectorAll(headSel).forEach(function (n) { sheet.appendChild(n.cloneNode(true)); });
            // 结果区
            var res = document.createElement('div');
            res.className = 'lr-result';
            var start = pi * perPage;
            var end = Math.min(rows.length, start + perPage);
            if (colCount === 1) {
                // 单列：列头（含序号）+ 行（序号 1 起）
                res.appendChild(colhead.cloneNode(true));
                for (var i = start; i < end; i++) {
                    var r = rows[i].cloneNode(true);
                    var sq = r.querySelector('.lr-seq');
                    if (sq) sq.textContent = (i + 1);
                    res.appendChild(r);
                }
            } else {
                // 双列：无序号，第一列满后第二列从上往下
                var cols = document.createElement('div');
                cols.className = 'lr-cols';
                for (var c = 0; c < 2; c++) {
                    var col = document.createElement('div');
                    col.className = 'lr-col';
                    col.appendChild(colhead.cloneNode(true));
                    var cStart = start + c * capPerCol;
                    var cEnd = Math.min(end, cStart + capPerCol);
                    for (var k = cStart; k < cEnd; k++) col.appendChild(rows[k].cloneNode(true));
                    cols.appendChild(col);
                }
                res.appendChild(cols);
            }
            sheet.appendChild(res);
            // 页脚（第x页/共x页 + 审核者留空）
            doc.querySelectorAll(footSel).forEach(function (n) { sheet.appendChild(n.cloneNode(true)); });
            var pageEl = sheet.querySelector('.lr-page');
            var totalEl = sheet.querySelector('.lr-total');
            if (pageEl) pageEl.textContent = (pi + 1);
            if (totalEl) totalEl.textContent = pageCount;
            var auditEl = sheet.querySelector('.lr-audit');
            if (auditEl) auditEl.textContent = '';
            sheets.push(sheet);
        }
        areaEl.innerHTML = '';
        sheets.forEach(function (s) { areaEl.appendChild(s); });
    }

    /**
     * 从接口加载单据内容并打印
     * @param {string} url   接口地址
     * @param {object} data  参数
     * @param {string} sheet 纸张类型：'a5'=病历纸 A5 竖版，'ticket'=窄条凭条纸，其他不传
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
     * 只读打印预览模态框（不可打印 / 不可交互，仅关闭）：
     * 复用打印模板的 HTML（含 A5 分页），在 modal-xl 中展示。
     * 供护士站查看完整病历 / 处置单 / 处方等场景复用。
     * @param {string} url        内容接口地址
     * @param {object} [data]     参数
     * @param {string} [title]    模态框标题（缺省「预览」）
     */
    function preview(url, data, title) {
        var mask = Clinic.modal.open(
            '<div class="text-center" style="padding:30px"><div class="spinner" style="border-top-color:var(--primary)"></div></div>',
            { title: title || '预览', size: 'modal-preview', buttons: [{ text: '关闭', cls: 'btn-primary' }] }
        );
        var body = mask.querySelector('.modal-body');
        body.style.overflow = 'auto';
        body.style.padding = '0';
        body.style.background = 'var(--bg-soft, #f1f5f9)';
        Clinic.ajax(url, data, {
            loading: false,
            onSuccess: function (json) {
                if (!(json.data && json.data.html)) {
                    body.innerHTML = '<div class="empty" style="padding:40px">内容获取失败</div>';
                    return;
                }
                var wrap = document.createElement('div');
                wrap.className = 'print-preview sheet-a5 print-preview-in-modal';
                wrap.innerHTML = '<div id="print-area" class="print-area">' + json.data.html + '</div>';
                // 先清空加载圈，再挂载内容
                body.innerHTML = '';
                body.appendChild(wrap);
                // A5 分页（病历 / 申请单 / 处方等 print-record-doc 文档）
                try { paginateSheetA5(wrap.querySelector('#print-area')); } catch (e) { /* 分页失败保持单页 */ }
                // 只读预览：禁右键 / 选择 / 复制（同打印预览层，防拷贝患者隐私）
                ['contextmenu', 'selectstart', 'dragstart', 'copy'].forEach(function (ev) {
                    wrap.addEventListener(ev, function (e) { e.preventDefault(); });
                });
            },
            onError: function () {
                body.innerHTML = '<div class="empty" style="padding:40px">内容加载失败</div>';
            },
        });
        return mask;
    }

    /**
     * 按纸张类型注入 / 移除打印页面尺寸规则
     * @param {string} sheet 'a5'=A5 竖版；'ticket'=按凭条实测尺寸动态生成纸张
     */
    function applyPageSize(sheet) {
        var st = document.getElementById('printPageSize');
        if (st) st.remove();
        if (sheet === 'a5') {
            st = document.createElement('style');
            st.id = 'printPageSize';
            st.textContent = '@page { size: A5 portrait; margin: 10mm; }';
            document.head.appendChild(st);
        } else if (sheet === 'ticket') {
            // 凭条：按「白边缓冲 + 黑边凭条」整体实测尺寸动态生成纸张，
            // 消除默认 A4 纸上大片空白的「没有合适纸张」问题——
            // 打印页即凭条本身（含四周等宽白边）。
            var t = document.getElementById('print-area');
            if (t) {
                var px2mmUp = function (px) { return Math.ceil(px / 96 * 25.4 * 2) / 2; };
                var w = px2mmUp(t.offsetWidth);
                var h = px2mmUp(t.offsetHeight);
                st = document.createElement('style');
                st.id = 'printPageSize';
                st.textContent = '@page { size: ' + w + 'mm ' + h + 'mm; margin: 0; }';
                document.head.appendChild(st);
            }
        } else if (sheet === 'lr') {
            // 检验报告单：横向 A5 固定纸张（210mm × 148mm），与画布同尺寸
            st = document.createElement('style');
            st.id = 'printPageSize';
            st.textContent = '@page { size: 210mm 148mm; margin: 0; }';
            document.head.appendChild(st);
        }
    }

    /**
     * 关闭打印预览
     */
    function close() {
        if (previewEl) {
            previewEl.remove();
            previewEl = null;
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
     * 支持一次打印多份单据（如按检查分类拆分出的多张检查申请单）——
     * 每份 .print-record-doc 独立分页，新文档必从新页开始；
     * 页码跨文档连续累计（第 X 页 / 共 Y 页）。
     * 多页病历自第 2 页起使用精简页眉（患者信息压缩为两行，标题不变）。
     * 屏幕预览与打印输出同构（所见即所得）。
     * @param {HTMLElement} [areaEl] 目标打印容器（缺省取 #print-area）
     */
    function paginateSheetA5(areaEl) {
        try {
            var area = areaEl || document.getElementById('print-area');
            // 收集待分页的单据文档：多份时逐份处理
            var docs = Array.prototype.slice.call(area.querySelectorAll('.print-record-doc'));
            if (!docs.length && area.firstElementChild) docs = [area.firstElementChild];
            if (!docs.length) return;

            var MM = 3.779527559;

            var headRe = /^(print-hosp-block|print-hosp|print-sub|print-title-line|print-header|print-record-barcode|print-line|print-info-grid|print-info-lines|print-head-sec)$/;
            // 页脚组类名集合：签名/末尾横线/时间行 + 提示词（沉底到医生签名上方，
            // 不紧跟表格/列表）。匹配按 class 逐个判断，兼容多类名节点。
            var footSet = ['print-record-sign', 'print-record-foot', 'print-line', 'print-note', 'print-note-tip'];
            function inFootSet(n) {
                var toks = ((n.className || '') + '').trim().split(/\s+/).filter(Boolean);
                return toks.length > 0 && toks.every(function (t) { return footSet.indexOf(t) !== -1; });
            }

            // 测量容器：宽度取打印可打印区 128mm（整个分页过程复用）
            var meas = document.createElement('div');
            meas.style.cssText = 'position:absolute;left:-99999px;top:0;width:128mm;visibility:hidden';
            area.appendChild(meas);

            /**
             * 对单个单据文档执行「头/尾识别 → 精简页眉构建 → 测高 → 整节点分配」。
             * 返回该文档的页面数组 [{head:[节点], body:[节点], foot:[节点], over:bool}]
             * 首页用完整页眉；后续页有精简版则用精简版。
             */
            function paginateDoc(doc) {
                var kids = Array.prototype.slice.call(doc.children);

                // ---- 头部：前缀连续命中头部类名 ----
                var hi = 0;
                while (hi < kids.length && headRe.test((kids[hi].className || '').trim())) hi++;

                // ---- 尾部：后缀连续命中页脚类名（必须含签名或时间行才算有效）----
                var fi = kids.length;
                while (fi > 0 && inFootSet(kids[fi - 1])) fi--;
                var footNodes = kids.slice(fi);
                var validFoot = footNodes.some(function (n) {
                    var toks = ((n.className || '') + '').trim().split(/\s+/);
                    return toks.indexOf('print-record-sign') !== -1 || toks.indexOf('print-record-foot') !== -1;
                });
                if (!validFoot) { footNodes = []; }
                var headNodes = kids.slice(0, hi);
                var contentNodes = kids.slice(hi, validFoot ? fi : kids.length);

                // ---- 后续页精简页眉（仅门诊病历含 .print-info-grid 时生成）----
                // 首页保留完整患者信息网格；第 2 页起参考急诊病历样式：
                // 医院抬头/标题（仍为「门诊电子病历」）与条形码不变，
                // 患者信息压缩为两行，缩短重复页眉占用的版心高度。
                var compactHeadNodes = null;
                var gridIdx = -1;
                headNodes.forEach(function (n, i) {
                    if (gridIdx === -1 && ((' ' + (n.className || '') + ' ').indexOf(' print-info-grid ') !== -1)) gridIdx = i;
                });
                if (gridIdx !== -1) {
                    var cells = {};
                    headNodes[gridIdx].querySelectorAll('.print-info-cell').forEach(function (c) {
                        var strong = c.querySelector('strong');
                        if (!strong) return;
                        var label = strong.textContent.replace(/：\s*$/, '');
                        var full = (c.textContent || '').trim();
                        cells[label] = full.charAt(label.length) === '：' ? full.slice(label.length + 1).trim() : '';
                    });
                    var esc = function (s) { return Clinic.escHtml(s); };
                    var pick = function (labels) {
                        return labels.filter(function (l) { return cells[l]; }).map(function (l) {
                            return '<span class="print-info-cell"><strong>' + esc(l) + '</strong>：' + esc(cells[l]) + '</span>';
                        }).join('');
                    };
                    var linesDiv = document.createElement('div');
                    linesDiv.className = 'print-info-lines';
                    linesDiv.innerHTML =
                        '<div class="print-info-line">' + pick(['姓名', '性别', '出生日期', '年龄']) + '</div>' +
                        '<div class="print-info-line">' + pick(['患者ID', '初复诊', '科室', '联系方式']) + '</div>';
                    compactHeadNodes = headNodes.map(function (n, i) {
                        return i === gridIdx ? linesDiv : n.cloneNode(true);
                    });
                }

                // ---- 测量页眉高度（首页用完整页眉）----
                var mHead = document.createElement('div');
                mHead.className = 'a5-head';
                headNodes.forEach(function (n) { mHead.appendChild(n.cloneNode(true)); });
                meas.appendChild(mHead);
                var headH = mHead.offsetHeight;
                meas.innerHTML = '';

                // ---- 测量精简页眉高度（第2页起使用：更矮，可用高度应更大，
                //      否则底部会多出「完整页眉-精简页眉」的高度差空白）----
                var compactHeadH = 0;
                if (compactHeadNodes) {
                    var mCompact = document.createElement('div');
                    mCompact.className = 'a5-head';
                    compactHeadNodes.forEach(function (n) { mCompact.appendChild(n.cloneNode(true)); });
                    meas.appendChild(mCompact);
                    compactHeadH = mCompact.offsetHeight;
                    meas.innerHTML = '';
                }

                // ---- 测量页脚高度（含页码行占位）----
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

                // 正文可用高度：以 184mm 为基准（打印纸张锁定 187mm，再留 3mm 防
                // 字体度量微差），叠加 14px 安全余量——预览与打印完全一致。
                // 首页用完整页眉高；第2页起用精简页眉高（更矮 → 可用高度更大，
                // 避免每页底部留出「完整页眉-精简页眉」的空白差）
                var availHFull = Math.floor(184 * MM) - headH - footH - 14;
                var availHCompact = compactHeadH > 0 ? Math.floor(184 * MM) - compactHeadH - footH - 14 : availHFull;

                // ---- 分离「底部签名区」（print-foot-sec）与正文流 ----
                // 签名区不参与正文流分页，随正文流保留在最后一页；
                // 为其预留高度，保证不被挤到单独的下一页。
                var bodyNodes = [];
                var footSecNodes = [];
                contentNodes.forEach(function (n) {
                    if ((' ' + ((n.className || '') + '').trim() + ' ').indexOf(' print-foot-sec ') !== -1) {
                        footSecNodes.push(n);
                    } else {
                        bodyNodes.push(n);
                    }
                });

                function measureHeight(node) {
                    meas.appendChild(node);
                    var cs = window.getComputedStyle(node);
                    var h = node.offsetHeight +
                        (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
                    meas.removeChild(node);
                    return h;
                }

                function isSplittable(n) {
                    return (' ' + ((n.className || '') + '').trim() + ' ').indexOf(' print-split ') !== -1;
                }

                function splitTextNode(node, availLeft) {
                    var full = node.textContent || '';
                    if (!full) return null;
                    var tpl = node.cloneNode(false);
                    var measLen = function (len) {
                        var c = tpl.cloneNode(false);
                        c.textContent = full.slice(0, len);
                        meas.appendChild(c);
                        var cs = window.getComputedStyle(c);
                        var h = c.offsetHeight +
                            (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
                        meas.removeChild(c);
                        return h;
                    };
                    var lo = 0, hi = full.length;
                    while (lo < hi) {
                        var mid = Math.ceil((lo + hi) / 2);
                        if (measLen(mid) <= availLeft) { lo = mid; } else { hi = mid - 1; }
                    }
                    if (lo <= 0 || lo >= full.length) return null;
                    var cut = lo;
                    while (cut > 0 && !/\s/.test(full[cut - 1])) cut--;
                    if (cut <= 0 || cut < lo * 0.5) cut = lo;
                    var fit = node.cloneNode(false);
                    fit.textContent = full.slice(0, cut);
                    var rest = node.cloneNode(false);
                    rest.textContent = full.slice(cut);
                    if (!rest.textContent) return null;
                    return { fit: fit, fitH: measLen(cut), rest: rest };
                }

                // ---- 底部签名区高度（供末页腾位判断） ----
                var footSecH = 0;
                footSecNodes.forEach(function (n) { footSecH += measureHeight(n); });

                // ---- 正文流分配：可拆分文本自动续页，整页填满不预留 ----
                var pages = [];
                var used = 0;
                function ensurePage() {
                    var isFirst = pages.length === 0;
                    var useCompact = !isFirst && !!compactHeadNodes;
                    pages.push({
                        head: useCompact ? compactHeadNodes : headNodes,
                        body: [], foot: footNodes, over: false,
                        avail: useCompact ? availHCompact : availHFull,
                    });
                    used = 0;
                }
                if (bodyNodes.length) ensurePage();
                var bi = 0;
                while (bi < bodyNodes.length) {
                    var cur = pages[pages.length - 1];
                    var n = bodyNodes[bi];
                    var h = measureHeight(n);
                    if (used + h <= cur.avail) {
                        cur.body.push(n); used += h; bi++;
                        continue;
                    }
                    if (isSplittable(n)) {
                        var availLeft = cur.avail - used;
                        if (availLeft > 24) {
                            var res = splitTextNode(n, availLeft);
                            if (res && res.fitH > 0) {
                                cur.body.push(res.fit); used += res.fitH;
                                bodyNodes[bi] = res.rest;
                                ensurePage();
                                continue;
                            }
                        }
                    }
                    if (h > cur.avail) {
                        cur.over = true;
                        cur.body.push(n); used += h; bi++;
                        continue;
                    }
                    ensurePage();
                }

                // ---- 底部签名区：追加到正文流的最后一页底部 ----
                // 不预留、不重排——正文先整页填满，签名直接追加到末页底部。
                // 若末页正文已满（签名放不下），该页标记 a5-overflow 自动扩展
                // （不额外加页、不留空白、签名不落单）。
                if (footSecNodes.length) {
                    if (!pages.length) ensurePage();
                    var lastPage = pages[pages.length - 1];
                    if (used + footSecH > lastPage.avail) lastPage.over = true;
                    footSecNodes.forEach(function (n) { lastPage.body.push(n); });
                }
                if (!pages.length) {
                    pages.push({ head: headNodes, body: [], foot: footNodes, over: false });
                }
                return pages;
            }

            // ---- 逐文档分页，汇总全部页面 ----
            var allPages = [];
            docs.forEach(function (doc) {
                paginateDoc(doc).forEach(function (p) { allPages.push(p); });
            });
            meas.remove();

            // ---- 组装页面：每页 = 页眉 + 正文 + 页脚 + 全局连续页码 ----
            area.innerHTML = '';
            area.classList.add('paginated');
            allPages.forEach(function (page, i) {
                var sheet = document.createElement('div');
                sheet.className = 'a5-sheet';
                if (page.over) sheet.classList.add('a5-overflow');

                var hd = document.createElement('div');
                hd.className = 'a5-head';
                page.head.forEach(function (n) { hd.appendChild(n.cloneNode(true)); });

                var bd = document.createElement('div');
                bd.className = 'a5-body';
                page.body.forEach(function (n) { bd.appendChild(n.cloneNode(true)); });

                var ft = document.createElement('div');
                ft.className = 'a5-foot';
                page.foot.forEach(function (n) { ft.appendChild(n.cloneNode(true)); });
                var pg = document.createElement('div');
                pg.className = 'a5-page-no';
                pg.textContent = '第 ' + (i + 1) + ' 页 / 共 ' + allPages.length + ' 页';
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
    return { open: open, load: load, close: close, preview: preview };
})();
