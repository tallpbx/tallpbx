<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\InitialAdminSetupController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\TenantSwitchController;
use App\Http\Middleware\EnforcePanelLivewireActionPermissions;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;
use Modules\Admin\Livewire\ProfileEdit;
use Modules\Auth\Livewire\ForgotPassword;
use Modules\Auth\Livewire\Register;
use Modules\Auth\Livewire\ResetPassword;
use Modules\Tenant\Livewire\Dashboard;

// ─── Livewire update endpoint ───────────────────────────────────────────────
// Replace Livewire's default update route so panel module components are
// authorized on every action. Page routes carry admin.can:* middleware,
// but actions travel through this shared endpoint instead; without the
// middleware a view-only user could invoke delete/save actions. Auth and
// layout components remain unrestricted here and keep their own checks.
Livewire::setUpdateRoute(function ($handle, $path) {
    return Route::post($path, $handle)
        ->middleware(['web', RequireLivewireHeaders::class, EnforcePanelLivewireActionPermissions::class])
        ->name('panel.livewire.update');
});

// ─── Root ───────────────────────────────────────────────────────────────────
Route::get('/', function () {
    if (auth()->guard('admin')->check()) {
        return redirect()->route('panel.dashboard');
    }
    if (auth()->check()) {
        return redirect()->route('panel.dashboard');
    }

    return redirect('/'.(session('locale', 'en')));
});

// ─── Language Switcher ──────────────────────────────────────────────────────
Route::get('lang/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'es', 'fr'])) {
        session()->put('locale', $locale);

        if (auth()->guard('web')->check()) {
            auth()->guard('web')->user()->update(['language' => $locale]);
        }

        if (auth()->guard('admin')->check()) {
            auth()->guard('admin')->user()->update(['language' => $locale]);
        }
    }

    return redirect()->back();
})->name('lang.switch');

// ─── Public Guest Pages (locale-prefixed) ─────────────────────────────────────
Route::group(['prefix' => '{locale}', 'where' => ['locale' => 'en|es|fr']], function () {
    Route::get('/', fn () => view('pages.home'))->name('home');
});

// ─── Unified Panel Routes ────────────────────────────────────────────────────
Route::prefix('panel')->name('panel.')->middleware('panel.ip')->group(function () {
    // Panel root — redirect to dashboard or login
    Route::get('/', function () {
        if (auth()->guard('admin')->check() || auth()->guard('web')->check()) {
            return redirect()->route('panel.dashboard');
        }

        return redirect()->route('panel.login');
    });

    // Guest routes — admin login
    Route::middleware('admin.guest')->group(function () {
        Route::get('/login', [AdminAuthController::class, 'create'])->name('login');
        Route::post('/login', [AdminAuthController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.store');

        // Initial setup uses the same panel IP policy as login, but its own
        // throttle because it may create an administrator exactly once.
        Route::get('/setup', [InitialAdminSetupController::class, 'create'])->name('initial-admin.setup');
        Route::post('/setup', [InitialAdminSetupController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('initial-admin.store');
    });

    // Guest routes — tenant user auth
    Route::middleware('guest')->group(function () {
        Route::get('/login/tenant', [AuthenticatedSessionController::class, 'create'])->name('login.tenant');
        Route::post('/login/tenant', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.tenant.store');
        Route::get('/register', fn () => app()->call(Register::class))->name('register');
        Route::get('/forgot-password', fn () => app()->call(ForgotPassword::class))->name('password.request');
        Route::get('/reset-password/{token}', fn (string $token) => app()->call(ResetPassword::class, ['token' => $token]))->name('password.reset');
    });

    // Authenticated panel routes
    Route::middleware(['auth.panel', 'throttle:60,1'])->group(function () {
        Route::post('/logout', function () {
            if (auth()->guard('admin')->check()) {
                return app(AdminAuthController::class)->destroy(request());
            }

            return app(AuthenticatedSessionController::class)->destroy(request());
        })->name('logout');

        Route::get('/dashboard', fn () => app()->call(Dashboard::class))->name('dashboard');
        Route::get('/profile', ProfileEdit::class)->name('profile');
    });

    Route::post('/tenant/switch', TenantSwitchController::class)
        ->middleware(['auth:web', 'throttle:30,1'])
        ->name('tenant.switch');
});

// ─── Fast Testing Authentication Bridge ───────────────────────────────────────
// Only registered in the testing environment to provide rapid session authentication
// for browser tests, bypassing repeated UI login forms.
if (app()->environment('testing')) {
    Route::get('/_testing/login/{guard}/{id}', [App\Http\Controllers\Testing\TestAuthController::class, 'login'])
        ->middleware(['web']);
}

// ─── Fallback ────────────────────────────────────────────────────────────────
Route::fallback(function () {
    if (auth()->guard('admin')->check()) {
        return redirect()->route('panel.dashboard');
    }
    if (auth()->check()) {
        return redirect()->route('panel.dashboard');
    }

    return redirect('/');
});
