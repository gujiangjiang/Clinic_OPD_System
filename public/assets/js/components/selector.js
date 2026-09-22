/**
 * ============================================================
 * selector.js v2.0.0 — UniversalSelector 通用「检索 + 选择 + 快捷创建」模态框
 * ============================================================
 * 说明：面向多场景复用（关联皮试处置 / 绑定途径处置等）的解耦组件：
 * 只负责 UI 交互，数据源与权限由调用方通过配置声明。
 * 旧版搜索式下拉选择器（Clinic.selector）已被 dropdown.js 取代，0 调用已删除。
 * ============================================================ */

window.Clinic = window.Clinic || {};

/*
 * Clinic.universalSelector.open({
 *   title: '选择关联处置项目',            // 模态框标题
 *   searchAction: 'disposal_search',      // 数据源 action（GET，参数 kw）
 *   allowCreate: true,                    // false 时彻底隐藏"+ 新建"入口
 *   createForm: function(){ return html } // 快建表单 HTML（allowCreate=true 时必传）
 *   createCollect: function(){ return {name:..., fee:..., creation_source:...} },
 *   createAction: 'disposal_quick_create',// 快建提交 action（POST）
 *   createContext: '在维护药品[青霉素]时快捷创建', // 审计追溯文案
 *   onSelect: function(item){}            // item = {id, name, fee, ...}
 * });
 */
