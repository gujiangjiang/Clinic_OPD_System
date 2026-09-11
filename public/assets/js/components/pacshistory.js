/**
 * pacshistory.js v1.0.0 — 患者历史影像报告调阅组件（任务3）
 * ============================================================
 * 说明：在报告撰写区域（一体化阅片模式右侧面板 + 经典模式写报告模态框）
 * 提供【历史报告调阅】：
 *   1. 检索：严格按患者唯一标识 patient_id（patients.patient_no）异步拉取，
 *      禁止姓名检索（防同名同姓混淆）；
 *   2. 分段加载：首屏最近 5 次，支持「加载更多」分页；
 *   3. 只读与复制控制：历史字段全部只读不可编辑，仅【影像表现】【影像诊断】
 *      旁提供一键【复制到当前报告】。
 * 接口：GET /api/imaging?action=history_reports&patient_id=xx&page=N
 * 用法：Clinic.pacsHistory.mount({ container, patientId, onCopy, emptyText })
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.pacsHistory = (function () {

    /* ==================== 状态 ==================== */
    var ST = {};   // container -> state { patientId, page, hasMore, loading, onCopy }

    function esc(s) { return Clinic.escHtml(s == null ? '' : String(s)); }

    /* ==================== 挂载 ==================== */
    /**
     * @param {object} cfg
     *   container  容器元素或 id（列表渲染于其中）
     *   patientId  患者唯一标识（patients.patient_no，必填）
     *   onCopy     function(kind, text) 一键复制回调：kind='findings'|'conclusion'
     *   emptyText  无历史时提示文案（可选）
     */
    function mount(cfg) {
        var box = typeof cfg.container === 'string' ? document.getElementById(cfg.container) : cfg.container;
        if (!box || !cfg.patientId) return null;
        var state = {
            patientId: cfg.patientId,
            page: 0,
            hasMore: true,
            loading: false,
            onCopy: cfg.onCopy || null,
            emptyText: cfg.emptyText || '该患者暂无历史影像报告',
        };
        ST[box.id || uid(box)] = state;
        box.setAttribute('data-hist-state', box.id || '');
        box.innerHTML = '<div class="fs-12 text-muted" style="padding:8px 2px">正在加载历史报告…</div>';
        load(box, state);
        return state;
    }

    function uid(el) {
        var id = 'pacsHist_' + Math.random().toString(36).slice(2, 8);
        el.id = id;
        return id;
    }

    /* ==================== 加载（分段/分页） ==================== */
    function load(box, state) {
        if (state.loading || !state.hasMore) return;
        state.loading = true;
        var nextPage = state.page + 1;
        Clinic.get('/api/imaging?action=history_reports&patient_id=' +
            encodeURIComponent(state.patientId) + '&page=' + nextPage, null, {
            loading: false,
            onSuccess: function (json) {
                state.loading = false;
                var d = json.data || {};
                state.page = d.page || nextPage;
                state.hasMore = !!d.has_more;
                render(box, state, d.list || []);
            },
            onError: function () {
                state.loading = false;
                box.innerHTML = '<div class="fs-12 text-muted" style="padding:8px 2px">历史报告加载失败，请重试</div>';
            },
        });
    }

    /* ==================== 渲染 ==================== */
    function render(box, state, list) {
        // 首页：重绘容器；后续页：追加
        if (state.page <= 1) box.innerHTML = '';
        var more = box.querySelector('.pacs-hist-more');
        var foot = box.querySelector('.pacs-hist-foot');
        if (more) more.parentNode.removeChild(more);
        if (foot) foot.parentNode.removeChild(foot);

        if (!box.children.length) {
            var p = (jsonPatient()) || null;
            var head = '';
            if (p) {
                head = '<div class="fs-12 text-muted" style="padding:2px 2px 8px">检索标识：' +
                    esc(p.patient_id) + '（严格按患者唯一标识检索，防同名混淆）</div>';
            }
            box.innerHTML = head + '<div class="fs-12 text-muted" style="padding:8px 2px">' + esc(state.emptyText) + '</div>';
            return;
        }

        list.forEach(function (r) {
            box.appendChild(itemEl(r, state));
        });

        if (state.hasMore) {
            var btnMore = document.createElement('button');
            btnMore.type = 'button';
            btnMore.className = 'btn btn-outline btn-sm pacs-hist-more';
            btnMore.style.width = '100%';
            btnMore.textContent = '加载更多（已显示 ' + shownCount(box) + '/' + '' + '）';
            btnMore.addEventListener('click', function () { load(box, state); });
            box.appendChild(btnMore);
        } else {
            var done = document.createElement('div');
            done.className = 'fs-12 text-muted pacs-hist-foot';
            done.style.cssText = 'text-align:center;padding:6px 0';
            done.textContent = '已加载全部历史报告';
            box.appendChild(done);
        }
    }

    function shownCount(box) { return box.querySelectorAll('.pacs-hist-item').length; }

    function jsonPatient() {
        // 患者头信息由接口返回但仅首屏使用；此处从缓存取（mount 后保存）
        return ST.__patient || null;
    }

    /** 单条历史报告（列表项 + 展开详情，全部只读） */
    function itemEl(r, state) {
        var st = r.status === 'withdrawn'
            ? '<span class="badge badge-danger" style="font-size:10px">已撤回</span>'
            : '<span class="badge badge-success" style="font-size:10px">已发布</span>';
        var el = document.createElement('div');
        el.className = 'pacs-hist-item';

        var head = document.createElement('div');
        head.className = 'pacs-hist-head';
        head.innerHTML =
            '<span class="hist-date">' + esc((r.report_time || '').replace('T', ' ').substr(0, 16)) + '</span>' + st +
            '<span class="hist-meta">' + esc(r.item_name || '') + ' · ' + esc(r.report_no || '') + '</span>';
        head.addEventListener('click', function () {
            var det = el.querySelector('.pacs-hist-detail');
            if (det) det.classList.toggle('open');
        });

        var det = document.createElement('div');
        det.className = 'pacs-hist-detail';
        det.innerHTML =
            '<div class="hist-field"><div class="hist-field-label">检查项目</div>' +
            '<div class="hist-field-text">' + esc(r.item_name || '—') + '</div></div>' +
            '<div class="hist-field"><div class="hist-field-label">检查时间</div>' +
            '<div class="hist-field-text">' + esc((r.check_time || '').replace('T', ' ').substr(0, 16) || '—') + '</div></div>' +
            '<div class="hist-field"><div class="hist-field-label">影像号 / 报告号</div>' +
            '<div class="hist-field-text">' + esc(r.report_no || '—') + '</div></div>' +
            '<div class="hist-field"><div class="hist-field-label">报告医生</div>' +
            '<div class="hist-field-text">' + esc(r.report_doctor || '—') + '</div></div>' +
            '<div class="hist-field"><div class="hist-field-label">审核医生</div>' +
            '<div class="hist-field-text">' + esc(r.audit_doctor || '—') + '</div></div>' +
            '<div class="hist-field"><div class="hist-field-label">报告状态</div>' +
            '<div class="hist-field-text">' + esc(r.status_name || '—') + '</div></div>' +
            copyField('影像表现', 'findings', r.findings, state) +
            copyField('影像诊断', 'conclusion', r.conclusion, state);
        el.appendChild(head);
        el.appendChild(det);
        return el;
    }

    /** 只读字段 +（仅 findings/conclusion）一键复制到当前报告 */
    function copyField(label, kind, text, state) {
        var copyBtn = '';
        if (kind === 'findings' || kind === 'conclusion') {
            copyBtn = '<button type="button" class="btn btn-outline btn-sm pacs-hist-copy">📋 复制到当前报告</button>';
        }
        var safe = text == null ? '' : String(text);
        return '<div class="hist-field"><div class="hist-field-label">' + esc(label) + copyBtn + '</div>' +
            '<div class="hist-field-text">' + esc(safe !== '' ? safe : '—') + '</div></div>';
    }

    /* 一键复制：事件委托（复制按钮动态生成，统一在容器上委托） */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.pacs-hist-copy') : null;
        if (!btn) return;
        var field = btn.closest('.hist-field');
        var item = btn.closest('.pacs-hist-item');
        if (!field || !item) return;
        var label = field.querySelector('.hist-field-label');
        var textEl = field.querySelector('.hist-field-text');
        if (!label || !textEl) return;
        var kind = label.textContent.indexOf('影像表现') !== -1 ? 'findings' : 'conclusion';
        var text = textEl.textContent === '—' ? '' : textEl.textContent;
        // 找到该容器对应的 state 回调
        var box = item.closest('[data-hist-state]');
        var cb = null;
        if (box) {
            var st = ST[box.getAttribute('data-hist-state')];
            if (st) cb = st.onCopy;
        }
        if (cb) cb(kind, text);
        else copyToClipboard(text);
    });

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                if (Clinic.toast) Clinic.toast.success('已复制');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            if (Clinic.toast) Clinic.toast.success('已复制');
        }
    }

    return {
        mount: mount,
        /** 保存患者头信息（首屏展示检索标识用） */
        setPatient: function (p) { ST.__patient = p || null; },
    };
})();
