<?php

namespace App\Http\Controllers;

use App\Filament\Dashboard\Pages\GitConnections;
use App\Models\User;
use App\Services\AuditAccountProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The "set your password" link an auto-created landing account is emailed.
 * Signed, and good only while the account still has the random password it
 * was created with: once a password is chosen the link is dead, so a leaked
 * copy cannot take the account over.
 */
class AuditAccountPasswordController extends Controller
{
    public function show(Request $request, User $user, AuditAccountProvisioner $provisioner): View
    {
        abort_unless($provisioner->awaitsPassword($user), 403);

        return view('audit.set-password', [
            'user' => $user,
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user, AuditAccountProvisioner $provisioner): RedirectResponse
    {
        abort_unless($provisioner->awaitsPassword($user), 403);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $provisioner->setPassword($user, $validated['password']);

        auth()->login($user);
        $request->session()->regenerate();

        $workspace = $provisioner->workspaceFor($user);

        return $workspace === null
            ? redirect()->route('dashboard')
            : redirect(GitConnections::getUrl(panel: 'dashboard', tenant: $workspace));
    }
}
