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

    /* ============ 竖屏/横屏检测 ============ */
    function detectOrientation() {
        var w = window.innerWidth || document.documentElement.clientWidth;
        var h = window.innerHeight || document.documentElement.clientHeight;
        var body = document.body;
        if (w < h) {
            body.classList.add('screen-portrait');
            body.classList.remove('screen-landscape');
        } else {
            body.classList.add('screen-landscape');
            body.classList.remove('screen-portrait');
        }
    }
    detectOrientation();
    window.addEventListener('resize', detectOrientation);

    /* ============ 时钟 ============ */
    function tickClock() {
        var el = document.getElementById('clock');
        if (!el) return;
        var d = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        var wd = ['日', '一', '二', '三', '四', '五', '六'][d.getDay()];
        el.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
            ' 周' + wd + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
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

        function showTip(i) {
            var t = tips[i % tips.length] || '';
            // 如果文本超长（> 30 字），启用跑马灯
            if (t.length > 30) {
                inner.innerHTML = '<div class="call-tips-marquee"><span>' + t + '</span></div>';
            } else {
                inner.textContent = t;
            }
        }
        tipsIndex = 0;
        showTip(0);
        if (tips.length > 1) {
            tipsTimer = setInterval(function () {
                tipsIndex = (tipsIndex + 1) % tips.length;
                showTip(tipsIndex);
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
        // 横屏/方屏（宽>=高）显示 16 位等待就诊（双排），竖屏显示 8 位；
        // 过号患者始终排在列表末尾（末尾留位优先过号患者），保证「过」徽标可见
        var isLand = !document.body.classList.contains('screen-portrait');
        var maxWait = isLand ? 16 : 8;
        var waitRaw = d.waiting || [];
        var missedArr = waitRaw.filter(function (w) { return w.missed; }).slice(0, maxWait);
        var normalArr = waitRaw.filter(function (w) { return !w.missed; }).slice(0, maxWait - missedArr.length);
        var wait = normalArr.concat(missedArr);

        var curCard = cur.name
            ? '<div class="screen-cur-name">' + maskName(cur.name) + '</div>' +
              '<div class="screen-cur-seq">' + seqLabel(cur) + '</div>'
            : '<div class="screen-empty-big">暂无就诊中患者</div>';
        var nextCard = next.name
            ? '<div class="screen-next-name">' + maskName(next.name) + '</div>' +
              '<div class="screen-next-seq">' + seqLabel(next) + '</div>'
            : '<div class="screen-empty">暂无候诊患者</div>';
        var waitList = wait.length
            ? wait.map(function (w) {
                return '<div class="screen-wait-item">' +
                    (w.missed ? '<span class="screen-wait-miss">过</span>' : '<span class="screen-wait-miss screen-wait-miss-empty"></span>') +
                    '<span class="screen-wait-seq">' + String(w.visit_seq).padStart(3, '0') + (w.is_transfer ? '★' : '') + '</span>' +
                    '<span class="screen-wait-name">' + maskName(w.name) + '</span>' +
                    '<span class="screen-wait-gender">' + (w.gender || '') + '</span>' +
                    '<span class="screen-wait-age">' + (w.age_fmt || '') + '</span></div>';
            }).join('')
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

        // ===== 竖屏排版：医生卡在上，主区（正在就诊/下一位 | 等待就诊）在下 =====
        if (!isLand) {
            return '<div class="screen-doctor-grid">' +
                docCard +
                '<div class="screen-main-area">' +
                '<div class="screen-left-col">' +
                '  <div class="screen-panel screen-cur-panel"><div class="screen-panel-title">正在就诊</div>' +
                '    <div class="screen-panel-body"><div class="screen-panel-inner">' + curCard + '</div></div></div>' +
                '  <div class="screen-panel screen-next-panel"><div class="screen-panel-title">下一位</div>' +
                '    <div class="screen-panel-body"><div class="screen-panel-inner">' + nextCard + '</div></div></div>' +
                '</div>' +
                '<div class="screen-wait-panel"><div class="screen-panel-title">等待就诊（' + wait.length + '）</div>' +
                '  <div class="screen-panel-body"><div class="screen-panel-inner">' + waitList + '</div></div></div>' +
                '</div></div>';
        }
        // ===== 横屏/方屏排版：上半 医生卡(左) | 正在就诊+下一位(右)；下半 等待就诊双排 =====
        return '<div class="screen-land-grid">' +
            '<div class="screen-land-top">' +
            '  <div class="screen-land-doctor">' + docCard + '</div>' +
            '  <div class="screen-land-main">' +
            '    <div class="screen-panel screen-cur-panel"><div class="screen-panel-title">正在就诊</div>' +
            '      <div class="screen-panel-body"><div class="screen-panel-inner">' + curCard + '</div></div></div>' +
            '    <div class="screen-panel screen-next-panel"><div class="screen-panel-title">下一位</div>' +
            '      <div class="screen-panel-body"><div class="screen-panel-inner">' + nextCard + '</div></div></div>' +
            '  </div>' +
            '</div>' +
            '<div class="screen-wait-panel"><div class="screen-panel-title">等待就诊（' + wait.length + '）</div>' +
            '  <div class="screen-wait-land-list">' + waitList + '</div></div>' +
            '</div>';
    }

    function renderDeptMode(d) {
        var wait = d.waiting || [];
        var cur = d.current || {};
        var list = wait.map(function (w, i) {
            var isCur = cur.name && w.visit_seq === cur.visit_seq && w.flow_no === cur.flow_no;
            return '<div class="screen-dept-item' + (isCur ? ' screen-dept-current' : '') + '">' +
                '<span class="screen-dept-seq">' + String(w.visit_seq).padStart(3, '0') + '</span>' +
                '<span class="screen-dept-name">' + maskName(w.name) + '</span>' +
                '<span class="screen-dept-extra">' + (w.gender || '') + ' ' + (w.age_fmt || '') + '</span>' +
                '<span class="screen-dept-flow">' + w.flow_no + '</span></div>';
        }).join('') || '<div class="screen-empty-big">当前暂无排队患者</div>';
        return '<div class="screen-dept-head">' +
            '<div class="screen-panel-title">排队队列</div>' +
            (cur.name ? '<div class="screen-dept-calling">呼叫：' + maskName(cur.name) + '（' + String(cur.visit_seq).padStart(3, '0') + ' 号）</div>' : '') +
            '</div><div class="screen-dept-list">' + list + '</div>';
    }

    /* ============ 主渲染 ============ */
    function render(d) {
        var main = document.getElementById('screenMain');
        if (!main) return;
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
        main.innerHTML = ROOM_TYPE === 'doctor' ? renderDoctorMode(d) : renderDeptMode(d);

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

    /* ============ 轮询心跳 + 数据 ============ */
    var pollFails = 0;   // 连续失败次数：≥3 次渲染断连提示（数据可能过期），恢复后自动消失
    function poll() {
        fetch('/api/screen?action=heartbeat&token=' + encodeURIComponent(TOKEN))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                pollFails = 0;   // 成功即清零，正常渲染
                if (!j.ok) { renderErr(j.msg); return; }
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

    poll();
    setInterval(poll, 3000);
})();