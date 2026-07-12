<!DOCTYPE html>
<html lang="$ContentLocale">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Mobile login</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7;
            color: #23292f;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .08);
            padding: 2.5rem;
            width: 360px;
            max-width: 90vw;
            text-align: center;
        }
        h1 { font-size: 1.35rem; margin: 0 0 .5rem; }
        p.hint { color: #6b7280; font-size: .9rem; margin: 0 0 1.5rem; }
        .qr-wrap {
            position: relative;
            display: inline-block;
            min-height: 300px;
            min-width: 300px;
        }
        .qr-wrap img { width: 300px; height: 300px; display: block; }
        .overlay {
            position: absolute;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            background: rgba(255, 255, 255, .92);
            border-radius: 8px;
            font-weight: 600;
        }
        .overlay.show { display: flex; }
        .status { margin-top: 1.25rem; font-size: .9rem; min-height: 1.2em; }
        .status.ok { color: #14a44d; }
        .status.warn { color: #b45309; }
        button {
            margin-top: 1rem;
            border: 0;
            background: #0071c2;
            color: #fff;
            padding: .6rem 1.25rem;
            border-radius: 8px;
            font-size: .9rem;
            cursor: pointer;
        }
        button:hover { background: #005a9e; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Log in with your app</h1>
        <p class="hint">Open the app and scan this code. It refreshes automatically.</p>
        <div class="qr-wrap">
            <img id="qr" alt="Login QR code" width="300" height="300">
            <div class="overlay" id="overlay"></div>
        </div>
        <div class="status" id="status"></div>
        <button id="refresh" type="button">New code</button>
    </div>

    <script>
        (function () {
            var qr = document.getElementById('qr');
            var overlay = document.getElementById('overlay');
            var status = document.getElementById('status');
            var refreshBtn = document.getElementById('refresh');
            var pollTimer = null;
            var refreshTimer = null;

            function clearTimers() {
                if (pollTimer) clearInterval(pollTimer);
                if (refreshTimer) clearTimeout(refreshTimer);
            }

            function setStatus(msg, cls) {
                status.textContent = msg || '';
                status.className = 'status' + (cls ? ' ' + cls : '');
            }

            function showOverlay(msg) {
                overlay.textContent = msg;
                overlay.classList.add('show');
            }

            function poll(id) {
                fetch('mobilelogin/status?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.paired) {
                            clearTimers();
                            showOverlay('✓ Paired');
                            setStatus('Your device is now paired.', 'ok');
                        } else if (data.expired) {
                            clearTimers();
                            setStatus('Code expired.', 'warn');
                        }
                    })
                    .catch(function () { /* transient - keep polling */ });
            }

            function load() {
                clearTimers();
                overlay.classList.remove('show');
                setStatus('Waiting for scan…');
                fetch('mobilelogin/qrcode', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            setStatus(data.error || 'Could not create code.', 'warn');
                            return;
                        }
                        qr.src = data.image;
                        pollTimer = setInterval(function () { poll(data.id); }, 2500);
                        // Refresh shortly before the pairing token expires.
                        refreshTimer = setTimeout(load, Math.max(5, data.expires_in - 5) * 1000);
                    })
                    .catch(function () { setStatus('Network error.', 'warn'); });
            }

            refreshBtn.addEventListener('click', load);
            load();
        })();
    </script>
</body>
</html>
