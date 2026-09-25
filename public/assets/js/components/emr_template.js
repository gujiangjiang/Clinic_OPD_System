/**
 * ============================================================
 * emr_template.js — 病历模板选择与应用
 * ============================================================
 * 说明：自 emr.js 拆出的病历模板选择悬浮框模块（「病历节点 +」首诊场景 /
 * 空白病历自动唤起）。经 Clinic.emr._ctx 读写共享状态与内部函数。
 * 依赖：Clinic.get / Clinic.toast / Clinic.emrEditor。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.template = (function () {
    var ctx = Clinic.emr._ctx;
    // 本地别名：与 emr.js 内部同名函数一致，函数体无需改写
    var escHtml = ctx.escHtml;
    var buildVitalSec = ctx.buildVitalSec;
    var buildConsciousNode = ctx.buildConsciousNode;
    var fillContHead = ctx.fillContHead;
    var _onApply = null;   // 当前选择框的选中回调（fetchAndApply 使用）

    function openTemplates(ev) {
        openTemplatePicker(ev);
    }

    /**
     * 模板选择悬浮框（可复用：病历模板 / 知情同意书模板）：
     * 搜索栏 + 范围筛选徽章 + 短列表，锚定在右侧「＋」按钮下方。
     * @param {Event} ev 触发事件（鼠标坐标定位；自动弹出可不传）
     * @param {object} opts { type, pickPlaceholder, emptyText, onApply }
     *   - type        模板类型（medical_record / consent），默认 medical_record
     *   - onApply(t)  选中模板后的回调（t 为模板内容对象）；不传则默认应用为病历模板
     */
    function openTemplatePicker(ev, opts) {
        opts = opts || {};
        var tplType = opts.type || 'medical_record';
        var pickPh = opts.pickPlaceholder || '🔍 搜索病历模板';
        var emptyTxt = opts.emptyText || '暂无可用的病历模板，可前往「模板管理」创建';
        var onApply = opts.onApply || null;
        _onApply = onApply;
        closeTemplatePicker();
        var pop = document.createElement('div');
        pop.id = 'tplPick';
        pop.className = 'tree-box';
        pop.style.cssText = 'position:fixed;z-index:2600;width:340px;max-width:calc(100vw-16px);';
        pop.innerHTML = '<div class="fs-12 text-muted" style="padding:12px;text-align:center">加载模板…</div>';
        document.body.appendChild(pop);
        // 定位：手动点击入口（有鼠标坐标）→ 跟随鼠标；自动弹出 → 锚定 + 号下方
        var W = 340, H = 380;
        if (ev && ev.clientX != null) {
            pop.style.left = Math.max(8, Math.min(ev.clientX + 12, window.innerWidth - W - 8)) + 'px';
            pop.style.top = Math.max(8, Math.min(ev.clientY + 12, window.innerHeight - H - 8)) + 'px';
        } else {
            var anchor = document.querySelector('.ena-add[title="添加病历"]') ||
                document.querySelector('.ena-add') || document.getElementById('queueBtn');
            if (anchor) {
                var r = anchor.getBoundingClientRect();
                pop.style.top = Math.max(8, r.bottom + window.scrollY + 6) + 'px';
                pop.style.left = Math.max(8, Math.min(r.left + window.scrollX, window.innerWidth - W - 8)) + 'px';
            } else {
                pop.style.top = '80px'; pop.style.left = '8px';
            }
        }
        var outside = function (e) { var el = document.getElementById('tplPick'); if (el && !el.contains(e.target)) closeTemplatePicker(); };
        var esc = function (e) { if (e.key === 'Escape') closeTemplatePicker(); };
        pop.__handlers = [outside, esc];
        setTimeout(function () { document.addEventListener('mousedown', outside, true); document.addEventListener('keydown', esc, true); }, 0);
        var scopeNames = { hospital: '全院', dept: '科室', personal: '个人' };
        var tplScope = '';   // 当前筛选范围：''=全部 / hospital / dept / personal
        var TPL_LIST = null; // 模板列表 infiniteList 句柄（重新打开时旧元素已随 pop 移除，必须重置再初始化）

        /** 列表分页接口地址（搜索关键字 / 范围徽章实时参与拼接；sort=picker 按 全院→科室→个人 分组时间升序） */
        function tplPickUrl(p, size) {
            var kw = encodeURIComponent((document.getElementById('tplPickKw') || {}).value || '');
            return '/api/template?action=list&type=' + tplType + '&page=' + p + '&size=' + size + '&kw=' + kw + '&scope=' + tplScope + '&sort=picker';
        }
        /** 重置列表到第一页（搜索/范围变化时调用） */
        function tplPickReset() {
            if (TPL_LIST) TPL_LIST.reset();
            else initTplPickList();
        }
        /** 初始化模板列表无限滚动（滚动到底部自动续加载） */
        function initTplPickList() {
            var box = document.getElementById('tplPickList');
            if (!box) return;
            if (!window.Clinic || !Clinic.infiniteList) {
                box.innerHTML = '<div class="fs-12 text-muted" style="padding:8px 10px">加载模板失败，请重试</div>';
                return;
            }
            if (TPL_LIST) TPL_LIST.stop();
            TPL_LIST = Clinic.infiniteList({
                el: box,
                pageSize: 15,
                threshold: 40,
                emptyHtml: '<div class="fs-12 text-muted" style="padding:8px 10px">' + emptyTxt + '</div>',
                url: tplPickUrl,
                render: function (list) {
                    return list.map(function (t) {
                        var effScope = t.status === 'pending_review' ? 'personal' : t.scope;
                        return '<div class="tree-search-item" style="display:flex;justify-content:space-between;align-items:center" data-id="' + t.id + '">' +
                            '<span>' + escHtml(t.title) + '</span>' +
                            '<span class="badge ' + (effScope === 'hospital' ? 'badge-primary' : (effScope === 'dept' ? 'badge-warning' : 'badge-gray')) + '" style="font-size:11px;flex-shrink:0">' +
                            (scopeNames[effScope] || t.scope) + '</span></div>';
                    }).join('');
                },
            });
        }

        var pop2 = document.getElementById('tplPick');
        if (pop2) {
            pop2.innerHTML =
                '<input class="input tree-box-search" id="tplPickKw" placeholder="' + pickPh + '" autocomplete="off">' +
                '<div class="flex gap-4" style="margin:6px 0;flex-wrap:wrap;justify-content:center">' +
                '  <span class="qp-chip active" data-scope="">全部</span>' +
                '  <span class="qp-chip" data-scope="hospital">全院</span>' +
                '  <span class="qp-chip" data-scope="dept">科室</span>' +
                '  <span class="qp-chip" data-scope="personal">个人</span>' +
                '</div>' +
                '<div class="send-tree" id="tplPickList" style="max-height:320px;min-height:120px"></div>';
            // 列表条目点击（委托：条目随滚动分页动态生成）
            var listBox = document.getElementById('tplPickList');
            listBox.addEventListener('click', function (e) {
                var it = e.target.closest ? e.target.closest('.tree-search-item') : null;
                if (it) {
                    closeTemplatePicker();
                    fetchAndApply(parseInt(it.getAttribute('data-id'), 10));
                }
            });
            initTplPickList();
            // 范围筛选徽章（互斥单选）：切换后重置分页
            pop2.querySelectorAll('.qp-chip').forEach(function (el) {
                el.addEventListener('click', function () {
                    pop2.querySelectorAll('.qp-chip').forEach(function (c) { c.classList.remove('active'); });
                    this.classList.add('active');
                    tplScope = this.getAttribute('data-scope') || '';
                    tplPickReset();
                });
            });
            // 搜索关键字：防抖后重置分页
            var kw = document.getElementById('tplPickKw');
            if (kw) {
                kw.addEventListener('input', function () {
                    clearTimeout(kw.__t);
                    kw.__t = setTimeout(function () { tplPickReset(); }, 300);
                });
                kw.focus();
            }
        }
    }

    function closeTemplatePicker() {
        var pop = document.getElementById('tplPick');
        if (pop) {
            if (pop.__handlers) {
                document.removeEventListener('mousedown', pop.__handlers[0], true);
                document.removeEventListener('keydown', pop.__handlers[1], true);
            }
            pop.remove();
        }
    }

    /** 取模板内容并执行回调（_onApply 自定义 或 默认应用为病历） */
    function fetchAndApply(tplId) {
        Clinic.get('/api/template?action=get&id=' + tplId + '&for_apply=1', null, {
            onSuccess: function (j) {
                var t = j.data.template;
                if (!t || !t.content) { Clinic.toast.warning('模板内容为空'); return; }
                if (typeof _onApply === 'function') {
                    var cb = _onApply;
                    _onApply = null;
                    cb(t);
                } else {
                    applyTemplate(t.content);
                    Clinic.toast.success('已应用模板，可在此基础上修改并保存');
                }
            },
        });
    }

    function applyTemplateById(tplId) {
        fetchAndApply(tplId);
    }

    function applyTemplate(c) {
        if (!c || typeof c !== 'object') c = {};
        var docBody = document.getElementById('docBody');
        var placeholder = docBody ? docBody.querySelector('.ro-placeholder') : null;
        if (placeholder) {
            var d2 = ctx.DATA;
            var r2 = d2.record;
            docBody.innerHTML = '';
            try {
                Clinic.emrEditor.render(docBody, r2.emr || {}, {
                    readonly: false,
                    beforeVitals: buildVitalSec(false, d2.vitals || {}),
                    midNode: buildConsciousNode(false, r2.consciousness || '清醒'),
                    mode: 'initial',
                    onChange: function () { ctx.EMR_DIRTY = true; },
                });
            } catch (e) { console.error('模板应用前编辑器渲染失败', e); }
            fillContHead(r2);
            ctx.DATA.__pending_initial = true;
            // 跨模块通知：数据已变更 → 由事件总线通知大纲/只读段刷新
            // （模板模块无需知道 renderLeftNav 存在，解耦）
            Clinic.eventBus.emit('emr:dataChanged');
        }
        var cur = Clinic.emrEditor.collect();
        var flatKeyMap = {
            chief_complaint: 'chief_complaint.symptom',
            present_illness: 'history_present.content',
            past_history: 'past_history.detail',
            allergy_history: 'allergies.detail',
        };
        Object.keys(c).forEach(function (k) {
            var val = c[k];
            if (typeof val === 'string' && flatKeyMap[k]) {
                var path = flatKeyMap[k].split('.');
                if (path.length === 2) {
                    if (!cur[path[0]]) cur[path[0]] = {};
                    cur[path[0]][path[1]] = val;
                }
                if (k === 'past_history' && val) {
                    cur.past_history = cur.past_history || {};
                    cur.past_history.type = '承认';
                }
                if (k === 'allergy_history' && val) {
                    cur.allergies = cur.allergies || {};
                    cur.allergies.type = '承认';
                }
            } else if (val && typeof val === 'object') {
                cur[k] = Object.assign(cur[k] || {}, val);
            } else {
                cur[k] = val;
            }
        });
        Clinic.emrEditor.set(cur);
        // 模板内容归一化兜底：导入科室/个人模板后确保病历书写区
        // DOM 内绝无残留的 input/textarea（一律为行内可编辑 span）
        if (Clinic.emrEditor.normalizeInlineFields) Clinic.emrEditor.normalizeInlineFields();
        Clinic.emrEditor.markDirty();
    }

    return {
        openTemplates: openTemplates,
        openTemplatePicker: openTemplatePicker,
        applyTemplateById: applyTemplateById,
        applyTemplate: applyTemplate,
    };
})();
