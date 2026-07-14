<div class="tokenauth-mobilelogin">
    <style>
        .tokenauth-mobilelogin { max-width: 420px; margin: 2rem auto; text-align: center; }
        .tokenauth-mobilelogin .qr-wrap { position: relative; display: inline-block; min-height: 300px; min-width: 300px; }
        .tokenauth-mobilelogin .qr-wrap img { width: 300px; height: 300px; display: block; }
        .tokenauth-mobilelogin .overlay { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; flex-direction: column; background: rgba(255,255,255,.92); font-weight: 600; }
        .tokenauth-mobilelogin .overlay.show { display: flex; }
        .tokenauth-mobilelogin .status { margin-top: 1rem; min-height: 1.2em; }
        .tokenauth-mobilelogin .status.ok { color: #14a44d; }
        .tokenauth-mobilelogin .status.warn { color: #b45309; }
        .tokenauth-mobilelogin .app-stores { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; margin: 1rem 0; }
        .tokenauth-mobilelogin .app-stores a { display: inline-flex; align-items: center; gap: .5rem; padding: .55rem 1rem; border-radius: 8px; background: #111; color: #fff; text-decoration: none; font-size: .9rem; }
        .tokenauth-mobilelogin .app-stores a:hover { background: #000; }
    </style>

    <h1>Log in with your app</h1>

    <% if $IOSAppURL || $AndroidAppURL %>
        <div class="app-stores">
            <% if $IOSAppURL %><a href="$IOSAppURL" target="_blank" rel="noopener">Download on the App Store</a><% end_if %>
            <% if $AndroidAppURL %><a href="$AndroidAppURL" target="_blank" rel="noopener">Get it on Google Play</a><% end_if %>
        </div>
    <% end_if %>

    <p>Open the app and scan this code. It refreshes automatically.</p>
    <div class="qr-wrap">
        <img id="tokenauth-qr" alt="Login QR code" width="300" height="300">
        <div class="overlay" id="tokenauth-overlay"></div>
    </div>
    <div class="status" id="tokenauth-status"></div>
    <p><button id="tokenauth-refresh" type="button">New code</button></p>

    <script>
        (function () {
            var qr = document.getElementById('tokenauth-qr');
            var overlay = document.getElementById('tokenauth-overlay');
            var status = document.getElementById('tokenauth-status');
            var refreshBtn = document.getElementById('tokenauth-refresh');
            var pollTimer = null, refreshTimer = null;
            function clearTimers() { if (pollTimer) clearInterval(pollTimer); if (refreshTimer) clearTimeout(refreshTimer); }
            function setStatus(msg, cls) { status.textContent = msg || ''; status.className = 'status' + (cls ? ' ' + cls : ''); }
            function showOverlay(msg) { overlay.textContent = msg; overlay.classList.add('show'); }
            function poll(id) {
                fetch('mobilelogin/status?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.paired) { clearTimers(); showOverlay('✓ Paired'); setStatus('Your device is now paired.', 'ok'); }
                        else if (data.expired) { clearTimers(); setStatus('Code expired.', 'warn'); }
                    }).catch(function () {});
            }
            function load() {
                clearTimers();
                overlay.classList.remove('show');
                setStatus('Waiting for scan…');
                fetch('mobilelogin/qrcode', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) { setStatus(data.error || 'Could not create code.', 'warn'); return; }
                        qr.src = data.image;
                        pollTimer = setInterval(function () { poll(data.id); }, 2500);
                        refreshTimer = setTimeout(load, Math.max(5, data.expires_in - 5) * 1000);
                    }).catch(function () { setStatus('Network error.', 'warn'); });
            }
            refreshBtn.addEventListener('click', load);
            load();
        })();
    </script>
</div>
