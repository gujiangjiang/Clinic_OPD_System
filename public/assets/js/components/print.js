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

    /** 读取当前用户的自动打印偏好（服务端初始态由 body data-print-auto 注入） */
    function readAutoPref() {
        return document.body.getAttribute('data-print-auto') === '1';
    }

    /** 保存自动打印偏好到服务器（users.print_auto，跟随用户跨设备生效） */
    function saveAutoPref(checked) {
        Clinic.ajax('/api/auth', { action: 'print_auto', value: checked ? 1 : 0 }, { loading: false });
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

        preview = document.createElement('div');
        preview.className = 'print-preview' + (sheet ? ' sheet-' + sheet : '');
        preview.innerHTML =
            '<div class="print-toolbar">' +
            '  <label class="print-auto" title="勾选后每次弹出预览会自动调起系统打印，打印后自动关闭本预览（偏好按账号记忆；如需关闭可在【个人信息】页的打印偏好中取消勾选）">' +
            '    <input type="checkbox" data-act="auto"> 自动打印</label>' +
            '  <button type="button" class="btn btn-outline" data-act="close">关闭</button>' +
            '  <button type="button" class="btn btn-primary" data-act="do">🖨️ 打印</button>' +
            '</div>' +
            '<div id="print-area" class="print-area">' + html + '</div>';
        document.body.appendChild(preview);
        // 预览层禁用右键菜单，避免误操作打断打印流程
        preview.addEventListener('contextmenu', function (e) { e.preventDefault(); });

        // 按纸张类型注入打印页面尺寸（A5 病历纸 / 凭条按实测尺寸动态生成）
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

        // 自动打印偏好（服务端 users.print_auto，跟随用户跨设备生效）
        var autoChk = preview.querySelector('[data-act="auto"]');
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
        return preview;
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
            // 页脚组类名集合：签名 / 末尾横线 / 时间行，以及提示词
            // （请凭本单据至…执行/取药 等）。提示词要求固定在页面底部、
            // 医生签名上一行靠左显示，因此归入页脚组随签名一起沉底，
            // 而不是紧跟在表格/列表后面。匹配按 class 逐个判断，
            // 兼容多类名节点（如 "print-note print-note-tip"）。
            var footSet = ['print-record-sign', 'print-record-foot', 'print-line', 'print-note', 'print-note-tip'];
            function inFootSet(n) {
                var toks = ((n.className || '') + '').trim().split(/\s+/).filter(Boolean);
                return toks.length > 0 && toks.every(function (t) { return footSet.indexOf(t) !== -1; });
            }

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
                var esc = function (s) {
                    return String(s).replace(/[&<>"]/g, function (ch) {
                        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch];
                    });
                };
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

            // 正文可用高度：屏幕预览纸张内容区约 190mm，而打印纸张锁定
            // 187mm——按 190mm 分页会导致「预览完整、正式打印末行被裁半」。
            // 这里统一以 184mm 为基准（再留 3mm 防字体度量微差），
            // 并叠加 14px 安全余量，保证预览与打印分页结果完全一致：
            // 宁可每页底部多留一点空白，也绝不让任何一行在纸上被截断。
            var availH = Math.floor(184 * MM) - headH - footH - 14;

            // 逐节点测高：offsetHeight 不含外边距，而各小节有 margin-bottom、
            // 表格有上下 margin，逐项漏加会让整页累计低估、末页底部被裁切。
            // 单独放入量杯测量并叠加自身上下外边距（此环境下不与兄弟折叠，
            // 结果只会略偏保守，分页更安全）。
            var heights = [];
            contentNodes.forEach(function (n) {
                meas.appendChild(n);
                var cs = window.getComputedStyle(n);
                var h = n.offsetHeight +
                    (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
                heights.push(h);
                meas.removeChild(n);
            });
            meas.remove();

            // ---- 分配：整节点原子分页，不拆行 ----
            // 单节点高度超过整页版心（如超长现病史段落）时无法再拆，
            // 标记所在页为 a5-overflow：放开该页固定高度与裁剪，
            // 由浏览器自然续页，宁可版式略松也不丢内容。
            var pages = [[]];
            var overFlags = [false];
            var used = 0;
            contentNodes.forEach(function (n, i) {
                var h = heights[i] || 0;
                var cur = pages.length - 1;
                if (used + h > availH && pages[cur].length) {
                    pages.push([]);
                    overFlags.push(false);
                    used = 0;
                    cur++;
                }
                if (h > availH) overFlags[cur] = true;
                pages[cur].push(n);
                used += h;
            });

            // ---- 组装页面：每页 = 页眉 + 正文 + 页脚 + 页码 ----
            area.innerHTML = '';
            area.classList.add('paginated');
            pages.forEach(function (pageNodes, i) {
                var sheet = document.createElement('div');
                sheet.className = 'a5-sheet';
                if (overFlags[i]) sheet.classList.add('a5-overflow');

                // 首页完整页眉；后续页有精简版则用精简版（缩短重复患者信息区）
                var pageHead = (i === 0 || !compactHeadNodes) ? headNodes : compactHeadNodes;

                var hd = document.createElement('div');
                hd.className = 'a5-head';
                pageHead.forEach(function (n) { hd.appendChild(n.cloneNode(true)); });

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
