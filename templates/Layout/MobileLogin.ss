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

    <h1><%t Joelgrondrup\Tokenauth\MobileLogin.HEADING 'Log in with your app' %></h1>

    <% if $IOSAppURL || $AndroidAppURL %>
        <div class="app-stores">
            <% if $IOSAppURL %><a href="$IOSAppURL" target="_blank" rel="noopener"><%t Joelgrondrup\Tokenauth\MobileLogin.APPSTORE 'Download on the App Store' %></a><% end_if %>
            <% if $AndroidAppURL %><a href="$AndroidAppURL" target="_blank" rel="noopener"><%t Joelgrondrup\Tokenauth\MobileLogin.GOOGLEPLAY 'Get it on Google Play' %></a><% end_if %>
        </div>
    <% end_if %>

    <p><%t Joelgrondrup\Tokenauth\MobileLogin.INTRO 'Open the app and scan this code. It refreshes automatically.' %></p>
    <div class="qr-wrap">
        <img id="tokenauth-qr" alt="<%t Joelgrondrup\Tokenauth\MobileLogin.QRALT 'Login QR code' %>" width="300" height="300">
        <div class="overlay" id="tokenauth-overlay"></div>
    </div>
    <div class="status" id="tokenauth-status"></div>
    <p><button id="tokenauth-refresh" type="button"><%t Joelgrondrup\Tokenauth\MobileLogin.NEWCODE 'New code' %></button></p>

    <script>
        (function () {
            var i18n = {
                paired: "<%t Joelgrondrup\Tokenauth\MobileLogin.PAIRED 'Paired' %>",
                pairedStatus: "<%t Joelgrondrup\Tokenauth\MobileLogin.PAIREDSTATUS 'Your device is now paired.' %>",
                expired: "<%t Joelgrondrup\Tokenauth\MobileLogin.EXPIRED 'Code expired.' %>",
                waiting: "<%t Joelgrondrup\Tokenauth\MobileLogin.WAITING 'Waiting for scan…' %>",
                createError: "<%t Joelgrondrup\Tokenauth\MobileLogin.CREATEERROR 'Could not create code.' %>",
                networkError: "<%t Joelgrondrup\Tokenauth\MobileLogin.NETWORKERROR 'Network error.' %>"
            };
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
                        if (data.paired) { clearTimers(); showOverlay('✓ ' + i18n.paired); setStatus(i18n.pairedStatus, 'ok'); }
                        else if (data.expired) { clearTimers(); setStatus(i18n.expired, 'warn'); }
                    }).catch(function () {});
            }
            function load() {
                clearTimers();
                overlay.classList.remove('show');
                setStatus(i18n.waiting);
                fetch('mobilelogin/qrcode', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) { setStatus(data.error || i18n.createError, 'warn'); return; }
                        qr.src = data.image;
                        pollTimer = setInterval(function () { poll(data.id); }, 2500);
                        refreshTimer = setTimeout(load, Math.max(5, data.expires_in - 5) * 1000);
                    }).catch(function () { setStatus(i18n.networkError, 'warn'); });
            }
            refreshBtn.addEventListener('click', load);
            load();
        })();
    </script>
</div>
