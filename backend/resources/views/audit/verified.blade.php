<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Email confirmed') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1c1917; background: #fafaf9; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; padding: 40px; max-width: 440px; text-align: center; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { color: #57534e; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Email confirmed') }}</h1>
        <p>{{ __('Thanks — we\'re checking your repository now. You\'ll get an email with next steps (or your report) shortly.') }}</p>
    </div>
</body>
</html>
