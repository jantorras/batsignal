(function () {
    'use strict';

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var BAT_PATH = 'M50 14 L46 6 L45 13 C40 12 36 13 33 16 C30 8 20 3 4 4 C12 9 14 16 12 24 C18 20 24 21 28 26 C31 22 36 22 40 28 C43 32 47 38 50 46 C53 38 57 32 60 28 C64 22 69 22 72 26 C76 21 82 20 88 24 C86 16 88 9 96 4 C80 3 70 8 67 16 C64 13 60 12 55 13 L54 6 Z';

    /* Strings come from the PHP dictionaries via window.BS_I18N (footer). */
    function tr(key, params) {
        var s = (window.BS_I18N && window.BS_I18N[key]) || key;
        for (var k in params || {}) s = s.split('{' + k + '}').join(params[k]);
        return s;
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function batSvg(cls) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 100 50');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('class', cls);
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', BAT_PATH);
        svg.appendChild(path);
        return svg;
    }

    /* ---------- Entrance: page sections rise in, staggered ---------- */
    document.querySelectorAll('main .page-header, main > .alert, main .card-bat:not(.stat-card), main .stat-card').forEach(function (node, i) {
        node.style.setProperty('--i', Math.min(i, 10));
        node.classList.add('reveal');
    });

    /* ---------- Count-up stat numbers ---------- */
    document.querySelectorAll('[data-count]').forEach(function (node) {
        var target = parseInt(node.dataset.count, 10) || 0;
        if (reduceMotion || target === 0) { node.textContent = target; return; }
        var start = performance.now(), dur = 900 + Math.min(target, 50) * 12;
        node.textContent = '0';
        requestAnimationFrame(function tick(t) {
            var p = Math.min(1, (t - start) / dur);
            node.textContent = Math.round(target * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(tick);
        });
    });

    /* ---------- Typewriter headline ---------- */
    document.querySelectorAll('[data-typewriter]').forEach(function (node) {
        var text = node.textContent.replace(/\s+/g, ' ').trim();
        if (reduceMotion || !text) return;
        node.setAttribute('aria-label', text);
        node.textContent = '';
        var span = el('span');
        span.setAttribute('aria-hidden', 'true');
        node.appendChild(span);
        node.classList.add('typing');
        var i = 0;
        (function step() {
            span.textContent = text.slice(0, ++i);
            if (i < text.length) setTimeout(step, 22 + Math.random() * 38);
            else setTimeout(function () { node.classList.add('typed'); }, 1800);
        })();
    });

    /* ---------- Bat swarm ---------- */
    function batSwarm(origin, count) {
        if (reduceMotion) return;
        count = count || 14;
        var r = origin ? origin.getBoundingClientRect() : { left: innerWidth / 2, top: innerHeight / 2, width: 0, height: 0 };
        var ox = r.left + r.width / 2, oy = r.top + r.height / 2;
        for (var i = 0; i < count; i++) {
            (function (i) {
                var bat = batSvg('flying-bat');
                var size = 20 + Math.random() * 36;
                bat.style.width = size + 'px';
                bat.style.left = ox + 'px';
                bat.style.top = oy + 'px';
                bat.style.animationDuration = (0.18 + Math.random() * 0.14) + 's';
                document.body.appendChild(bat);
                var angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.5;
                var dist = 380 + Math.random() * 620;
                var dx = Math.cos(angle) * dist, dy = Math.sin(angle) * dist;
                var wobble = (Math.random() - 0.5) * 180;
                var anim = bat.animate([
                    { transform: 'translate(-50%,-50%) scale(.2)', opacity: 0 },
                    { opacity: 1, offset: 0.12 },
                    { transform: 'translate(calc(-50% + ' + (dx * 0.45 + wobble) + 'px), calc(-50% + ' + (dy * 0.45) + 'px)) scale(1) rotate(' + ((Math.random() - 0.5) * 30) + 'deg)', offset: 0.55 },
                    { transform: 'translate(calc(-50% + ' + dx + 'px), calc(-50% + ' + dy + 'px)) scale(1.35) rotate(' + ((Math.random() - 0.5) * 40) + 'deg)', opacity: 0 }
                ], { duration: 1300 + Math.random() * 900, delay: i * 40, easing: 'cubic-bezier(.3,.6,.4,1)', fill: 'forwards' });
                anim.onfinish = function () { bat.remove(); };
            })(i);
        }
    }

    function toast(msg) {
        var t = el('div', 'bat-toast', msg);
        t.setAttribute('role', 'status');
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('out'); }, 2600);
        setTimeout(function () { t.remove(); }, 3200);
    }

    /* ---------- Konami code: light the Bat-Signal ---------- */
    var konami = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];
    var kpos = 0;
    document.addEventListener('keydown', function (e) {
        var k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        kpos = k === konami[kpos] ? kpos + 1 : (k === konami[0] ? 1 : 0);
        if (kpos < konami.length) return;
        kpos = 0;
        var sky = el('div', 'sky-signal');
        sky.setAttribute('aria-hidden', 'true');
        var disc = el('div', 'sky-disc');
        disc.appendChild(batSvg('sky-bat'));
        sky.appendChild(el('div', 'sky-beam'));
        sky.appendChild(disc);
        document.body.appendChild(sky);
        batSwarm(null, 36);
        toast(tr('batman'));
        setTimeout(function () { sky.classList.add('out'); }, 3600);
        setTimeout(function () { sky.remove(); }, 4400);
    });

    /* ---------- Login: outside the card, the pointer becomes a bat and the beam follows it ---------- */
    (function () {
        var card = document.querySelector('body.auth-page .auth-card');
        if (!card || reduceMotion) return;

        var cursor = el('div', 'bat-cursor');
        cursor.appendChild(batSvg('bat-cursor-icon'));
        document.body.appendChild(cursor);

        var tracking = false;
        function setTracking(on) {
            if (tracking === on) return;
            tracking = on;
            document.body.classList.toggle('beam-tracking', on);
            document.body.classList.toggle('bat-cursor-active', on);
        }

        document.addEventListener('mousemove', function (e) {
            var r = card.getBoundingClientRect();
            var inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
            setTracking(!inside);
            if (inside) return;

            cursor.style.left = e.clientX + 'px';
            cursor.style.top = e.clientY + 'px';

            // The beam's pivot sits below the viewport (see body::before: left 50%, bottom -20vh);
            // "up" (0deg) points straight at the top of the screen from there.
            var ox = window.innerWidth / 2, oy = window.innerHeight * 1.2;
            var deg = Math.atan2(e.clientX - ox, oy - e.clientY) * 180 / Math.PI;
            deg = Math.max(-85, Math.min(85, deg));
            document.body.style.setProperty('--beam-angle', deg.toFixed(1) + 'deg');
        });
        document.addEventListener('mouseleave', function () { setTracking(false); });
    })();

    /* ---------- Bat-Computer: live progress for "Executar ara" ---------- */
    function openBatComputer(form) {
        var siteId = form.dataset.runSite;
        var siteName = form.dataset.siteName || '';
        var trigger = form.querySelector('button');

        var overlay = el('div', 'batcomputer');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'bc-title');
        overlay.innerHTML =
            '<div class="bc-panel" tabindex="-1">' +
            '  <div class="bc-scan" aria-hidden="true"></div>' +
            '  <div class="bc-head"><span class="bc-dots" aria-hidden="true"><i></i><i></i><i></i></span>' +
            '    <span id="bc-title">BAT-COMPUTER <span class="bc-sep">//</span> <span class="bc-site"></span></span>' +
            '    <span class="bc-clock mono">00:00</span></div>' +
            '  <div class="bc-body">' +
            '    <div class="bc-status"><span class="bc-step"></span><span class="bc-pct mono">0%</span></div>' +
            '    <div class="bc-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="bc-fill"><span class="bc-glint"></span></div></div>' +
            '    <div class="bc-meta mono"><span class="bc-count">—</span><span class="bc-eta"></span></div>' +
            '    <div class="bc-log mono"></div>' +
            '  </div>' +
            '  <div class="bc-foot"><span class="bc-summary" role="status"></span>' +
            '    <button type="button" class="btn btn-bat bc-close" disabled><i class="ph ph-arrow-right" aria-hidden="true"></i><span class="bc-close-label"></span></button></div>' +
            '</div>';
        overlay.querySelector('.bc-site').textContent = siteName;
        overlay.querySelector('.bc-step').textContent = tr('init');
        overlay.querySelector('.bc-bar').setAttribute('aria-label', tr('progress_aria'));
        overlay.querySelector('.bc-close-label').textContent = tr('see_results');
        document.body.appendChild(overlay);
        document.body.classList.add('bc-open');
        requestAnimationFrame(function () { overlay.classList.add('in'); });
        overlay.querySelector('.bc-panel').focus();
        batSwarm(trigger, 16);

        var q = function (s) { return overlay.querySelector(s); };
        var fill = q('.bc-fill'), bar = q('.bc-bar'), pctEl = q('.bc-pct'), step = q('.bc-step');
        var log = q('.bc-log'), count = q('.bc-count'), eta = q('.bc-eta'), clock = q('.bc-clock');
        var summaryEl = q('.bc-summary'), closeBtn = q('.bc-close');
        var t0 = Date.now(), shown = 0, finished = false, ran = 0;

        var clockTimer = setInterval(function () {
            var s = Math.floor((Date.now() - t0) / 1000);
            clock.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
        }, 250);

        function ts() {
            var d = new Date();
            return [d.getHours(), d.getMinutes(), d.getSeconds()].map(function (n) { return String(n).padStart(2, '0'); }).join(':');
        }
        function line(text, cls) {
            var row = el('div', 'bc-line ' + (cls || ''));
            row.appendChild(el('span', 'bc-ts', '[' + ts() + ']'));
            row.appendChild(document.createTextNode(' ' + text));
            log.appendChild(row);
            while (log.children.length > 250) log.removeChild(log.firstChild);
            log.scrollTop = log.scrollHeight;
        }
        function setPct(p) {
            p = Math.max(shown, Math.min(100, p));
            shown = p;
            fill.style.width = p + '%';
            pctEl.textContent = Math.floor(p) + '%';
            bar.setAttribute('aria-valuenow', String(Math.floor(p)));
            var elapsed = (Date.now() - t0) / 1000;
            if (p > 4 && p < 100) {
                var left = Math.max(1, Math.round(elapsed * (100 - p) / p));
                eta.textContent = tr('seconds_left', { n: left });
            } else if (p >= 100) {
                eta.textContent = elapsed.toFixed(1) + ' s';
            }
        }
        function finish(summary, failed) {
            finished = true;
            clearInterval(clockTimer);
            setPct(100);
            closeBtn.disabled = false;
            closeBtn.focus();
            // Only real failures (down / pages with errors) light the red alert; warnings stay amber.
            var serious = (summary.down || 0) + (summary.degraded || 0);
            var counts = { down: summary.down || 0, degraded: summary.degraded || 0, warning: summary.warning || 0, ok: summary.ok || 0 };
            if (failed) {
                overlay.classList.add('is-error');
                step.textContent = tr('lost');
                summaryEl.textContent = tr('lost_text');
                q('.bc-close-label').textContent = tr('close');
            } else if (serious > 0) {
                overlay.classList.add('is-down');
                step.textContent = tr('alert');
                summaryEl.textContent = tr('alert_text', counts);
                line(tr('alert_line', { n: serious }), 'bad');
            } else if (counts.warning > 0) {
                overlay.classList.add('is-warn');
                step.textContent = tr('warn');
                summaryEl.textContent = tr('warn_text', counts);
                line('>> ' + tr('warn_text', counts), 'warn');
            } else {
                overlay.classList.add('is-ok');
                step.textContent = tr('done');
                summaryEl.textContent = tr('done_text');
                line(tr('done_line', { n: summary.ok || 0 }), 'ok');
            }
        }

        closeBtn.addEventListener('click', function () {
            window.location.href = 'site.php?id=' + encodeURIComponent(siteId) + '&ran=' + ran;
        });
        overlay.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && finished) closeBtn.click();
            if (e.key === 'Tab') { e.preventDefault(); if (!closeBtn.disabled) closeBtn.focus(); }
        });

        line(tr('boot1'), 'dim');
        line(tr('boot2'), 'dim');

        var marks = { ok: tr('mark_ok'), warning: tr('mark_warning'), degraded: tr('mark_degraded'), down: tr('mark_down') };
        var pageStyle = { ok: ['✔', 'page'], broken: ['▲', 'warn'], slow: ['▲', 'warn'], timeout: ['▲', 'warn'], error: ['✖', 'bad'] };
        var total = 0;
        function handle(ev) {
            switch (ev.type) {
                case 'start':
                    total = ev.total;
                    count.textContent = tr('n_of_checks', { i: 0, n: total });
                    line(tr('target', { n: total }));
                    break;
                case 'check_start':
                    step.textContent = ev.name;
                    count.textContent = tr('n_of_checks', { i: ev.index, n: total });
                    line('> [' + ev.index + '] ' + ev.name.toUpperCase() + (ev.kind === 'crawl' ? tr('crawling_suffix') : ''), 'head');
                    break;
                case 'page':
                    step.textContent = tr('crawling', { url: ev.url });
                    var ps = pageStyle[ev.category] || (ev.problem ? pageStyle.error : pageStyle.ok);
                    line('   ' + ps[0] + ' ' + ev.url + (ev.problem ? ' — ' + ev.problem : '  ' + ev.ms + ' ms'), ps[1]);
                    break;
                case 'check_done':
                    ran++;
                    line('   ' + marks[ev.status] + ' · ' + (ev.detail || tr('all_ok')) + ' · ' + ev.ms + ' ms', ev.status === 'ok' ? 'ok' : (ev.status === 'warning' ? 'warn' : 'bad'));
                    break;
                case 'done':
                    finish(ev.summary, false);
                    return;
            }
            if (typeof ev.percent === 'number') setPct(ev.percent);
        }

        var body = new URLSearchParams({ site_id: siteId });
        fetch('run.php', { method: 'POST', body: body, credentials: 'same-origin' }).then(function (res) {
            if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);
            var reader = res.body.getReader(), dec = new TextDecoder(), buf = '';
            return (function pump() {
                return reader.read().then(function (r) {
                    if (r.done) { if (!finished) throw new Error('stream closed'); return; }
                    buf += dec.decode(r.value, { stream: true });
                    var i;
                    while ((i = buf.indexOf('\n')) >= 0) {
                        var raw = buf.slice(0, i).trim();
                        buf = buf.slice(i + 1);
                        if (raw) handle(JSON.parse(raw));
                    }
                    return pump();
                });
            })();
        }).catch(function (err) {
            line('ERROR: ' + err.message, 'bad');
            finish({}, true);
        });
    }

    document.querySelectorAll('form[data-run-site]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.fetch || !window.ReadableStream) return; // classic POST fallback
            e.preventDefault();
            openBatComputer(form);
        });
    });

    window.BatSignal = { batSwarm: batSwarm, toast: toast };
})();