Clinic.universalSelector = (function () {

    /** 当前配置（快建提交时使用） */
    let CFG = null;

    /** 当前用户是否管理员（快建文案用；open 时刷新，供 showCreateForm 使用） */
    let IS_ADMIN = false;

    /** 渲染结果列表 */
    function renderList(box, rows) {
        if (!rows || !rows.length) {
            box.innerHTML = '<div class="empty"><div class="empty-ico">🔍</div>未找到匹配项目</div>';
            return;
        }
        box.innerHTML = rows.map(function (r) {
            return '<div class="us-item" data-id="' + r.id + '" data-name="' + Clinic.escHtml(r.name || '') + '"' +
                ' data-fee="' + (r.fee || 0) + '" style="padding:10px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;cursor:pointer">' +
                '<div class="flex-between"><span class="fw-600">' + Clinic.escHtml(r.name || '') + '</span>' +
                '<span class="fs-12 text-muted">¥' + Number(r.fee || 0).toFixed(2) + '</span></div></div>';
        }).join('');
        box.querySelectorAll('.us-item').forEach(function (el) {
            el.addEventListener('click', function () {
                const item = { id: el.getAttribute('data-id'), name: el.getAttribute('data-name'), fee: parseFloat(el.getAttribute('data-fee')) || 0 };
                Clinic.modal.close();
                if (CFG && typeof CFG.onSelect === 'function') CFG.onSelect(item);
            });
        });
    }

    /** 检索防抖定时器 / 请求序号（只渲染最新一次响应，防 AJAX 竞态旧数据覆盖新结果） */
    let searchTimer = null;
    let searchSeq = 0;

    /** 检索 */
    function doSearch(box, kw) {
        if (searchTimer) clearTimeout(searchTimer);
        const myBox = box;
        const myKw = kw;
        searchTimer = setTimeout(function () {
            const seq = ++searchSeq;
            Clinic.get('/api/admin?action=' + CFG.searchAction + '&kw=' + encodeURIComponent(myKw || ''), null, {
                onSuccess: function (json) {
                    if (seq === searchSeq) renderList(myBox, json.data.list || []);
                },
            });
        }, myKw === '' ? 0 : 250);   // 初始空搜索立即；输入防抖 250ms
    }

    /**
     * 打开通用选择模态框
     * @param {object} cfg 配置对象（见文件头注释）
     */
    function open(cfg) {
        CFG = cfg || {};
        IS_ADMIN = document.body.getAttribute('data-role') === 'admin';
        const canCreate = !!CFG.allowCreate;
        const createBtn = canCreate
            ? '<button type="button" class="btn btn-outline btn-sm" id="usCreate">+ 新建项目</button>'
            : '';
        const html =
            '<div class="form-group"><input class="input" id="usKw" placeholder="输入名称检索…"></div>' +
            '<div id="usList" style="max-height:320px;overflow-y:auto"></div>' +
            '<div class="flex-between mt-8"><span class="fs-12 text-muted">' +
            (canCreate ? '找不到？可直接就地新建' + (IS_ADMIN ? '。' : '（非管理员提交需审核）。') : '') + '</span>' + createBtn + '</div>';

        Clinic.modal.open(html, {
            title: CFG.title || '选择项目',
            size: 'modal-md',
            buttons: [{ text: '关闭', cls: 'btn-outline' }],
        });

        const box = document.getElementById('usList');
        doSearch(box, '');
        document.getElementById('usKw').addEventListener('input', function () {
            doSearch(box, this.value.trim());
        });

        if (canCreate) {
            document.getElementById('usCreate').addEventListener('click', function () {
                showCreateForm();
            });
        }
    }

    /** 快建表单视图 */
    function showCreateForm() {
        const kw = document.getElementById('usKw');
        const createBtn = document.getElementById('usCreate');
        // 进入新建模式：隐藏搜索框与「新建项目」按钮，聚焦表单
        if (kw) kw.style.display = 'none';
        if (createBtn) createBtn.style.display = 'none';
        const formHtml =
            '<div id="usCreateBox" style="background:var(--bg-soft);border-radius:10px;padding:14px">' +
            '<div class="form-group"><label class="form-label">项目名称 <span class="req">*</span></label>' +
            '<input class="input" id="usc_name" placeholder="如：青霉素皮试"></div>' +
            '<div class="form-group"><label class="form-label">费用（元）</label>' +
            '<input class="input" type="number" step="0.01" min="0" id="usc_fee" value="0"></div>' +
            '<div class="flex gap-8">' +
            '<button type="button" class="btn btn-primary btn-sm" id="usc_submit">提交</button>' +
            '<button type="button" class="btn btn-outline btn-sm" id="usc_back">返回检索</button></div>' +
            '<div class="fs-12 text-warning mt-8">' + (IS_ADMIN ? '提交后将记录创建来源（' + String(CFG.createContext || '快捷创建') + '）。' : '提交后将记录创建来源（' + String(CFG.createContext || '快捷创建') + '），非管理员需管理员审核。') + '</div></div>';
        document.getElementById('usList').innerHTML = formHtml;
        document.getElementById('usc_back').addEventListener('click', function () {
            // 返回检索：恢复搜索框与新建按钮
            if (kw) kw.style.display = '';
            if (createBtn) createBtn.style.display = '';
            doSearch(document.getElementById('usList'), kw ? kw.value.trim() : '');
        });
        document.getElementById('usc_submit').addEventListener('click', function () {
            const name = document.getElementById('usc_name').value.trim();
            if (!name) { Clinic.toast.warning('请填写项目名称'); return; }
            const payload = CFG.createCollect ? CFG.createCollect() : {};
            payload.name = name;
            payload.fee = parseFloat(document.getElementById('usc_fee').value) || 0;
            payload.creation_source = CFG.createContext || '快捷创建';
            payload.action = CFG.createAction;
            Clinic.ajax('/api/admin', payload, {
                onSuccess: function (json) {
                    const d = json.data || {};
                    if (d.pending) {
                        Clinic.toast.success(json.msg);
                        Clinic.modal.close();
                        return;   // 待审核项不直接回填选择
                    }
                    Clinic.toast.success(json.msg);
                    Clinic.modal.close();
                    if (typeof CFG.onSelect === 'function') {
                        CFG.onSelect({ id: d.id, name: d.name, fee: d.fee });
                    }
                },
            });
        });
    }

    return { open: open };
})();
