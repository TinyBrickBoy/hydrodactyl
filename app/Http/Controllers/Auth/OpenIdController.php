<?php

namespace Pterodactyl\Http\Controllers\Auth;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Illuminate\Support\Carbon;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\RedirectResponse;
use Pterodactyl\Events\Auth\DirectLogin;
use Pterodactyl\Services\Auth\OpenIdService;

class OpenIdController extends AbstractLoginController
{
    public function __construct(private OpenIdService $openid)
    {
        parent::__construct();
    }

    /**
     * Begin the OpenID Connect login (or re-authentication) flow.
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (!$this->openid->enabled()) {
            abort(404);
        }

        $state = Str::random(40);
        $nonce = Str::random(40);

        $request->session()->put('openid_state', $state);
        $request->session()->put('openid_nonce', $nonce);

        $confirm = $request->boolean('confirm') && Auth::check() && $request->session()->get('auth_via_sso');
        if ($confirm) {
            $request->session()->put('openid_confirm', true);
            $request->session()->put('openid_return', $this->safeReturnUrl($request->query('return')));
        } else {
            $request->session()->forget('openid_confirm');
            $request->session()->forget('openid_return');
        }

        return redirect()->away($this->openid->authorizationUrl($state, $nonce, $confirm));
    }

    /**
     * Handle the redirect back from the identity provider.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (!$this->openid->enabled()) {
            abort(404);
        }

        if ($request->has('error')) {
            return $this->failRedirect($request, $request->query('error_description') ?: 'The SSO provider denied the login request.');
        }

        $state = $request->session()->pull('openid_state');
        if (empty($state) || !hash_equals($state, (string) $request->query('state'))) {
            return $this->failRedirect($request, 'The SSO login could not be verified. Please try again.');
        }

        $nonce = $request->session()->pull('openid_nonce');

        try {
            $tokens = $this->openid->exchangeCode((string) $request->query('code'));
            $claims = $this->openid->validateIdToken($tokens['id_token'] ?? '', (string) $nonce);
        } catch (\Throwable $e) {
            report($e);

            return $this->failRedirect($request, 'We were unable to verify your identity with the SSO provider.');
        }

        if (empty($claims['email']) && !empty($tokens['access_token'])) {
            $claims = array_merge($this->openid->userInfo($tokens['access_token']), $claims);
        }

        $subject = $claims['sub'] ?? null;
        if (empty($subject)) {
            return $this->failRedirect($request, 'The SSO provider did not return a valid identity.');
        }

        if ($request->session()->pull('openid_confirm')) {
            return $this->handleConfirmation($request, (string) $subject);
        }

        return $this->handleLogin($request, (string) $subject, $claims);
    }

    protected function handleLogin(Request $request, string $subject, array $claims): RedirectResponse
    {
        $user = User::query()->where('external_id', $subject)->first();

        $emailVerified = $this->emailIsVerified($claims);

        if ($user === null && !empty($claims['email']) && $emailVerified) {
            $user = User::query()->where('email', $claims['email'])->first();
            if ($user !== null) {
                $user->forceFill(['external_id' => $subject])->saveOrFail();
            }
        }

        if ($user === null) {
            if (!config('openid.auto_create')) {
                return $this->failRedirect($request, 'No matching account exists for your SSO identity. Please contact an administrator.');
            }

            if (empty($claims['email']) || !$emailVerified) {
                return $this->failRedirect($request, 'The SSO provider did not return a verified email address, which is required to create an account.');
            }

            $user = $this->createUser($subject, $claims);
        }

        $this->completeLogin($user, $request);

        Activity::event('auth:success')->withRequestMetadata()->subject($user)->property('via', 'sso')->log();

        return redirect()->intended('/');
    }

    protected function handleConfirmation(Request $request, string $subject): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || $user->external_id !== $subject) {
            return $this->failRedirect($request, 'The SSO account did not match your signed-in user.');
        }

        $request->session()->put('sso_confirmed_at', Carbon::now()->getTimestamp());

        return redirect()->to($request->session()->pull('openid_return', '/'));
    }

    protected function createUser(string $subject, array $claims): User
    {
        $user = new User();
        $user->forceFill([
            'uuid' => Uuid::uuid4()->toString(),
            'external_id' => $subject,
            'email' => $claims['email'],
            'username' => $this->uniqueUsername($claims),
            'name_first' => $claims['given_name'] ?? Str::before($claims['name'] ?? 'SSO', ' '),
            'name_last' => $claims['family_name'] ?? Str::after($claims['name'] ?? 'User', ' '),
            'password' => Hash::make(Str::random(64)),
            'root_admin' => (bool) config('openid.create_as_admin'),
            'language' => 'en',
        ])->saveOrFail();

        return $user;
    }

    protected function emailIsVerified(array $claims): bool
    {
        $value = $claims['email_verified'] ?? null;

        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    protected function uniqueUsername(array $claims): string
    {
        $base = $claims['preferred_username'] ?? Str::before($claims['email'], '@');
        $base = Str::lower(preg_replace('/[^a-z0-9_.-]/i', '', (string) $base));
        $base = trim($base, '._-') ?: 'user';
        $base = Str::limit($base, 180, '');

        $candidate = $base;
        $suffix = 1;
        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = $base . $suffix++;
        }

        return $candidate;
    }

    protected function completeLogin(User $user, Request $request): void
    {
        $request->session()->regenerate();
        $this->auth->guard()->login($user, true);
        $request->session()->put('auth_via_sso', true);
        $request->session()->put('sso_confirmed_at', Carbon::now()->getTimestamp());

        Event::dispatch(new DirectLogin($user, true));
    }

    protected function failRedirect(Request $request, string $message): RedirectResponse
    {
        $request->session()->forget(['openid_state', 'openid_nonce', 'openid_confirm', 'openid_return']);

        return redirect()->to('/auth/login?' . http_build_query(['sso_error' => $message]));
    }

    protected function safeReturnUrl(?string $url): string
    {
        if (empty($url)) {
            return '/';
        }

        return Str::startsWith($url, '/') && !Str::startsWith($url, '//') ? $url : '/';
    }
}
