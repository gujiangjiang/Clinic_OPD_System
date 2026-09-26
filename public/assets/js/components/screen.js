/**
 * screen.js v1.1.0 — 叫号大屏前端（免登常驻 + 双模式 + 语音播报 + 温馨提示轮播）
 * 功能：
 * 1. 每 3 秒轮询 /api/screen.php?action=heartbeat&token=xxx（心跳 + 数据）
 * 2. 双模式渲染：doctor 大卡片 / 医技列表看板
 * 3. 姓名脱敏（服务端已处理）与语音播报（Web Speech API）
 * 4. 自动播放解锁遮罩 + 心跳防休眠 + 温馨提示轮播/跑马灯
 * 5. 竖屏/横屏自适应 + 转诊患者标记 + 医生信息展示
 */
(function () {
    var TOKEN = document.body.getAttribute('data-token');
    var ROOM_TYPE = document.body.getAttribute('data-roomtype') || 'doctor';
    var lastCallKey = '';
    var muted = false;
    var voiceEnabled = true;
    var tipsTimer = null;
    var tipsIndex = 0;
    var LAST_DATA = null;      // 最近一次渲染数据（窗口缩放/旋转后重排重裁）
    var RENDER_SEQ = 0;        // 渲染序号：异步重裁时丢弃过期回调
    var resizeTimer = null;

    /* ============ 屏型检测（三套布局自动切换） ============
       · 纵向（宽<高）：screen-portrait
       · 方屏/近方屏（1:1 ~ 16:10）：screen-landscape + screen-square（上 医生卡+就诊，下 等待双排）
       · 宽屏/超宽屏（≥16:10）：screen-landscape + screen-wide（左中右三等分三栏） */
    function detectOrientation() {
        var w = window.innerWidth || document.documentElement.clientWidth;
        var h = window.innerHeight || document.documentElement.clientHeight;
        var body = document.body;
        body.classList.remove('screen-portrait', 'screen-landscape', 'screen-square', 'screen-wide');
        if (w < h) {
            body.classList.add('screen-portrait');
        } else {
            body.classList.add('screen-landscape');
            if (w / h >= 1.6) body.classList.add('screen-wide');
            else body.classList.add('screen-square');
        }
    }
    detectOrientation();
    /* 窗口缩放/屏幕旋转：屏型变化后按最新尺寸重排并重新裁剪等待列表
       （防抖合并连续 resize；LAST_DATA 为空（未收到数据）时忽略） */
    window.addEventListener('resize', function () {
        detectOrientation();
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (LAST_DATA) render(LAST_DATA);
        }, 120);
    });

    /* ============ 时钟 ============ */
    function tickClock() {
        var el = document.getElementById('clock');
        if (!el) return;
        var d = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        var wd = ['日', '一', '二', '三', '四', '五', '六'][d.getDay()];
        var dateStr = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' 周' + wd;
        var timeStr = pad(d.getHours()) + ':' + pad(d.getMinutes());
        // 日期/时间分包：纵向屏下由 CSS 控制上下两行显示（避免挤占医院名称），横屏仍单行
        el.innerHTML = '<span class="clock-date">' + dateStr + '</span>' +
            '<span class="clock-time">' + timeStr + '</span>';
    }
    setInterval(tickClock, 1000);
    tickClock();

    /* ============ 语音播报（TTS 队列） ============ */
    var TTS = {
        queue: [], speaking: false,
        pickVoice: function () {
            var voices = window.speechSynthesis ? speechSynthesis.getVoices() : [];
            for (var i = 0; i < voices.length; i++) {
                var v = voices[i].lang || '';
                if (v.indexOf('zh-CN') === 0 || v.indexOf('zh_CN') === 0) return voices[i];
            }
            return null;
        },
        speak: function (text, repeat) {
            if (muted || !voiceEnabled) return;
            repeat = repeat || 2;
            for (var i = 0; i < repeat; i++) this.queue.push(text);
            this.pump();
        },
        chime: function () {
            if (muted || !voiceEnabled) return;
            try {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                var ctx = TTS._actx || (TTS._actx = new AC());
                if (ctx.state === 'suspended') ctx.resume();
                var now = ctx.currentTime;
                [880, 1174.66].forEach(function (f, i) {
                    var o = ctx.createOscillator();
                    var g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = f;
                    g.gain.setValueAtTime(0.0001, now + i * 0.12);
                    g.gain.exponentialRampToValueAtTime(0.2, now + i * 0.12 + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.12 + 0.35);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now + i * 0.12); o.stop(now + i * 0.12 + 0.4);
                });
            } catch (e) { }
        },
        pump: function () {
            var self = this;
            if (this.speaking || !this.queue.length) return;
            this.speaking = true;
            var text = this.queue.shift();
            var u = new SpeechSynthesisUtterance(text);
            u.lang = 'zh-CN';
            u.rate = 0.9; u.pitch = 1.0; u.volume = 1.0;
            var v = this.pickVoice();
            if (v) u.voice = v;
            u.onend = function () {
                self.speaking = false;
                setTimeout(function () { self.pump(); }, 300);
            };
            u.onerror = function () { self.speaking = false; setTimeout(function () { self.pump(); }, 300); };
            window.speechSynthesis.speak(u);
        },
        resume: function () {
            if (window.speechSynthesis && window.speechSynthesis.paused) window.speechSynthesis.resume();
        },
    };
    if (window.speechSynthesis) {
        speechSynthesis.getVoices();
        speechSynthesis.onvoiceschanged = function () { speechSynthesis.getVoices(); };
    }

    /* ============ 自动播放解锁 ============ */
    var mask = document.getElementById('autoplayMask');
    function unlockAutoplay() {
        if (!mask) return;
        mask.style.display = 'none';
        if (window.speechSynthesis) {
            var u = new SpeechSynthesisUtterance('');
            u.volume = 0;
            speechSynthesis.speak(u);
        }
    }
    if (mask) mask.addEventListener('click', unlockAutoplay);

    /* ============ 温馨提示轮播/跑马灯 ============ */
    function startTips(tips, interval) {
        var inner = document.getElementById('tipsInner');
        if (!inner) return;
        if (tipsTimer) { clearInterval(tipsTimer); tipsTimer = null; }
        if (!tips || !tips.length) { inner.textContent = ''; return; }

        // animate=true 时以「上下翻页」动画切入新条目；仅一条时保持静态不动
        function showTip(i, animate) {
            var t = tips[i % tips.length] || '';
            var box = document.createElement('div');
            box.className = 'call-tips-item' + (animate ? ' call-tips-flip' : '');
            // 如果文本超长（> 30 字），启用跑马灯
            if (t.length > 30) {
                box.innerHTML = '<div class="call-tips-marquee"><span>' + t + '</span></div>';
            } else {
                box.textContent = t;
            }
            inner.innerHTML = '';
            inner.appendChild(box);
        }
        tipsIndex = 0;
        showTip(0, false);
        if (tips.length > 1) {
            tipsTimer = setInterval(function () {
                tipsIndex = (tipsIndex + 1) % tips.length;
                showTip(tipsIndex, true);
            }, interval * 1000);
        }
    }

    /* ============ 医生信息字号动态适配 ============
       姓名以所在合并单元格（前 2/7 高度、右列全宽）能容纳的最大字号显示；
       职称/工号/介绍按姓名字号比例联动（0.62 / 0.5 / 0.5）。 */
    function fitDoctorCard() {
        var card = document.querySelector('.screen-doctor-card');
        if (!card) return;
        var info = card.querySelector('.screen-doc-info');
        var nameEl = card.querySelector('.screen-doc-name');
        var titleEl = card.querySelector('.screen-doc-title');
        var empEl = card.querySelector('.screen-doc-emp');
        var introEl = card.querySelector('.screen-doc-intro');
        if (!info || !nameEl || !nameEl.textContent) return;

        var cs = getComputedStyle(info);
        var rowGap = parseFloat(cs.rowGap) || 0;
        var availH = info.clientHeight - (parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom));
        var availW = info.clientWidth - (parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight)) -
                     (parseFloat(cs.borderLeftWidth) || 0);

        // 7 行网格：姓名占前 2 行（含 1 个行距）
        var rowH = (availH - rowGap * 6) / 7;
        var nameCellH = rowH * 2 + rowGap;

        // 从高度上限开始逐步缩小，直到文字宽高都放入单元格
        var fs = Math.max(12, nameCellH * 0.85);
        nameEl.style.fontSize = fs + 'px';
        var guard = 60;
        while (guard-- > 0 && (nameEl.scrollWidth > availW || nameEl.scrollHeight > nameCellH)) {
            fs = fs * 0.93;
            nameEl.style.fontSize = fs + 'px';
        }
        // 姓名在最大可容纳字号基础上减 10 号（约四成），保持舒展排版
        fs = fs * 0.6;
        nameEl.style.fontSize = fs + 'px';

        // 其余项随姓名字号比例联动（职称 0.62、工号 0.5、介绍比工号再小 1 号）
        if (titleEl) titleEl.style.fontSize = (fs * 0.62) + 'px';
        if (empEl) empEl.style.fontSize = (fs * 0.5) + 'px';
        if (introEl) introEl.style.fontSize = (fs * 0.46) + 'px';
    }

    function maskName(n) { return n || ''; }

    /* 就诊序号标签：转诊患者显示完整序号 +（转） */
    function seqLabel(r) {
        if (!r) return '';
        var s = String(r.visit_seq).padStart(3, '0') + ' 号';
        if (r.is_transfer) s = (r.first_dept_name || '转科') + ' ' + s + '（转）';
        return s;
    }

    function renderDoctorMode(d) {
        var cur = d.current || {};
        var next = d.next || {};
        var doc = d.doctor || {};
        // 三种屏型：纵向 / 方屏(近方屏) / 宽屏(≥16:10)，自动切换布局
        var body = document.body;
        var isPortrait = body.classList.contains('screen-portrait');
        var isWide = body.classList.contains('screen-wide');
        var mode = isPortrait ? 'portrait' : (isWide ? 'wide' : 'square');
        var waitRaw = d.waiting || [];
        var missedArr = waitRaw.filter(function (w) { return w.missed; });
        var normalArr = waitRaw.filter(function (w) { return !w.missed; });
        var candidates = normalArr.concat(missedArr).slice(0, 50);   // 超长屏可多显示

        var curCard = cur.name
            ? '<div class="screen-cur-name">' + maskName(cur.name) + '</div>' +
              '<div class="screen-cur-seq">' + seqLabel(cur) + '</div>'
            : '<div class="screen-empty-big">暂无就诊中患者</div>';
        var nextCard = next.name
            ? '<div class="screen-next-name">' + maskName(next.name) + '</div>' +
              '<div class="screen-next-seq">' + seqLabel(next) + '</div>'
            : '<div class="screen-empty">暂无候诊患者</div>';

        // 医生信息卡：左列照片（单元格内等比最大化），右列 7 行网格
        var docCard = doc.name
            ? '<div class="screen-doctor-card">' +
              '<div class="screen-doc-photo' + (doc.photo ? ' has-img' : '') + '">' + (doc.photo ? '<img src="' + doc.photo + '">' : '👨‍⚕️') + '</div>' +
              '<div class="screen-doc-info">' +
              '<div class="screen-doc-head">' +
              '<div class="screen-doc-name">' + doc.name + '</div>' +
              (doc.title ? '<div class="screen-doc-title">' + doc.title + '</div>' : '') +
              (doc.emp_no ? '<div class="screen-doc-emp">工号 ' + doc.emp_no + '</div>' : '') +
              '</div>' +
              '<div class="screen-doc-intro' + (doc.intro ? '' : ' screen-doc-intro-empty') + '">' + (doc.intro || '暂无医生介绍') + '</div>' +
              '</div></div>'
            : '<div class="screen-doctor-card screen-doctor-card-empty"><div class="screen-doc-photo">👨‍⚕️</div>' +
              '<div class="screen-doc-info"><div class="screen-doc-name">医生出诊中</div>' +
              '<div class="screen-doc-intro screen-doc-intro-empty">暂无医生信息</div></div></div>';

        var curNext = '<div class="screen-panel screen-cur-panel"><div class="screen-panel-title">正在就诊</div>' +
            '  <div class="screen-panel-body"><div class="screen-panel-inner">' + curCard + '</div></div></div>' +
            '<div class="screen-panel screen-next-panel"><div class="screen-panel-title">下一位</div>' +
            '  <div class="screen-panel-body"><div class="screen-panel-inner">' + nextCard + '</div></div></div>';

        var main = document.getElementById('screenMain');
        var cols = 1;
        var waitPanel;
        if (mode === 'portrait') {
            // ===== 纵向：医生卡在上，主区（正在就诊/下一位 | 等待就诊）在下 =====
            waitPanel = waitPanelHtml(candidates, 1);
            main.innerHTML = '<div class="screen-doctor-grid">' +
                docCard +
                '<div class="screen-main-area">' +
                '<div class="screen-left-col">' + curNext + '</div>' +
                waitPanel +
                '</div></div>';
        } else if (mode === 'square') {
            // ===== 方屏/近方屏：上 医生卡(左)+正在就诊/下一位(右)；下 等待就诊（双排2列） =====
            cols = 2;
            waitPanel = waitPanelHtml(candidates, 2);
            main.innerHTML = '<div class="screen-land-grid">' +
                '<div class="screen-land-top">' +
                '  <div class="screen-land-doctor">' + docCard + '</div>' +
                '  <div class="screen-land-main">' + curNext + '</div>' +
                '</div>' +
                waitPanel +
                '</div>';
        } else {
            // ===== 宽屏/超宽屏：左 医生卡 | 中 正在就诊/下一位 | 右 等待就诊（满高单列） =====
            waitPanel = waitPanelHtml(candidates, 1);
            main.innerHTML = '<div class="screen-land-grid">' +
                '  <div class="screen-land-doctor">' + docCard + '</div>' +
                '  <div class="screen-land-main">' + curNext + '</div>' +
                waitPanel +
                '</div>';
        }
        // 动态裁剪等待就诊数量（放不下则减少，超长屏可多显示；过号恒居末尾）
        var seq = ++RENDER_SEQ;
        fitWaitList(main, normalArr, missedArr, cols);
        // 首帧布局/字体度量可能尚未稳定，下一帧以最终尺寸再裁一次（若期间已重渲染则丢弃）
        var raf = window.requestAnimationFrame || function (cb) { return setTimeout(cb, 16); };
        raf(function () {
            if (seq !== RENDER_SEQ) return;
            fitWaitList(main, normalArr, missedArr, cols);
        });
    }

    /* 等待就诊单行 HTML */
    function waitItemHtml(w) {
        return '<div class="screen-wait-item">' +
            (w.missed ? '<span class="screen-wait-miss">过</span>' : '<span class="screen-wait-miss screen-wait-miss-empty"></span>') +
            '<span class="screen-wait-seq">' + String(w.visit_seq).padStart(3, '0') + (w.is_transfer ? '★' : '') + '</span>' +
            '<span class="screen-wait-name">' + maskName(w.name) + '</span>' +
            '<span class="screen-wait-gender">' + (w.gender || '') + '</span>' +
            '<span class="screen-wait-age">' + (w.age_fmt || '') + '</span></div>';
    }

    /* 等待就诊面板 HTML（cols=1 单列 / cols=2 方屏双排），供 fitWaitList 动态裁剪 */
    function waitPanelHtml(list, cols) {
        var body;
        if (!list.length) {
            body = '<div class="screen-wait-empty"><div class="screen-empty">暂无候诊患者</div></div>';
        } else {
            body = cols === 2
                ? '<div class="screen-wait-land-list">' + list.map(waitItemHtml).join('') + '</div>'
                : '<div class="screen-wait-list">' + list.map(waitItemHtml).join('') + '</div>';
        }
        return '<div class="screen-wait-panel">' +
            '<div class="screen-panel-title">等待就诊（' + list.length + '）</div>' + body + '</div>';
    }

    /* 动态计算等待就诊显示数量：以可用高度得出放几行；过号患者恒居末尾并按顺序排列。
       cols=1 单列逐行；cols=2 方屏双排（每行2位，列间分隔线）。
       列宽由 CSS max-content 按内容自适应（共享列轨），各行 序号/姓名/性别/年龄 自动
       纵向对齐且姓名后无超长空隙。 */
    function fitWaitList(main, normalArr, missedArr, cols) {
        var panel = main.querySelector('.screen-wait-panel');
        var listEl = cols === 2 ? main.querySelector('.screen-wait-land-list') : main.querySelector('.screen-wait-list');
        if (!listEl) return;
        var items = listEl.querySelectorAll('.screen-wait-item');
        var count = items.length;
        if (!count) return;
        var avail = listEl.clientHeight;
        if (avail <= 0) return;
        var fitN, maxRows;
        if (cols === 2) {
            // 双排：行高 = 当前 repeat(8,1fr) 行高；文本超出当前行则按文本自然高缩行数
            var rowH = items[0].offsetHeight;
            var contentH = items[0].scrollHeight;
            var rowsCap = (contentH > rowH + 1)
                ? Math.max(1, Math.floor((avail - 2) / contentH))
                : Math.max(1, Math.floor((avail - 4) / rowH));
            maxRows = Math.min(10, rowsCap);      // 上限 10 行（20 位）
            fitN = maxRows * 2;
            listEl.style.gridTemplateRows = 'repeat(' + maxRows + ', minmax(0, 1fr))';
            listEl.style.setProperty('--wait-rows', maxRows);   // 分隔线自第二列起
        } else {
            // 单列：以单行实际高度（内容高度，不受容器拉伸影响）整除可用高度，
            // 避免用 scrollHeight/count 均值受末行/边框影响而少显示一行
            var itemH = items[0].offsetHeight || (listEl.scrollHeight / count) || 1;
            var total = itemH * count;
            if (total <= avail + 2) {
                fitN = count;
            } else {
                fitN = Math.max(1, Math.floor((avail - 2) / itemH));   // 预留 2px 防末行裁切
            }
        }
        var showMissed = missedArr.slice(0, fitN);
        var showNormal = normalArr.slice(0, Math.max(0, fitN - showMissed.length));
        var shown = showNormal.concat(showMissed);
        listEl.innerHTML = shown.map(waitItemHtml).join('');
        // 共享列宽：用同字号隐藏探针测量各列内容最大自然宽度，设为列宽变量，
        // 保证各行 序号/姓名/性别/年龄 全部纵向对齐，且姓名后无超长空隙
        measureWaitColumns(listEl, shown);
        // 方屏双排：给第二列条目加 class，用于显示纵向分隔线
        if (cols === 2 && maxRows) {
            var all = listEl.querySelectorAll('.screen-wait-item');
            for (var i = 0; i < all.length; i++) {
                if (i >= maxRows) all[i].classList.add('wait-col2');
            }
        }
        updateWaitTitle(panel, shown.length);
    }

    function updateWaitTitle(panel, n) {
        var t = panel ? panel.querySelector('.screen-panel-title') : null;
        if (t) t.textContent = '等待就诊（' + n + '）';
    }

    /* 共享列宽：构造同字号隐藏探针行（各列取最长内容），测出每列自然宽度，
       设为 --wait-*-w 变量（+4px 缓冲）。因所有行共用这些列宽，五列全部纵向对齐，
       且列宽贴合内容、姓名后无超长空隙。 */
    function measureWaitColumns(listEl, shown) {
        if (!shown || !shown.length) return;
        var longest = function (fn) {
            var t = '';
            for (var i = 0; i < shown.length; i++) {
                var v = String(fn(shown[i]) || '');
                if (v.length > t.length) t = v;
            }
            return t;
        };
        var seqText = longest(function (w) { return String(w.visit_seq).padStart(3, '0') + (w.is_transfer ? '★' : ''); });
        var nameText = longest(function (w) { return maskName(w.name); });
        var genderText = longest(function (w) { return w.gender || ''; });
        var ageText = longest(function (w) { return w.age_fmt || ''; });
        var probe = document.createElement('div');
        probe.className = 'screen-wait-item';
        // 关键：内联 grid-template-columns 覆盖继承自 listEl 的 --wait-*-w 固定列宽，
        // 强制按各列内容自然宽度测量；否则探针会沿用上一轮列宽并每轮 +4px 缓冲，
        // 导致列宽逐轮膨胀、右侧年龄列被挤出面板。
        probe.style.cssText = 'position:absolute;visibility:hidden;top:0;left:0;pointer-events:none;' +
            'grid-template-columns:max-content max-content max-content max-content max-content';
        probe.innerHTML = '<span class="screen-wait-miss">过</span>' +
            '<span class="screen-wait-seq">' + seqText + '</span>' +
            '<span class="screen-wait-name">' + nameText + '</span>' +
            '<span class="screen-wait-gender">' + genderText + '</span>' +
            '<span class="screen-wait-age">' + ageText + '</span>';
        listEl.appendChild(probe);
        var missW = probe.children[0].getBoundingClientRect().width;
        var seqW = probe.children[1].getBoundingClientRect().width;
        var nameW = probe.children[2].getBoundingClientRect().width;
        var genderW = probe.children[3].getBoundingClientRect().width;
        var ageW = probe.children[4].getBoundingClientRect().width;
        listEl.removeChild(probe);
        var set = function (k, v) { if (v > 0) listEl.style.setProperty(k, (Math.ceil(v) + 4) + 'px'); };
        set('--wait-miss-w', missW);
        set('--wait-seq-w', seqW);
        set('--wait-name-w', nameW);
        set('--wait-gender-w', genderW);
        set('--wait-age-w', ageW);
    }

    /* 医技队列共享列宽（与医生端 measureWaitColumns 同思路）：
       用同字号隐藏探针行取各列最长内容，测出自然宽度设为 --dept-*-w 变量，
       所有行共用列宽 → 序号/姓名/性别/年龄/就诊科室 全部纵向对齐。 */
    function measureDeptColumns(listEl, wait) {
        if (!listEl || !wait || !wait.length) return;
        var longest = function (fn) {
            var t = '';
            for (var i = 0; i < wait.length; i++) {
                var v = String(fn(wait[i]) || '');
                if (v.length > t.length) t = v;
            }
            return t;
        };
        var genderText = longest(function (w) { return w.gender || ''; });
        var ageText = longest(function (w) { return w.age_fmt || ''; });
        var deptText = longest(function (w) { return w.dept_name || ''; });
        var probe = document.createElement('div');
        probe.className = 'dept-wait-item';
        probe.style.cssText = 'position:absolute;visibility:hidden;top:0;left:0;pointer-events:none';
        // 探针各列 width:auto，避免沿用上一轮测得的固定列宽导致测不准
        probe.innerHTML = '<span class="dept-wait-seq">000</span>' +
            '<span class="dept-wait-name">测</span>' +
            '<span class="dept-wait-gender" style="width:auto">' + genderText + '</span>' +
            '<span class="dept-wait-age" style="width:auto">' + ageText + '</span>' +
            '<span class="dept-wait-dept" style="width:auto">' + deptText + '</span>';
        listEl.appendChild(probe);
        var genderW = probe.children[2].getBoundingClientRect().width;
        var ageW = probe.children[3].getBoundingClientRect().width;
        var deptW = probe.children[4].getBoundingClientRect().width;
        listEl.removeChild(probe);
        var set = function (k, v) { if (v > 0) listEl.style.setProperty(k, (Math.ceil(v) + 4) + 'px'); };
        set('--dept-gender-w', genderW);
        set('--dept-age-w', ageW);
        set('--dept-dept-w', deptW);
    }

    /* 医技大屏（lab/imaging/pharmacy/nurse）：三种尺寸重设计
     * 竖屏：上半 当前患者（标签+姓名换行）→ 下半 排队队列（姓名/性别/年龄/开单科室）
     * 方屏：同竖屏但上半更小、当前患者文字横排「当前患者 XXX」
     * 宽屏：左侧 当前患者（标签+姓名换行），右侧一半 排队队列
     * 无患者/无队列时占位提示居中显示。 */
    function renderDeptMode(d) {
        // 大屏页仅加载 screen.js（无 Clinic），就地定义 HTML 转义
        var esc = function (s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        };
        // 未绑定（bound=false）时后端已清空数据：此处照常渲染整体轮廓，
        // 患者区/排队区显示「暂无患者」占位，大屏始终面向患者展示
        var wait = d.waiting || [];
        var cur = d.current || {};
        var nowHtml;
        if (cur.name) {
            nowHtml = '<div class="dept-now-label">当前患者</div>' +
                '<div class="dept-now-name">' + esc(maskName(cur.name)) + '</div>' +
                '<div class="dept-now-sub">' + String(cur.visit_seq).padStart(3, '0') + ' 号' +
                (cur.dept_name ? ' · ' + esc(cur.dept_name) : '') + '</div>';
        } else {
            nowHtml = '<div class="dept-now-empty">当前暂无患者</div>';
        }
        var listHtml = wait.length
            ? wait.map(function (w) {
                return '<div class="dept-wait-item">' +
                    '<span class="dept-wait-seq">' + String(w.visit_seq).padStart(3, '0') + '</span>' +
                    '<span class="dept-wait-name">' + esc(maskName(w.name)) + '</span>' +
                    '<span class="dept-wait-gender">' + esc(w.gender || '') + '</span>' +
                    '<span class="dept-wait-age">' + esc(w.age_fmt || '') + '</span>' +
                    '<span class="dept-wait-dept">' + esc(w.dept_name || '') + '</span></div>';
            }).join('')
            : '<div class="dept-wait-empty">当前暂无排队患者</div>';
        return '<div class="dept-screen">' +
            '<div class="dept-now">' + nowHtml + '</div>' +
            '<div class="dept-wait">' +
            '  <div class="dept-wait-title">排队队列</div>' +
            '  <div class="dept-wait-list">' + listHtml + '</div>' +
            '</div></div>';
    }

    /* ============ 主渲染 ============ */
    function render(d) {
        var main = document.getElementById('screenMain');
        if (!main) return;
        LAST_DATA = d;   // 缓存最近数据：窗口缩放/旋转后据此重排重裁
        // 更新科室+诊室名称标题栏
        var deptName = d.room ? d.room.dept : '';
        var roomName = d.room ? d.room.name : '';
        var titleEl = document.getElementById('roomTitle');
        if (titleEl) {
            titleEl.textContent = deptName ? deptName + ' ' + roomName : roomName;
        }
        voiceEnabled = !!d.enable_voice;
        // 医生诊室大屏：未绑定医生（或无存活心跳）时患者数据为空，
        // 但仍渲染面向患者的整体框架（就诊中/下一位/待就诊空态提示，而非医生操作提示）
        if (ROOM_TYPE === 'doctor') {
            renderDoctorMode(d);   // 内部自行写入 screenMain + 动态裁剪等待就诊
        } else {
            main.innerHTML = renderDeptMode(d);
            // 共享列宽：性别/年龄/就诊科室 各列纵向对齐
            measureDeptColumns(main.querySelector('.dept-wait-list'), d.waiting || []);
        }

        // 医生信息字号动态适配（渲染后测量单元格尺寸）
        if (ROOM_TYPE === 'doctor') fitDoctorCard();

        // 温馨提示
        if (d.tips) {
            startTips(d.tips, d.tip_interval || 5);
        }
    }

    /* ============ 叫号播报（幂等判定：当前患者变化 / 再次叫号均触发） ============
       播报对象是「正在就诊」的当前患者（医生工作站推送信号 + 回库校验后的数据）：
       · 叫号下一位 → current.flow_no 变化 → 播报新患者
       · 再次叫号   → current.called_at 变化 → 重复播报同一患者 */
    function maybeAnnounce(d) {
        if (ROOM_TYPE !== 'doctor' || d.bound === false) return;
        var cur = d.current || {};
        if (!cur.flow_no || !cur.called_at) return;
        var key = cur.flow_no + '|' + cur.called_at;
        if (key === lastCallKey) return;
        lastCallKey = key;
        var roomName = (d.room && d.room.name) || '';
        var text = '请 ' + String(cur.visit_seq).padStart(3, '0') + ' 号 ' + (cur.raw_name || cur.name || '') + ' 到 ' + roomName + ' 就诊';
        TTS.chime();
        TTS.speak(text, 2);
        TTS.resume();
    }

    /* ============ 轮询心跳 + 数据（SmartPoller 弹性兜底） ============ */
    var pollFails = 0;   // 连续失败次数：≥3 次渲染断连提示（数据可能过期），恢复后自动消失
    var lastUpdated = 0; // 数据版本戳：轮询带 last_updated，无变化时跳过渲染（接口轻量化）
    function poll() {
        fetch('/api/screen?action=heartbeat&token=' + encodeURIComponent(TOKEN) + '&last_updated=' + lastUpdated)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                pollFails = 0;   // 成功即清零，正常渲染
                if (!j.ok) { renderErr(j.msg); return; }
                // 接口轻量化：数据无变化时返回 changed:false，仅更新版本戳跳过渲染
                if (j.data && j.data.changed === false) { if (j.data.updated_at) lastUpdated = j.data.updated_at; return; }
                if (j.data && j.data.updated_at) lastUpdated = j.data.updated_at;
                render(j.data);
                maybeAnnounce(j.data);
            })
            .catch(function () {
                pollFails++;
                if (pollFails >= 3) renderErr('⚠️ 连接中断，正在重试…');
            });
    }

    function renderErr(msg) {
        var main = document.getElementById('screenMain');
        if (main) main.innerHTML = '<div class="screen-empty-big" style="color:#f88">' + (msg || '加载失败，自动重试中…') + '</div>';
    }

    setInterval(function () { if (window.speechSynthesis) TTS.resume(); }, 10000);

    // 实时推送：叫号事件到达立即刷新数据（播报更快）
    if (window.Clinic && Clinic.push && Clinic.push.supported()) {
        Clinic.push.subscribe('scr:' + TOKEN, function () { poll(); });
    }
    // 弹性兜底轮询：推流健康 60s 低频对齐；断开时 SmartPoller 自动升级应急高频（5s）。
    // 取代原盲目固死 setInterval(poll, 3000)，大幅削减 SSE 正常时的数据库 I/O 与网络开销。
    if (window.Clinic && Clinic.smartPoller) {
        var poller = Clinic.smartPoller({
            url: function () { return '/api/screen?action=heartbeat&token=' + encodeURIComponent(TOKEN) + '&last_updated=' + lastUpdated; },
            interval: 60000,
            emergencyInterval: 5000,
            fetch: function (url, ok, err) {
                poll();   // 复用 poll（含轻量 changed 判断）
                ok();
            },
        });
        poller.start();
    } else {
        poll();
        setInterval(poll, 3000);   // 无 SmartPoller 时保留旧兜底
    }
})();