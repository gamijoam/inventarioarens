<?php

namespace App\Modules\Auth\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class McpLoginController
{
    public function create(): View
    {
        return view('mcp.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();
        $allowedEmail = strtolower((string) config('mcp.allowed_admin_email'));

        if (! $user || ! Hash::check($credentials['password'], $user->password) || ! $user->isPlatformAdmin() || strtolower($user->email) !== $allowedEmail) {
            return back()->withErrors(['email' => 'Las credenciales no son validas.'])->onlyInput('email');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('spa.root'));
    }
}
