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
    var renderLeftNav = ctx.renderLeftNav;

    function openTemplates(ev) {
        openTemplatePicker(ev);
    }

    /**
     * 病历模板选择悬浮框：搜索栏 + 短列表，锚定在右侧「病历节点 +」按钮下方；
     * 选中模板后按其内容创建首张电子病历（套用到编辑器）。
     */
    function openTemplatePicker(ev) {
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
        Clinic.get('/api/template?action=list&type=medical_record', null, {
            onSuccess: function (j) {
                var list = j.data.list || [];
                var scopeW = { hospital: 0, dept: 1, personal: 2 };
                var order = list.slice().sort(function (a, b) {
                    var sa = a.status === 'pending_review' ? 'personal' : a.scope;
                    var sb = b.status === 'pending_review' ? 'personal' : b.scope;
                    var wa = scopeW[sa] != null ? scopeW[sa] : 9;
                    var wb = scopeW[sb] != null ? scopeW[sb] : 9;
                    if (wa !== wb) return wa - wb;
                    return (b.updated_at || '').localeCompare(a.updated_at || '');
                });
                var scopeNames = { hospital: '全院', dept: '科室', personal: '个人' };
                function renderItems(items) {
                    var box = document.getElementById('tplPickList');
                    if (!box) return;
                    box.innerHTML = items.length ? items.map(function (t) {
                        var effScope = t.status === 'pending_review' ? 'personal' : t.scope;
                        return '<div class="tree-search-item" style="display:flex;justify-content:space-between;align-items:center" data-id="' + t.id + '">' +
                            '<span>' + escHtml(t.title) + '</span>' +
                            '<span class="badge ' + (effScope === 'hospital' ? 'badge-primary' : (effScope === 'dept' ? 'badge-warning' : 'badge-gray')) + '" style="font-size:11px;flex-shrink:0">' +
                            (scopeNames[effScope] || t.scope) + '</span></div>';
                    }).join('') : '<div class="fs-12 text-muted" style="padding:8px 10px">暂无可用的病历模板，可前往「模板管理」创建</div>';
                    box.querySelectorAll('.tree-search-item').forEach(function (it) {
                        it.addEventListener('click', function () {
                            closeTemplatePicker();
                            applyTemplateById(parseInt(it.getAttribute('data-id'), 10));
                        });
                    });
                }
                var pop2 = document.getElementById('tplPick');
                if (pop2) {
                    pop2.innerHTML =
                        '<input class="input tree-box-search" id="tplPickKw" placeholder="🔍 搜索病历模板" autocomplete="off">' +
                        '<div class="send-tree" id="tplPickList" style="max-height:320px"></div>';
                    renderItems(order);
                    var kw = document.getElementById('tplPickKw');
                    if (kw) {
                        kw.addEventListener('input', function () {
                            var q = this.value.trim().toLowerCase();
                            renderItems(q ? order.filter(function (t) { return t.title.toLowerCase().indexOf(q) !== -1; }) : order);
                        });
                        kw.focus();
                    }
                }
            },
            onError: function () {
                var pop3 = document.getElementById('tplPick');
                if (pop3) pop3.innerHTML = '<div class="fs-12 text-muted" style="padding:12px;text-align:center">加载模板失败，请重试或前往「模板管理」创建</div>';
            },
        });
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

    function applyTemplateById(tplId) {
        Clinic.get('/api/template?action=get&id=' + tplId + '&for_apply=1', null, {
            onSuccess: function (j) {
                var t = j.data.template;
                if (t && t.content) {
                    applyTemplate(t.content);
                    closeTemplatePicker();
                    Clinic.toast.success('已应用模板，可在此基础上修改并保存');
                }
            },
        });
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
            renderLeftNav();
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
        Clinic.emrEditor.markDirty();
    }

    return {
        openTemplates: openTemplates,
        openTemplatePicker: openTemplatePicker,
        applyTemplateById: applyTemplateById,
        applyTemplate: applyTemplate,
    };
})();
