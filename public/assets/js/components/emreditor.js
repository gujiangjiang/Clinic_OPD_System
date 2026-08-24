/**
 * ============================================================
 * emreditor.js v1.0.0 — 结构化电子病历编辑器引擎
 * ============================================================
 * 核心规则：
 * 1. Word 式所见即所得：静态标签（主诉：等）user-select:none +
 *    contenteditable=false；可编辑部分为 [] 包裹的占位字段，
 *    点击字段内任意区域全选文字，输入任意字符即直接替换。
 * 2. 字段为行内元素（display:inline-block），禁止回车换行——
 *    Enter 自动跳到下一个字段；空字段按 Backspace 不删除结构；
 *    粘贴自动转为纯文本。
 * 3. 占位符仅作提示：未填写（空）的字段不参与保存与打印；
 *    [] 括号本身永不保存，仅保存内部文字。
 * 4. 所有 [] 内容均为独立存储字段：前端收集为结构化 JSON
 *    （emr_data），后端提取投影字段并生成打印纯净文本。
 *
 * Clinic.emrEditor：
 *   .render(container, data, opts)   渲染整份病历文档正文（opts.mode 区分首诊/续写）
 *   .collect()                       收集为结构化 JSON
 *   .setAuto(key, html)              更新自动段（辅助检查已开项/处方/处置）
 *   .setReadonly(ro)                 只读切换（诊毕）
 *   .setPrevDiagnoses(list)          注入前序医生诊断（跨医生引用查重上下文）
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.emrEditor = (function () {

    /* ==================== 字典常量 ==================== */
    var UNITS = ['分', '小时', '天', '周', '月', '年'];
    var INFORMANTS = ['患者自诉', '亲属代诉', '朋友代诉', '同事代诉', '120接诊医师代诉', '警察代诉'];
    var ARRIVAL_WAYS = ['自行来院', '被亲属/朋友/同事送入我院', '被120送入我院', '被警察送入我院'];
    var MAIN_SYMPTOM_CATS = {
        '全身症状': ['发热', '头痛', '乏力', '肌肉酸痛', '关节痛'],
        '呼吸道症状': ['咽痛', '咳嗽', '咳痰', '流涕', '胸闷', '呼吸困难'],
        '消化道症状': ['腹泻', '腹痛', '呕吐'],
        '皮疹症状': ['斑丘疹', '水疱疹', '针尖样皮疹', '焦痂'],
        '出血症状': ['斑点瘀斑', '呕血', '咯血', '血尿', '血便', '结膜出血'],
        '神经系统症状': ['发热伴惊厥', '肌无力', '视力模糊'],
    };
    var PE_CATS = ['皮肤黏膜', '头部', '胸部', '肺脏及胸膜', '心脏', '腹部', '神经反射', '肌力及肌张力', '其它体格检查'];

    /* ==================== 内部状态 ==================== */
    var FIELDS = [];      // 字段注册表（按 DOM 顺序）：{path, type, el}
    var ROOT = null;      // 文档容器
    var READONLY = false;
    var MODE = 'initial'; // 文书模式：initial 首诊全量模块 / progress 续写精简模块
    var DIAGS = [];       // 初步诊断列表 [{code,name,part,note,suspected}]
    var PREV_DIAGS = [];  // 前序医生诊断上下文（跨医生引用查重用，emr.js 注入）
    var onChange = null;  // 数据变化回调（脏标记用）

    /** HTML 转义（诊断名称/医生姓名等来自数据库的文本进模态框前转义） */
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * 注入前序医生诊断上下文（每次加载病历后由 emr.js 调用）。
     * 列表元素：{code,name,part,note,suspected,doctor_name}
     */
    function setPrevDiagnoses(list) {
        PREV_DIAGS = Array.isArray(list) ? list : [];
    }

    /** 查找前序医生是否已下过该诊断：编码精确匹配优先，其次名称完全相同；取最近一条 */
    function findPrevDiag(code, name) {
        var hit = null;
        PREV_DIAGS.forEach(function (d) {
            if ((code && d.code === code) || (!code && name && d.name === name)) hit = d;
        });
        return hit;
    }

    /** 当前已选列表是否已含同编码诊断（已选过则不再弹引用确认） */
    function hasDiagCode(code) {
        return DIAGS.some(function (d) { return code && d.code === code; });
    }

    function markDirty() { if (onChange) onChange(); }

    /** 按 path 取值（path 形如 chief_complaint.symptom / allergies） */
    function dig(obj, path) {
        var parts = path.split('.');
        var cur = obj;
        for (var i = 0; i < parts.length; i++) {
            if (cur == null || typeof cur !== 'object') return '';
            cur = cur[parts[i]];
        }
        return cur == null ? '' : cur;
    }

    /** 按 path 写值 */
    function bury(obj, path, val) {
        var parts = path.split('.');
        var cur = obj;
        for (var i = 0; i < parts.length - 1; i++) {
            if (typeof cur[parts[i]] !== 'object' || cur[parts[i]] === null) cur[parts[i]] = {};
            cur = cur[parts[i]];
        }
        cur[parts[parts.length - 1]] = val;
    }

    /* ==================== 字段 DOM 构建 ==================== */

    /** 可编辑占位字段：<span class="ef-field" contenteditable data-ph> */
    function textField(path, ph, width) {
        var el = document.createElement('span');
        el.className = 'ef-field';
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('spellcheck', 'false');
        el.setAttribute('data-ph', ph);
        el.setAttribute('data-k', path);
        if (width) el.style.minWidth = width + 'px';
        bindFieldEvents(el);
        FIELDS.push({ path: path, type: 'text', el: el });
        return el;
    }

    /** 下拉选择字段 */
    function selectField(path, ph, options) {
        var wrap = document.createElement('span');
        wrap.className = 'ef-select-wrap';
        var sel = document.createElement('select');
        sel.className = 'ef-select';
        sel.setAttribute('data-k', path);
        sel.innerHTML = '<option value="">' + ph + '</option>' +
            options.map(function (o) { return '<option value="' + o + '">' + o + '</option>'; }).join('');
        sel.addEventListener('change', markDirty);
        wrap.appendChild(sel);
        FIELDS.push({ path: path, type: 'select', el: sel });
        return wrap;
    }

    /** 无空值下拉（首项即有效值并默认选中，用于否认/承认、是/否等） */
    function simpleSelect(path, options, defaultVal) {
        var sel = document.createElement('select');
        sel.className = 'ef-select';
        sel.setAttribute('data-k', path);
        sel.innerHTML = options.map(function (o) {
            return '<option value="' + o + '"' + (o === defaultVal ? ' selected' : '') + '>' + o + '</option>';
        }).join('');
        sel.addEventListener('change', markDirty);
        FIELDS.push({ path: path, type: 'select', el: sel });
        return sel;
    }

    /** 静态文字标签（不可选中/不可编辑） */
    function staticText(t) {
        var s = document.createElement('span');
        s.className = 'ef-label';
        s.textContent = t;
        return s;
    }

    /** 绑定字段交互事件（双击全选/退格保护/回车跳格/纯文本粘贴） */
    function bindFieldEvents(el) {
        // 双击 → 全选该字段文字（单击仅正常定位光标，符合常规输入习惯）
        el.addEventListener('dblclick', function () {
            var range = document.createRange();
            range.selectNodeContents(el);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        });
        // 空字段退格：仅阻止，避免删除父级 DOM 结构
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && el.innerText.trim() === '') {
                e.preventDefault();
                return;
            }
            // 禁止字段内回车换行 → 跳到下一个字段
            if (e.key === 'Enter') {
                e.preventDefault();
                focusNext(el);
            }
        });
        // 粘贴转纯文本
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            var t = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
            document.execCommand('insertText', false, t.replace(/[\r\n]+/g, ''));
        });
        el.addEventListener('input', markDirty);
    }

    /** 聚焦注册表中的下一个字段 */
    function focusNext(currentEl) {
        for (var i = 0; i < FIELDS.length; i++) {
            if (FIELDS[i].el === currentEl && i < FIELDS.length - 1) {
                var nx = FIELDS[i + 1].el;
                if (nx.tagName === 'SELECT') { nx.focus(); return; }
                nx.focus();
                placeCaretEnd(nx);
                return;
            }
        }
    }

    function placeCaretEnd(el) {
        var range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    /* ==================== 各节构建 ==================== */

    function secWrap(label, required, innerAttr) {
        var d = document.createElement('div');
        d.className = 'doc-sec' + (innerAttr ? ' ' + innerAttr : '');
        // 必填节（主诉/现病史/初步诊断）以红色文字标识，替代原 * 星号——更直观、更整洁
        var lb = '<span class="doc-sec-label' + (required ? ' doc-sec-label-required' : '') + '">' + label + '</span>';
        d.innerHTML = lb;
        return d;
    }

    /** 主诉 */
    function buildCC() {
        var d = secWrap('主诉', true);
        d.appendChild(textField('chief_complaint.symptom', '主要症状', 90));
        d.appendChild(textField('chief_complaint.duration', '时间', 36));
        d.appendChild(selectField('chief_complaint.unit', '单位', UNITS));
        d.appendChild(textField('chief_complaint.second_symptom', '次要症状', 90));
        d.appendChild(textField('chief_complaint.second_duration', '时间', 36));
        d.appendChild(selectField('chief_complaint.second_unit', '单位', UNITS));
        return d;
    }

    /** 现病史 */
    function buildPI() {
        var d = secWrap('现病史', true);
        d.appendChild(selectField('history_present.informant', '供史者', INFORMANTS));
        d.appendChild(textField('history_present.duration', '时间', 36));
        d.appendChild(selectField('history_present.unit', '单位', UNITS));
        d.appendChild(textField('history_present.content', '现病史具体内容', 260));
        d.appendChild(staticText('，'));
        d.appendChild(selectField('history_present.arrival_way', '来院途径', ARRIVAL_WAYS));
        return d;
    }

    /** 既往史：否认/承认下拉；承认时显示详细内容字段 */
    function buildPH() {
        var d = secWrap('既往史', false);
        var sel = simpleSelect('past_history.type', ['否认', '承认'], '否认');
        d.appendChild(sel);
        var detailWrap = document.createElement('span');
        detailWrap.className = 'ef-cond';
        detailWrap.appendChild(textField('past_history.detail', '请填写详细既往史', 220));
        d.appendChild(detailWrap);
        var sync = function () {
            var v = sel.value;
            detailWrap.style.display = (v === '承认') ? '' : 'none';
            if (v !== '承认') detailWrap.querySelector('.ef-field').innerText = '';
        };
        sel.addEventListener('change', sync);
        d.__sync = sync; // set 值后调用
        return d;
    }

    /** 过敏史：否认/承认下拉（默认否认），承认后显示填写框 */
    function buildAllergy() {
        var d = secWrap('过敏史', false);
        var sel = simpleSelect('allergies.type', ['否认', '承认'], '否认');
        d.appendChild(sel);
        var detailWrap = document.createElement('span');
        detailWrap.className = 'ef-cond';
        detailWrap.appendChild(textField('allergies.detail', '请填写过敏史', 200));
        d.appendChild(detailWrap);
        var sync = function () {
            var v = sel.value;
            detailWrap.style.display = (v === '承认') ? '' : 'none';
            if (v !== '承认') detailWrap.querySelector('.ef-field').innerText = '';
        };
        sel.addEventListener('change', sync);
        d.__sync = sync;
        return d;
    }

    /** 主要症状：六类下拉，默认占位不打印；全空整节不打印 */
    function buildMainSymptoms() {
        var d = secWrap('主要症状', false);
        Object.keys(MAIN_SYMPTOM_CATS).forEach(function (cat, i) {
            if (i > 0) d.appendChild(staticText('　'));
            d.appendChild(staticText(cat + '：'));
            d.appendChild(selectField('main_symptoms.' + cat, '请选择', MAIN_SYMPTOM_CATS[cat]));
        });
        return d;
    }

    /** 体格检查：九项文本字段 */
    function buildPE() {
        var d = secWrap('体格检查', false);
        PE_CATS.forEach(function (cat, i) {
            if (i > 0) d.appendChild(staticText('　'));
            d.appendChild(staticText(cat + '：'));
            d.appendChild(textField('physical_exam.' + cat, '请输入', 70));
        });
        return d;
    }

    /** 初步诊断：点击弹出诊断选择模态框 */
    function buildDiag() {
        var d = secWrap('初步诊断', true);
        var f = document.createElement('span');
        f.className = 'ef-field ef-diag';
        f.setAttribute('data-ph', '请添加初步诊断');
        f.setAttribute('title', '点击选择诊断（支持名称/ICD10编码/拼音检索）');
        f.addEventListener('click', function () {
            if (!READONLY) openDiagModal();
        });
        d.appendChild(f);
        FIELDS.push({ path: 'diagnoses', type: 'diag', el: f });
        return d;
    }

    /** 辅助检查：已开项目(auto) + 手工结果 + 外院结果 */
    function buildAux() {
        var d = secWrap('辅助检查', false);
        var auto = document.createElement('span');
        auto.className = 'ef-auto';
        auto.setAttribute('data-auto', 'aux_orders');
        auto.setAttribute('title', '开具检验/检查后自动显示');
        d.appendChild(auto);
        d.appendChild(textField('aux_result', '请填写辅助检查结果', 130));
        d.appendChild(textField('aux_external', '请填写外院辅助检查结果', 130));
        return d;
    }

    /** 门诊处置：处方行(auto) + 处置项(auto) + 自定义输入 */
    function buildDisp() {
        var d = secWrap('门诊处置', false);
        d.style.alignItems = 'flex-start';
        var box = document.createElement('span');
        box.className = 'ef-disp-box';
        var rxBox = document.createElement('span');
        rxBox.className = 'ef-auto ef-rx-lines';
        rxBox.setAttribute('data-auto', 'rx_lines');
        var dispBox = document.createElement('span');
        dispBox.className = 'ef-auto';
        dispBox.setAttribute('data-auto', 'disp_items');
        box.appendChild(rxBox);
        box.appendChild(dispBox);
        box.appendChild(textField('disposition_custom', '填写其他处置/治疗内容', 150));
        d.appendChild(box);
        return d;
    }

    /** 留观 */
    function buildObs() {
        var d = secWrap('留观', false);
        d.appendChild(simpleSelect('is_leave_hospital', ['否', '是'], '否'));
        return d;
    }

    /** 嘱托 */
    function buildAdvice() {
        var d = secWrap('嘱托', false);
        d.appendChild(textField('advice', '请输入嘱托', 320));
        return d;
    }

    /** 病历续写（progress 文书顶部必填项）：续写内容 + 「病史同上」快捷按钮 */
    function buildProg() {
        var d = secWrap('病历续写', true);
        d.appendChild(textField('progress.content', '请输入病历续写内容', 300));
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline btn-sm ef-prog-btn';
        btn.textContent = '病史同上';
        btn.title = '快捷填入「病史同上」（表示病史与前序医生文书一致）';
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (READONLY) return;
            var f = d.querySelector('[data-k="progress.content"]');
            if (f) { f.innerText = '病史同上'; markDirty(); }
        });
        d.appendChild(btn);
        return d;
    }

    /* ==================== 渲染入口 ==================== */

    /**
     * 渲染病历正文各节
     * @param {HTMLElement} container 文档正文容器（.doc-body）
     * @param {object} data     结构化病历数据（emr_data）
     * @param {object} opts     { readonly, onChange, beforeVitals, afterAdvice, mode }
     *                          beforeVitals/afterAdvice：插入自定义节（生命体征/意识状态由外部渲染）
     *                          mode：initial 首诊全量模块（默认）/ progress 续写精简模块
     *                          ——续写文书顶部必填「病历续写」，自动载入全局既往史/过敏史，
     *                            下方为当前医生专属的体格检查/初步诊断/辅助检查/门诊处置。
     */
    function render(container, data, opts) {
        ROOT = container;
        FIELDS = [];
        DIAGS = [];
        READONLY = !!opts.readonly;
        MODE = opts.mode === 'progress' ? 'progress' : 'initial';
        onChange = opts.onChange || null;

        ROOT.innerHTML = '';
        if (MODE === 'progress') {
            // 续写文书：病历续写（必填）→ 既往史/过敏史（全局同步预填）→
            // 生命体征/意识状态（续写时记录当前体征）→ 体格检查
            // （主诉/现病史/主要症状归首诊医生文书，不再重复）
            ROOT.appendChild(buildProg());
            ROOT.appendChild(buildPH());
            ROOT.appendChild(buildAllergy());
            if (opts.beforeVitals) ROOT.appendChild(opts.beforeVitals); // 生命体征节（外部构建）
            if (opts.midNode) ROOT.appendChild(opts.midNode);           // 意识状态节（外部构建）
            ROOT.appendChild(buildPE());
        } else {
            ROOT.appendChild(buildCC());
            ROOT.appendChild(buildPI());
            ROOT.appendChild(buildPH());
            ROOT.appendChild(buildAllergy());
            ROOT.appendChild(buildMainSymptoms());
            if (opts.beforeVitals) ROOT.appendChild(opts.beforeVitals); // 生命体征节（外部构建）
            if (opts.midNode) ROOT.appendChild(opts.midNode);           // 意识状态节（外部构建）
            ROOT.appendChild(buildPE());
        }
        ROOT.appendChild(buildDiag());
        ROOT.appendChild(buildAux());
        ROOT.appendChild(buildDisp());
        ROOT.appendChild(buildObs());
        ROOT.appendChild(buildAdvice());

        set(data || {});

        if (READONLY) setReadonly(true);
    }

    /** 用数据填充全部字段 */
    function set(data) {
        // 程序化填充不视为用户编辑：临时屏蔽 onChange——
        // 末尾对既往史/过敏史下拉的合成 change（显隐同步）不应触发脏标记，
        // 否则页面一加载 EMR_DIRTY 即为 true，闲置后刷新误弹离开提醒
        var cb = onChange;
        onChange = null;
        FIELDS.forEach(function (f) {
            var v = dig(data, f.path);
            if (f.type === 'text') {
                f.el.innerText = v == null ? '' : String(v);
            } else if (f.type === 'select') {
                f.el.value = v == null ? '' : String(v);
            } else if (f.type === 'diag') {
                DIAGS = Array.isArray(v) ? v : [];
                renderDiagText();
            }
        });
        // 既往史/过敏史条件显隐同步（选「承认」才显示填写框）
        ['past_history', 'allergies'].forEach(function (prefix) {
            var typeSel = ROOT.querySelector('select[data-k="' + prefix + '.type"]');
            if (typeSel) typeSel.dispatchEvent(new Event('change'));
        });
        onChange = cb;
    }

    /** 收集为结构化 JSON（空字段存空串；[] 括号不保存，仅内部文字） */
    function collect() {
        var out = {};
        FIELDS.forEach(function (f) {
            var v;
            if (f.type === 'text') v = f.el.innerText.replace(/\u00a0/g, ' ').trim();
            else if (f.type === 'select') v = f.el.value;
            else if (f.type === 'diag') v = DIAGS;
            bury(out, f.path, v);
        });
        return out;
    }

    /** 更新自动段（辅助检查已开项目/处方行/处置项，由 emr.js loadOrders 调用） */
    function setAuto(key, html, hasContent) {
        var el = ROOT ? ROOT.querySelector('[data-auto="' + key + '"]') : null;
        if (!el) return;
        el.innerHTML = html || '';
        el.classList.toggle('empty', !hasContent);
    }

    function setReadonly(ro) {
        READONLY = ro;
        FIELDS.forEach(function (f) {
            if (f.type === 'text') f.el.setAttribute('contenteditable', ro ? 'false' : 'true');
            else f.el.disabled = ro;
        });
        // 只读态下编辑器可能未渲染（ROOT 为空），加空指针防护
        if (ROOT) {
            ROOT.querySelectorAll('.ef-field').forEach(function (el) {
                el.classList.toggle('readonly', ro);
            });
        }
    }

    /* ==================== 初步诊断选择模态框 ==================== */

    /** 诊断展示文本（与打印规则一致）：编码 部位名称（备注）疑似? */
    function diagText(dg) {
        var s = (dg.part || '') + (dg.name || '') + (dg.note ? '（' + dg.note + '）' : '') + (dg.suspected === '是' ? '?' : '');
        return (dg.code ? dg.code + ' ' : '') + s;
    }

    function renderDiagText() {
        var f = null;
        FIELDS.forEach(function (x) { if (x.type === 'diag') f = x.el; });
        if (!f) return;
        f.innerHTML = DIAGS.length
            ? DIAGS.map(function (d, i) {
                // 首个诊断后附「＋」快捷添加入口（跟随鼠标的悬浮添加窗）
                var add = i === 0
                    ? '<span class="ef-diag-add" title="添加诊断" onclick="Clinic.emr.openDiagPop(event);event.stopPropagation()">＋</span>'
                    : '';
                return '<span class="ef-diag-item" data-i="' + i + '">' + diagText(d) + add + '</span>';
            }).join('<span class="ef-diag-sep">，</span>')
            : '';
    }

    /** 诊断选择模态框：左侧搜索（名称/ICD10/拼音），右侧已选列表（排序/删除/编辑） */
    function openDiagModal() {
        var html =
            '<div class="diag-pick">' +
            '  <div class="diag-pick-left">' +
            '    <input class="input" id="dpSearch" placeholder="搜索疾病名称 / ICD10编码 / 拼音" autocomplete="off">' +
            '    <div class="diag-pick-results" id="dpResults"><div class="text-muted fs-13" style="padding:10px">输入关键词检索 ICD10 诊断</div></div>' +
            '  </div>' +
            '  <div class="diag-pick-right">' +
            '    <div class="fs-13 fw-700 mb-8">已选诊断（可调整顺序）</div>' +
            '    <div class="diag-pick-selected" id="dpSelected"></div>' +
            '  </div>' +
            '</div>';
        Clinic.modal.open(html, {
            title: '选择初步诊断',
            size: 'modal-lg',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: '完成', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        Clinic.modal.close();
                        renderDiagText();
                        markDirty();
                    },
                },
            ],
        });
        renderSelected();

        var timer = null;
        document.getElementById('dpSearch').addEventListener('input', function () {
            var kw = this.value.trim();
            if (timer) clearTimeout(timer);
            if (!kw) {
                document.getElementById('dpResults').innerHTML = '<div class="text-muted fs-13" style="padding:10px">输入关键词检索 ICD10 诊断</div>';
                return;
            }
            timer = setTimeout(function () {
                Clinic.get('/api/icd10?action=search&kw=' + encodeURIComponent(kw), null, {
                    onSuccess: function (j) {
                        var list = j.data.list || [];
                        document.getElementById('dpResults').innerHTML = list.length
                            ? list.map(function (x) {
                                return '<div class="diag-pick-item" data-code="' + x.diagnosis_code + '" data-name="' + x.diagnosis_name + '">' +
                                    '<b>' + x.diagnosis_name + '</b><span class="fs-12 text-muted"> ' + x.diagnosis_code + '</span></div>';
                            }).join('')
                            : '<div class="text-muted fs-13" style="padding:10px">未检索到匹配诊断</div>';
                    },
                });
            }, 200);
        });

        document.getElementById('dpResults').addEventListener('click', function (e) {
            var item = e.target.closest('.diag-pick-item');
            if (!item) return;
            var code = item.getAttribute('data-code') || '';
            var name = item.getAttribute('data-name') || '';
            // 跨医生引用查重：前序医生已下过该诊断且本人尚未选 → 弹引用确认框
            var prev = findPrevDiag(code, name);
            if (prev && !hasDiagCode(code)) {
                confirmQuotePrev(prev, function () {
                    // 引用：拷贝追加到已选列表（保留原部位/备注/疑似），
                    // 并自动弹出二级模态框供当前医生修改
                    DIAGS.push({
                        code: prev.code, name: prev.name,
                        part: prev.part || '', note: prev.note || '',
                        suspected: prev.suspected === '是' ? '是' : '',
                    });
                    renderSelected();
                    renderDiagText();
                    markDirty();
                    openDiagEdit(DIAGS[DIAGS.length - 1], DIAGS.length - 1);
                }, function () {
                    // 取消：仍作为普通新诊断添加（走常规二级编辑模态框）
                    openDiagEdit({ code: code, name: name, part: '', note: '', suspected: '' }, null);
                });
                return;
            }
            openDiagEdit({ code: code, name: name, part: '', note: '', suspected: '' }, null);
        });

        document.getElementById('dpSelected').addEventListener('click', function (e) {
            var act = e.target.closest('[data-act]');
            var row = e.target.closest('.diag-sel-row');
            if (!row) return;
            var idx = parseInt(row.getAttribute('data-idx'), 10);
            if (act) {
                if (act.getAttribute('data-act') === 'up' && idx > 0) {
                    var t = DIAGS[idx - 1]; DIAGS[idx - 1] = DIAGS[idx]; DIAGS[idx] = t;
                    renderSelected(); markDirty();
                } else if (act.getAttribute('data-act') === 'down' && idx < DIAGS.length - 1) {
                    var t2 = DIAGS[idx + 1]; DIAGS[idx + 1] = DIAGS[idx]; DIAGS[idx] = t2;
                    renderSelected(); markDirty();
                } else if (act.getAttribute('data-act') === 'del') {
                    DIAGS.splice(idx, 1);
                    renderSelected(); markDirty();
                }
                return;
            }
            // 点击已选诊断 → 二级模态框编辑
            if (DIAGS[idx]) openDiagEdit(DIAGS[idx], idx);
        });
    }

    function renderSelected() {
        var box = document.getElementById('dpSelected');
        if (!box) return;
        box.innerHTML = DIAGS.length
            ? DIAGS.map(function (d, i) {
                return '<div class="diag-sel-row" data-idx="' + i + '">' +
                    '<span class="fw-600 fs-13">' + diagText(d) + '</span>' +
                    '<span class="diag-sel-ops">' +
                    '<button class="btn btn-outline btn-sm" data-act="up"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
                    '<button class="btn btn-outline btn-sm" data-act="down"' + (i === DIAGS.length - 1 ? ' disabled' : '') + '>↓</button>' +
                    '<button class="btn btn-outline btn-sm" data-act="del">删除</button>' +
                    '</span></div>';
            }).join('')
            : '<div class="text-muted fs-13">尚未选择诊断，请在左侧检索后点击添加</div>';
    }

    /**
     * 跨医生引用确认框：XX医生此前已添加过该诊断【诊断名称】，是否直接引用？
     * 【引用】→ onQuote（拷贝追加 + 二级模态框修改）；【取消】→ onCancel（普通新增）
     */
    function confirmQuotePrev(prev, onQuote, onCancel) {
        var detail = [];
        if (prev.part) detail.push('部位：' + esc(prev.part));
        if (prev.note) detail.push('备注：' + esc(prev.note));
        if (prev.suspected === '是') detail.push('疑似标记：是');
        Clinic.modal.open(
            '<div class="fs-13">【' + esc(prev.doctor_name) + '】医生此前已添加过该诊断' +
            '<b>【' + esc(prev.name) + '】</b>' + (prev.code ? '（' + esc(prev.code) + '）' : '') +
            '，是否直接引用？</div>' +
            (detail.length ? '<div class="fs-12 text-muted mt-8">' + detail.join(' ｜ ') + '</div>' : '') +
            '<div class="fs-12 text-muted mt-4">引用后可修改部位、备注与疑似标记；取消则作为新诊断添加。</div>',
            {
                title: '引用前序诊断',
                size: 'modal-sm',
                buttons: [
                    { text: '取消', cls: 'btn-outline', onClick: function () { Clinic.modal.close(); if (onCancel) onCancel(); } },
                    { text: '引用', cls: 'btn-primary', onClick: function () { Clinic.modal.close(); if (onQuote) onQuote(); } },
                ],
            }
        );
    }

    /** 二级模态框：部位/备注/是否疑似（三项均选填，不填不显示） */
    function openDiagEdit(diag, editIdx) {
        var html =
            '<div class="fs-13 mb-12">诊断：<b>' + diag.code + ' ' + diag.name + '</b></div>' +
            '<div class="form-group"><label class="form-label">部位（选填）</label><input class="input" id="de_part" value="' + (diag.part || '') + '" placeholder="如：左侧、右上肢"></div>' +
            '<div class="form-group"><label class="form-label">备注（选填）</label><input class="input" id="de_note" value="' + (diag.note || '') + '" placeholder="如：中指挫擦伤"></div>' +
            '<div class="form-group"><label class="form-label">是否疑似（选填）</label><select class="select" id="de_sus">' +
            '<option value=""' + (diag.suspected !== '是' ? ' selected' : '') + '>否</option>' +
            '<option value="是"' + (diag.suspected === '是' ? ' selected' : '') + '>是</option></select>' +
            '<div class="fs-12 text-muted mt-4">选「是」时诊断末尾追加 ? 标记</div></div>';
        Clinic.modal.open(html, {
            title: (editIdx === null ? '添加诊断信息' : '修改诊断信息'),
            size: 'modal-sm',
            buttons: [
                { text: '取消', cls: 'btn-outline' },
                {
                    text: editIdx === null ? '确认添加' : '确认修改', cls: 'btn-primary', autoClose: false,
                    onClick: function () {
                        var part = document.getElementById('de_part').value.trim();
                        var note = document.getElementById('de_note').value.trim();
                        var sus = document.getElementById('de_sus').value;
                        var obj = {
                            code: diag.code, name: diag.name,
                            part: part, note: note,
                            suspected: sus === '是' ? '是' : '',
                        };
                        if (editIdx === null) DIAGS.push(obj); else DIAGS[editIdx] = obj;
                        Clinic.modal.close();
                        renderSelected();
                        renderDiagText();
                        markDirty();
                    },
                },
            ],
        });
    }

    return {
        render: render,
        collect: collect,
        set: set,
        setAuto: setAuto,
        setReadonly: setReadonly,
        diagText: diagText,
        setPrevDiagnoses: setPrevDiagnoses,
        markDirty: markDirty,
        /** 外部同步诊断列表（服务端已持久化，仅同步显示，不置脏标记） */
        setDiags: function (list) {
            DIAGS = Array.isArray(list) ? list : [];
            renderDiagText();
        },
        /** 外部快捷入口（左栏「＋」）：打开诊断选择弹窗，只读状态拦截并提示 */
        openDiagPicker: function () {
            if (READONLY) { Clinic.toast.info('当前病历为只读状态，无法添加诊断'); return; }
            openDiagModal();
        },
    };
})();
