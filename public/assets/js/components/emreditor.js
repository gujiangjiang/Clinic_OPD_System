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

    /** 过敏史全局历史（来自患者主表，供续写/新挂号时引用） */
    var ALLERGY_HIST = '';
    /** 当前就诊中过敏史是否已被修改过（修改后不再自动预载历史，避免删除后重开又出现） */
    var ALLERGY_MODIFIED = false;

    /** HTML 转义（诊断名称/医生姓名等来自数据库的文本进模态框前转义） */
    function esc(s) { return Clinic.escHtml(s); }

    /**
     * 注入前序医生诊断上下文（每次加载病历后由 emr.js 调用）。
     * 列表元素：{code,name,part,note,suspected,doctor_name}
     */
    function setPrevDiagnoses(list) {
        PREV_DIAGS = Array.isArray(list) ? list : [];
    }

    /** 当前已选列表是否已含同编码诊断（已选过则不再弹引用确认） */
    function hasDiagCode(code) {
        return DIAGS.some(function (d) { return code && d.code === code; });
    }

    /** 查找前序医生诊断（同编码，用于续写时"是否引用"确认）；
     *  命中返回前序诊断对象（含 part/note/suspected/doctor_name），否则 null */
    function findPrevDiag(code) {
        if (!code) return null;
        for (var i = 0; i < PREV_DIAGS.length; i++) {
            if (PREV_DIAGS[i] && PREV_DIAGS[i].code === code) return PREV_DIAGS[i];
        }
        return null;
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

    /** 过敏史：否认/承认按钮（默认否认）。点击弹出过敏史模态框——
     *  输入多条目（+添加/删除），保存后自动变「承认」并显示内容。
     *  续写/新挂号默认否认，但模态框内预载历史过敏史，保存即引用。 */
    function buildAllergy() {
        var d = secWrap('过敏史', false);
        // 隐藏的原始字段（供 FIELDS 收集 / set 填充）
        var sel = simpleSelect('allergies.type', ['否认', '承认'], '否认');
        sel.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden';
        d.appendChild(sel);
        var detailWrap = document.createElement('span');
        detailWrap.className = 'ef-cond';
        var tf = textField('allergies.detail', '请填写过敏史', 200);
        tf.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden';
        detailWrap.appendChild(tf);
        d.appendChild(detailWrap);
        // 可点击文字（灰色下划线 + 主题色文字，同诊断点击样式）：显示「否认」或「承认：内容」
        var btn = document.createElement('span');
        btn.className = 'allergy-btn';
        btn.style.cssText = 'color:var(--primary);font-weight:600;cursor:pointer;border-bottom:1px dashed var(--border);font-size:13px;line-height:1.9';
        btn.title = '点击编辑过敏史';
        btn.addEventListener('click', function (e) {
            if (READONLY) return;   // 诊毕只读：不弹编辑
            e.stopPropagation();
            openAllergyModal();
        });
        d.appendChild(btn);
        var refresh = function () {
            var type = sel.value || '否认';
            var detail = tf ? String(tf.innerText || '').trim() : '';
            btn.textContent = (type === '承认' && detail) ? detail : '否认';
        };
        // set(data) 末尾会对 allergies.type 派发 change 事件，据此刷新按钮
        sel.addEventListener('change', refresh);
        d.__sync = refresh;
        return d;
    }

    /** 读过敏史类型（DOM 字段，默认否认） */
    function allergyType() {
        var el = ROOT && ROOT.querySelector('select[data-k="allergies.type"]');
        return el ? (el.value || '否认') : '否认';
    }

    /** 读过敏史内容 */
    function allergyDetail() {
        var el = ROOT && ROOT.querySelector('[data-k="allergies.detail"]');
        return el ? String(el.innerText || '').trim() : '';
    }

    /** 设置过敏史（模态框保存后）：type + detail，并同步显示 */
    function setAllergy(type, detail) {
        ALLERGY_MODIFIED = true;   // 用户已修改过敏史：不再预载历史，避免删除后重开又出现
        ALLERGY_HIST = detail || '';   // 模态框即历史数据：保存后同步内存历史，删除持久生效
        var sel = ROOT && ROOT.querySelector('select[data-k="allergies.type"]');
        var tf = ROOT && ROOT.querySelector('[data-k="allergies.detail"]');
        if (sel) { sel.value = type || '否认'; sel.dispatchEvent(new Event('change')); }
        if (tf) tf.innerText = detail || '';
        var btn = ROOT && ROOT.querySelector('.allergy-btn');
        if (btn) btn.textContent = ((type === '承认' && detail) ? detail : '否认');
        markDirty();
    }

    /** 过敏史模态框：输入框 + 列表（+添加/删除，保存引用）
     *  患者主表（ALLERGY_HIST）是唯一数据源。模态框始终从患者主表读取，
     *  保存后写入患者主表。病历显示的过敏史是快照，与模态框无关。 */
    function openAllergyModal() {
        var items = [];
        var seed = ALLERGY_HIST || '';
        if (seed) {
            String(seed).split(/[、，,;；\n\/]/).map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (s) {
                if (items.indexOf(s) === -1) items.push(s);
            });
        }
        var listBox = 'allergyList';
        Clinic.modal.open(
            '<div class="flex gap-4" style="align-items:center">' +
            '  <input class="input" id="alInput" placeholder="输入过敏史，如：青霉素" style="flex:1" autocomplete="off">' +
            '  <button type="button" class="btn btn-primary btn-sm" id="alAdd" style="flex-shrink:0">＋</button>' +
            '</div>' +
            '<div class="fs-12 text-muted mt-4 mb-4">' + (items.length ? '当前列表（可直接修改后保存）。' : '尚未添加过敏史，可直接输入添加；也可在病历中保存后引用。') + '</div>' +
            '<div id="allergyList" style="max-height:220px;overflow-y:auto"></div>' +
            '<div class="flex gap-8 mt-8">' +
            '  <button type="button" class="btn btn-outline" style="flex:1" onclick="Clinic.modal.close()">取消</button>' +
            '  <button type="button" class="btn btn-primary" style="flex:1" id="alSave">保存</button>' +
            '</div>',
            { title: '💊 过敏史', size: 'modal-sm', buttons: [] }
        );
        var render = function () {
            var box = document.getElementById(listBox);
            if (!box) return;
            box.innerHTML = items.length ? items.map(function (s, i) {
                return '<div class="flex-between" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;margin-bottom:4px">' +
                    '<span style="font-size:13px">' + esc(s) + '</span>' +
                    '<button type="button" class="btn btn-outline btn-sm" style="padding:0 8px" data-rm="' + i + '">✕</button></div>';
            }).join('') : '<div class="text-muted fs-13 text-center" style="padding:10px">尚未添加过敏史</div>';
            box.querySelectorAll('[data-rm]').forEach(function (el) {
                el.addEventListener('click', function () {
                    items.splice(parseInt(el.getAttribute('data-rm'), 10), 1);
                    render();
                });
            });
        };
        render();
        var add = function () {
            var inp = document.getElementById('alInput');
            var v = inp.value.trim();
            if (!v) return;
            if (items.indexOf(v) !== -1) { Clinic.toast.warning('该过敏史已存在'); return; }
            items.push(v);
            inp.value = '';
            inp.focus();
            render();
        };
        document.getElementById('alAdd').addEventListener('click', add);
        document.getElementById('alInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); add(); }
        });
        document.getElementById('alSave').addEventListener('click', function () {
            if (items.length) {
                setAllergy('承认', items.join('、'));
            } else {
                setAllergy('否认', '');
            }
            Clinic.modal.close();
        });
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
        f.setAttribute('title', '点击添加诊断（支持名称/ICD10编码/拼音检索）');
        f.addEventListener('click', function (ev) {
            if (!READONLY) Clinic.emr.openDiagPop(ev);
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
     * @param {object} opts     { readonly, onChange, beforeVitals, afterAdvice, mode, templateMode }
     *                          beforeVitals/afterAdvice：插入自定义节（生命体征/意识状态由外部渲染）
     *                          mode：initial 首诊全量模块（默认）/ progress 续写精简模块
     *                          ——续写文书顶部必填「病历续写」，自动载入全局既往史/过敏史，
     *                            下方为当前医生专属的体格检查/初步诊断/辅助检查/门诊处置。
     *                          templateMode：模板编辑态——仅渲染模板允许节
     *                            （主诉/现病史/主要症状/体格检查/门诊处置/嘱托），
     *                            强制跳过 诊断/生命体征/意识状态/既往史/过敏史/辅助检查/留观。
     */
    function render(container, data, opts) {
        ROOT = container;
        FIELDS = [];
        DIAGS = [];
        READONLY = !!opts.readonly;
        MODE = opts.mode === 'progress' ? 'progress' : 'initial';
        onChange = opts.onChange || null;
        ALLERGY_HIST = opts.allergyHistory || '';
        ALLERGY_MODIFIED = false;   // 新文档渲染：过敏史尚未修改，可预载历史
        var TPL = !!opts.templateMode;

        ROOT.innerHTML = '';
        if (TPL) {
            // ===== 模板编辑态：仅保留主诉/现病史/主要症状/体格检查/门诊处置/嘱托 =====
            ROOT.appendChild(buildCC());
            ROOT.appendChild(buildPI());
            ROOT.appendChild(buildMainSymptoms());
            ROOT.appendChild(buildPE());
            ROOT.appendChild(buildDisp());
            ROOT.appendChild(buildAdvice());
        } else if (MODE === 'progress') {
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
        if (!TPL) {
            ROOT.appendChild(buildDiag());
            ROOT.appendChild(buildAux());
            ROOT.appendChild(buildDisp());
            ROOT.appendChild(buildObs());
            ROOT.appendChild(buildAdvice());
        }

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
                // 点击已添加的诊断 → 弹出编辑悬浮窗（预填部位/备注/疑似）
                return '<span class="ef-diag-item" data-i="' + i + '" onclick="Clinic.emr.openDiagEditPop(event,' + i + ');event.stopPropagation()">' + diagText(d) + '</span>';
            }).join('<span class="ef-diag-sep">，</span>')
            : '';
        // 「＋」显示在字段框（横线框）后侧、最后一个诊断之后（有诊断且可编辑时），
        // 点击弹出跟随鼠标的诊断添加悬浮窗
        var old = f.parentNode ? f.parentNode.querySelector('.ef-diag-add') : null;
        if (old) old.remove();
        if (DIAGS.length && !READONLY) {
            var add = document.createElement('span');
            add.className = 'ef-diag-add';
            add.textContent = '＋';
            add.title = '添加诊断';
            add.onclick = function (ev) { Clinic.emr.openDiagPop(ev); };
            f.insertAdjacentElement('afterend', add);
        }
    }


    return {
        render: render,
        collect: collect,
        set: set,
        setAuto: setAuto,
        setReadonly: setReadonly,
        diagText: diagText,
        setPrevDiagnoses: setPrevDiagnoses,
        findPrevDiag: findPrevDiag,
        markDirty: markDirty,
        /** 过敏史是否被模态框修改过（保存病历时的 allergy_modified 标志） */
        isAllergyModified: function () { return ALLERGY_MODIFIED; },
        /** 外部同步诊断列表（服务端已持久化，仅同步显示，不置脏标记） */
        setDiags: function (list) {
            DIAGS = Array.isArray(list) ? list : [];
            renderDiagText();
        },
    };
})();
