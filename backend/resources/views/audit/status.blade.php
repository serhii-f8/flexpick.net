<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Audit status') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1c1917; background: #fafaf9; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; padding: 40px; max-width: 460px; text-align: center; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { color: #57534e; line-height: 1.5; margin: 0; }
        .spinner { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #d4a853; margin-right: 8px; animation: pulse 1.6s ease-in-out infinite; }
        @keyframes pulse { 0%, 100% { opacity: 0.35; } 50% { opacity: 1; } }
        .btn { display: none; margin-top: 20px; background: #1c1917; color: #fafaf9; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; }
        .muted { font-size: 12px; color: #a8a29e; margin-top: 18px; }
    </style>
</head>
<body data-poll-url="{{ $pollUrl }}">
    <div class="card">
        <h1>{{ __('Your codebase audit') }}</h1>
        <p><span class="spinner" id="spinner"></span><span id="status-label">{{ $label }}</span></p>
        <a class="btn" id="report-link" href="#">{{ __('Open my report →') }}</a>
        <p class="muted">{{ __('This page updates automatically. We also email you at every step — safe to close.') }}</p>
    </div>
    <script>
        (function () {
            var pollUrl = document.body.dataset.pollUrl;
            var label = document.getElementById('status-label');
            var link = document.getElementById('report-link');
            var spinner = document.getElementById('spinner');

            function poll() {
                fetch(pollUrl, { headers: { Accept: 'application/json' } })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (!data) return setTimeout(poll, 10000);
                        label.textContent = data.label;
                        if (data.report_url) {
                            link.href = data.report_url;
                            link.style.display = 'inline-block';
                            spinner.style.display = 'none';
                            return;
                        }
                        if (data.failed) { spinner.style.display = 'none'; return; }
                        setTimeout(poll, 5000);
                    })
                    .catch(function () { setTimeout(poll, 10000); });
            }

            setTimeout(poll, 5000);
        })();
    </script>
</body>
</html>
