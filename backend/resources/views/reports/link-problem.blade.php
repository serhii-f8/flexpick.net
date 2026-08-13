{{--
    Rendered for a signature Laravel rejected. `$expired` distinguishes the two
    causes it collapses into one exception: a link whose validity window has
    passed, versus one that arrived damaged. Telling a customer their link
    expired when their mail client truncated it sends them to support with the
    wrong problem — and on the verification path, tells them to reply to a
    report email they have not been sent yet.
--}}
@php
    $heading = $expired
        ? ($context === 'verification'
            ? __('This verification link has expired')
            : __('This report link has expired'))
        : ($context === 'verification'
            ? __('This verification link looks incomplete')
            : __('This report link looks incomplete'));

    $body = $expired
        ? ($context === 'verification'
            ? __('Verification links are valid for :hours hours. Submit your repository again and we\'ll send you a fresh one.', ['hours' => config('audit.verification_link_hours')])
            : __('Report links are valid for :days days. Reply to your report email and we\'ll send you a fresh one.', ['days' => config('audit.report_link_days')]))
        : ($context === 'verification'
            ? __('Some email apps shorten or alter long links. Open the link directly from your verification email rather than copying it, or submit your repository again to get a new one.')
            : __('Some email apps shorten or alter long links. Open the link directly from your report email rather than copying it, or reply to that email and we\'ll send you a fresh one.'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $expired ? __('Link expired') : __('Link not valid') }}</title></head>
<body style="font-family: sans-serif; margin: 64px auto; max-width: 480px; text-align: center;">
    <h1>{{ $heading }}</h1>
    <p>{{ $body }}</p>
</body>
</html>
