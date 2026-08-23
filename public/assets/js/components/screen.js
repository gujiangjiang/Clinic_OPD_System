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

    /* ============ 双模式渲染 ============ */
    function maskName(n) { return n || ''; }

    function renderDoctorMode(d) {
        var cur = d.current || {};
        var next = d.next || {};
        var wait = d.waiting || [];
        var doc = d.doctor || {};

        // 就诊序号：转诊患者显示完整序号 + （转）
        function seqLabel(r) {
            if (!r) return '';
            var s = String(r.visit_seq).padStart(3, '0') + ' 号';
            if (r.is_transfer) {
                s = (r.first_dept_name || '转科') + ' ' + s + '（转）';
            }
            return s;
        }

        var curCard = cur.name
            ? '<div class="screen-cur-name">' + maskName(cur.name) + '</div>' +
              '<div class="screen-cur-seq">' + seqLabel(cur) + '</div>'
            : '<div class="screen-empty-big">暂无就诊中患者</div>';
        var nextCard = next.name
            ? '<div class="screen-next-name">' + maskName(next.name) + '</div>' +
              '<div class="screen-next-seq">' + seqLabel(next) + '</div>'
            : '<div class="screen-empty">暂无候诊患者</div>';
        var waitList = wait.length
            ? wait.map(function (w, i) {
                return '<div class="screen-wait-item">' +
                    '<span class="screen-wait-seq">' + String(w.visit_seq).padStart(3, '0') + (w.is_transfer ? '★' : '') + '</span>' +
                    '<span class="screen-wait-name">' + maskName(w.name) + '</span>' +
                    '<span class="screen-wait-extra">' + (w.gender || '') + ' ' + (w.age_fmt || '') + '</span></div>';
            }).join('')
            : '<div class="screen-empty">暂无</div>';

        // 医生信息卡（占上方约一半区域：左侧头像，右侧姓名/工号/职称 + 大块介绍区）
        var docCard = doc.name
            ? '<div class="screen-doctor-card">' +
              '<div class="screen-doc-photo">' + (doc.photo ? '<img src="' + doc.photo + '">' : '👨‍⚕️') + '</div>' +
              '<div class="screen-doc-info">' +
              '<div class="screen-doc-head">' +
              '<div class="screen-doc-name">' + doc.name + '</div>' +
              '<div class="screen-doc-meta">' +
              (doc.emp_no ? '<span class="screen-doc-meta-item">工号 ' + doc.emp_no + '</span>' : '') +
              (doc.title ? '<span class="screen-doc-meta-item">' + doc.title + '</span>' : '') +
              '</div></div>' +
              '<div class="screen-doc-intro">' + (doc.intro || '暂无医生介绍') + '</div>' +
              '</div></div>'
            : '<div class="screen-doctor-card screen-doctor-card-empty"><div class="screen-doc-photo">👨‍⚕️</div>' +
              '<div class="screen-doc-info"><div class="screen-doc-name">医生出诊中</div>' +
              '<div class="screen-doc-intro">暂无医生信息</div></div></div>';

        return '<div class="screen-doctor-grid">' +
            docCard +
            '<div class="screen-main-area">' +
            '<div class="screen-left-col">' +
            '  <div class="screen-panel screen-cur-panel"><div class="screen-panel-title">正在就诊</div>' + curCard + '</div>' +
            '  <div class="screen-panel screen-next-panel"><div class="screen-panel-title">下一位</div>' + nextCard + '</div>' +
            '</div>' +
            '<div class="screen-wait-panel"><div class="screen-panel-title">等待就诊（' + wait.length + '）</div>' + waitList + '</div>' +
            '</div></div>';
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
        main.innerHTML = ROOM_TYPE === 'doctor' ? renderDoctorMode(d) : renderDeptMode(d);

        // 温馨提示
        if (d.tips) {
            startTips(d.tips, d.tip_interval || 5);
        }
    }

    /* ============ 叫号播报（幂等判定） ============ */
    function maybeAnnounce(d) {
        var next = d.next || {};
        if (!next.flow_no) return;
        var key = next.flow_no + '|' + next.visit_seq;
        if (key === lastCallKey) return;
        lastCallKey = key;
        var roomName = (d.room && d.room.name) || '';
        var text = '请 ' + String(next.visit_seq).padStart(3, '0') + ' 号 ' + (next.raw_name || next.name || '') + ' 到 ' + roomName + ' 就诊';
        TTS.chime();
        TTS.speak(text, 2);
        TTS.resume();
    }

    /* ============ 轮询心跳 + 数据 ============ */
    function poll() {
        fetch('/api/screen?action=heartbeat&token=' + encodeURIComponent(TOKEN))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { renderErr(j.msg); return; }
                render(j.data);
                maybeAnnounce(j.data);
            })
            .catch(function () {});
    }

    function renderErr(msg) {
        var main = document.getElementById('screenMain');
        if (main) main.innerHTML = '<div class="screen-empty-big" style="color:#f88">' + (msg || '加载失败，自动重试中…') + '</div>';
    }

    setInterval(function () { if (window.speechSynthesis) TTS.resume(); }, 10000);

    poll();
    setInterval(poll, 3000);
})();