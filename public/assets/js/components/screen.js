/**
 * ============================================================
 * screen.js v1.0.0 — 叫号大屏前端（免登常驻 + 双模式 + 语音播报）
 * ============================================================
 * 功能：
 * 1. 每 3 秒轮询 /api/screen.php?action=heartbeat&token=xxx（心跳 + 数据）
 * 2. 双模式渲染：doctor 大卡片 / 医技列表看板
 * 3. 姓名脱敏（服务端已处理）与语音播报（Web Speech API）
 * 4. 自动播放解锁遮罩 + 心跳防休眠 + 静音切换
 * ============================================================ */
(function () {
    var TOKEN = document.body.getAttribute('data-token');
    var ROOM_TYPE = document.body.getAttribute('data-roomtype') || 'doctor';
    var lastCallKey = '';       // 叫号事件幂等（避免重复播报）
    var muted = false;
    var voiceEnabled = true;

    /* ============ 时钟 ============ */
    function tickClock() {
        var el = document.getElementById('clock');
        if (!el) return;
        var d = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        var wd = ['日', '一', '二', '三', '四', '五', '六'][d.getDay()];
        el.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
            ' 星期' + wd + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
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
        /** 前奏提示音（Web Audio 生成轻量"叮咚"，非音频文件） */
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
            } catch (e) { /* 音频不可用则静默 */ }
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
            // 防休眠：部分电视大屏语音引擎长时间无叫号后暂停
            if (window.speechSynthesis && window.speechSynthesis.paused) window.speechSynthesis.resume();
        },
    };
    // 预取语音列表（部分浏览器需异步）
    if (window.speechSynthesis) {
        speechSynthesis.getVoices();
        speechSynthesis.onvoiceschanged = function () { speechSynthesis.getVoices(); };
    }

    /* ============ 自动播放解锁 ============ */
    var mask = document.getElementById('autoplayMask');
    function unlockAutoplay() {
        if (!mask) return;
        mask.style.display = 'none';
        // 触发一次静默语音以解锁自动播放权限
        if (window.speechSynthesis) {
            var u = new SpeechSynthesisUtterance('');
            u.volume = 0;
            speechSynthesis.speak(u);
        }
    }
    if (mask) mask.addEventListener('click', unlockAutoplay);

    /* ============ 双模式渲染 ============ */
    function maskName(n) { return n || ''; }   // 服务端已脱敏

    function renderDoctorMode(d) {
        var cur = d.current || {};
        var next = d.next || {};
        var wait = d.waiting || [];
        var curCard = cur.name
            ? '<div class="screen-cur-name">' + maskName(cur.name) + '</div>' +
              '<div class="screen-cur-seq">' + String(cur.visit_seq).padStart(3, '0') + ' 号</div>' +
              '<div class="screen-cur-doctor">' + (d.doctor ? '坐诊医生：' + d.doctor : '') + '</div>'
            : '<div class="screen-empty-big">暂无就诊中患者</div>';
        var nextCard = next.name
            ? '<div class="screen-next-name">' + maskName(next.name) + '</div>' +
              '<div class="screen-next-seq">' + String(next.visit_seq).padStart(3, '0') + ' 号</div>'
            : '<div class="screen-empty">暂无候诊患者</div>';
        var waitList = wait.length
            ? wait.map(function (w, i) {
                return '<div class="screen-wait-item">' +
                    '<span class="screen-wait-seq">' + String(w.visit_seq).padStart(3, '0') + '</span>' +
                    '<span>' + maskName(w.name) + '</span>' +
                    '<span class="screen-wait-extra">' + (w.gender || '') + ' ' + (w.age_fmt || '') + '</span></div>';
            }).join('')
            : '<div class="screen-empty">暂无</div>';
        return '<div class="screen-doctor-grid">' +
            '<div class="screen-panel screen-cur-panel"><div class="screen-panel-title">正在就诊</div>' + curCard + '</div>' +
            '<div class="screen-right">' +
            '  <div class="screen-panel screen-next-panel"><div class="screen-panel-title">请就诊</div>' + nextCard + '</div>' +
            '  <div class="screen-panel screen-wait-panel"><div class="screen-panel-title">等待就诊（' + wait.length + '）</div>' + waitList + '</div>' +
            '</div></div>';
    }

    function renderDeptMode(d) {
        // 医技列表看板：队列 + 当前呼叫高亮
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
        document.getElementById('roomTitle').textContent = d.room ? d.room.name : '';
        voiceEnabled = !!d.enable_voice;
        main.innerHTML = ROOM_TYPE === 'doctor' ? renderDoctorMode(d) : renderDeptMode(d);
    }

    /* ============ 叫号播报（幂等判定） ============ */
    function maybeAnnounce(d) {
        var next = d.next || {};
        if (!next.flow_no) return;
        var key = next.flow_no + '|' + next.visit_seq;
        if (key === lastCallKey) return;
        lastCallKey = key;
        var roomName = (d.room && d.room.name) || '';
        var text = '请 ' + String(next.visit_seq).padStart(3, '0') + ' 号 ' + (next.name || '') + ' 到 ' + roomName + ' 就诊';
        TTS.chime();          // 前奏提示音
        TTS.speak(text, 2);   // 语音播报 2 遍
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
            .catch(function () { /* 网络波动静默重试 */ });
    }

    function renderErr(msg) {
        var main = document.getElementById('screenMain');
        if (main) main.innerHTML = '<div class="screen-empty-big" style="color:#f88">' + (msg || '加载失败，自动重试中…') + '</div>';
    }

    /* 防休眠：定期恢复语音引擎 */
    setInterval(function () { if (window.speechSynthesis) TTS.resume(); }, 10000);

    /* 静音切换 */
    var muteBtn = document.getElementById('muteBtn');
    if (muteBtn) muteBtn.addEventListener('click', function () {
        muted = !muted;
        muteBtn.textContent = muted ? '🔇' : '🔊';
        if (muted && window.speechSynthesis) speechSynthesis.cancel();
    });

    poll();
    setInterval(poll, 3000);
})();
