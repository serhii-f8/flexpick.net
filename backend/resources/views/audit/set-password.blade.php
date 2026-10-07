<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Set your password') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1c1917; background: #fafaf9; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; padding: 16px; box-sizing: border-box; }
        .card { background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; padding: 40px; max-width: 420px; width: 100%; box-sizing: border-box; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { color: #57534e; line-height: 1.5; margin: 0 0 20px; }
        label { display: block; font-size: 13px; font-weight: bold; margin: 0 0 6px; }
        input[type=password] { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d6d3d1; border-radius: 6px; font-size: 15px; margin: 0 0 16px; }
        .error { color: #b91c1c; font-size: 13px; margin: -8px 0 16px; }
        button { width: 100%; background: #1c1917; color: #fafaf9; padding: 12px 24px; border: 0; border-radius: 6px; font-weight: bold; font-size: 15px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Set your password') }}</h1>
        <p>{{ __('Your FlexPick account for :email is ready. Choose a password, then connect GitHub, GitLab or Bitbucket so we can read a private repository.', ['email' => $user->email]) }}</p>
        <form method="POST" action="{{ $action }}">
            @csrf
            <label for="password">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
            @error('password')
                <p class="error">{{ $message }}</p>
            @enderror
            <label for="password_confirmation">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            <button type="submit">{{ __('Save and continue') }}</button>
        </form>
    </div>
</body>
</html>
