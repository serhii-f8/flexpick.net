<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ __('Link expired') }}</title></head>
<body style="font-family: sans-serif; margin: 64px auto; max-width: 480px; text-align: center;">
    <h1>{{ __('This report link has expired') }}</h1>
    <p>{{ __('Report links are valid for :days days. Reply to your report email and we\'ll send you a fresh one.', ['days' => config('audit.report_link_days')]) }}</p>
</body>
</html>
