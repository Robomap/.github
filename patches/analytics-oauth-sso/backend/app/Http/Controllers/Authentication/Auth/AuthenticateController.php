<?php

namespace App\Http\Controllers\Authentication\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\User;
use App\Support\AccountRole;
use App\Support\AuthUserPayload;
use App\Support\FrontendUrl;
use App\Services\UserNotificationService;
use Laravel\Socialite\Facades\Socialite;

class AuthenticateController extends Controller
{
    private const OAUTH_TOKEN_CACHE_PREFIX = 'oauth_desktop_token:';

    private const OAUTH_TOKEN_TTL_MINUTES = 15;

    public function __construct()
    {
        $this->middleware('auth:web')->only(['logout', 'logoutAPI', 'me']);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'login_error_1'], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'login_error_2'], 404);
        }

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials))
        {
            if (Schema::hasColumn('rc_users', 'email_verified_at') && is_null($user->email_verified_at)) {
                Auth::logout();

                return response()->json(['message' => 'email_not_verified'], 403);
            }

            $request->session()->regenerate();

            $accessToken = $this->generateToken($user);

            return response()->json([
                'message' => 'Authenticated successfully',
                'user' => AuthUserPayload::from($user),
                'access_token' => $accessToken,
            ]);
        }

        return response()->json(['message' => 'login_error_3'], 401);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => AuthUserPayload::from(Auth::user()),
        ]);
    }

    public function oauthBootstrap(Request $request): JsonResponse
    {
        return $this->completeOAuthTokenExchange($request);
    }

    public function oauthEstablishSession(Request $request): JsonResponse
    {
        return $this->completeOAuthTokenExchange($request);
    }

    private function completeOAuthTokenExchange(Request $request): JsonResponse
    {
        $token = trim((string) $request->query('access_token', ''));

        if ($token === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = $this->resolveUserFromAccessToken($token);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            Auth::login($user, true);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        } catch (\Throwable $e) {
            Log::warning('OAuth session login fallback: ' . $e->getMessage());

            Auth::login($user, false);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        return response()->json([
            'message' => 'Session established',
            'user' => AuthUserPayload::from($user),
        ]);
    }

    public function generateToken($user): string
    {
        $apiToken = Str::random(60);
        $hashedToken = hash('sha256', $apiToken);

        if (Schema::hasColumn('rc_users', 'api_token')) {
            try {
                $user->api_token = $hashedToken;
                $user->save();
            } catch (\Throwable $e) {
                Log::error('Failed to persist api_token after Google OAuth: ' . $e->getMessage());
            }
        } else {
            Log::warning('api_token column missing; OAuth bearer auth unavailable.');
        }

        Cache::put(
            self::OAUTH_TOKEN_CACHE_PREFIX . $hashedToken,
            $user->id,
            now()->addMinutes(self::OAUTH_TOKEN_TTL_MINUTES)
        );

        $this->persistDesktopOAuthToken($hashedToken, $user->id);

        return $apiToken;
    }

    private function persistDesktopOAuthToken(string $hashedToken, int $userId): void
    {
        if (!Schema::hasTable('oauth_desktop_tokens')) {
            return;
        }

        try {
            DB::table('oauth_desktop_tokens')->updateOrInsert(
                ['token_hash' => $hashedToken],
                [
                    'user_id' => $userId,
                    'expires_at' => now()->addMinutes(self::OAUTH_TOKEN_TTL_MINUTES),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to persist desktop OAuth token: ' . $e->getMessage());
        }
    }

    private function googleRedirectUrl(): string
    {
        return (string) config('services.google.redirect');
    }

    private function isProviderConfigured(string $provider): bool
    {
        $filled = static fn (string $key): bool => trim((string) config($key)) !== '';

        if ($provider === 'apple') {
            // Apple's client_secret is a short-lived JWT generated at runtime from the
            // team_id, key_id and private_key, so APPLE_CLIENT_SECRET is normally empty.
            return $filled('services.apple.client_id')
                && $filled('services.apple.team_id')
                && $filled('services.apple.key_id')
                && $filled('services.apple.private_key');
        }

        return $filled("services.{$provider}.client_id")
            && $filled("services.{$provider}.client_secret");
    }

    private function buildOAuthState(string $returnUrl, bool $desktop, string $app): array
    {
        return [
            'returnUrl' => $this->sanitizeReturnUrl($returnUrl, $app),
            'desktop' => $desktop,
            'app' => $this->resolveOAuthApp($app),
        ];
    }

    public function showLoginForm()
    {
        $verificationSuccededUrl = env('APP_FRONTEND_URL');

        return redirect()->away($verificationSuccededUrl);
    }

    public function redirectToGoogle(Request $request)
    {
        $app = $this->resolveOAuthApp($request->query('app'));
        $returnUrl = $this->sanitizeReturnUrl($request->query('returnUrl'), $app);
        $desktop = $request->boolean('desktop');

        if (!$this->isProviderConfigured('google')) {
            Log::warning('Google OAuth attempted but credentials are not configured.');

            return $this->buildOAuthErrorRedirect('google_not_configured', $this->buildOAuthState($returnUrl, $desktop, $app));
        }

        return Socialite::driver('google')
            ->stateless()
            ->redirectUrl($this->googleRedirectUrl())
            ->scopes(['openid', 'profile', 'email'])
            ->with(['state' => $this->encodeOAuthState($returnUrl, $desktop, $app)])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        if ($request->filled('error')) {
            Log::warning('Google OAuth denied by provider: ' . $request->query('error'));

            return $this->buildOAuthErrorRedirect('google_auth_failed', $this->decodeOAuthState($request->input('state')));
        }

        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl($this->googleRedirectUrl())
                ->user();
            [$user, $isNewUser] = $this->findOrCreateGoogleUser($googleUser);

            try {
                Auth::login($user, true);

                if ($request->hasSession()) {
                    $request->session()->regenerate();
                }
            } catch (\Throwable $e) {
                Log::warning('Google OAuth session setup skipped: ' . $e->getMessage());
            }

            if ($this->shouldEnsureWelcomeNotification($user, $isNewUser)) {
                try {
                    app(UserNotificationService::class)->ensureWelcomeNotification($user, $this->shouldForceWelcomeEmailRetry($user, $isNewUser));
                } catch (\Throwable $e) {
                    Log::warning('Welcome notification skipped after Google OAuth: ' . $e->getMessage());
                }
            }

            $accessToken = $this->generateToken($user);
            $oauthState = $this->decodeOAuthState($request->input('state'));

            return $this->buildOAuthCallbackRedirect(
                'google',
                $oauthState['returnUrl'],
                $accessToken,
                $isNewUser,
                $oauthState['desktop'],
                $oauthState['app']
            );
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Google OAuth user persistence failed: ' . $e->getMessage());

            return $this->buildOAuthErrorRedirect('google_auth_failed', $this->decodeOAuthState($request->input('state')));
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback failed: ' . $e->getMessage());

            return $this->buildOAuthErrorRedirect('google_auth_failed', $this->decodeOAuthState($request->input('state')));
        }
    }

    private function appleRedirectUrl(): string
    {
        return (string) config('services.apple.redirect');
    }

    private function appleAuthorizationUrl(string $state): string
    {
        return 'https://appleid.apple.com/auth/authorize?' . http_build_query([
            'client_id' => (string) config('services.apple.client_id'),
            'redirect_uri' => $this->appleRedirectUrl(),
            'scope' => 'name email',
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function redirectToApple(Request $request)
    {
        $app = $this->resolveOAuthApp($request->query('app'));
        $returnUrl = $this->sanitizeReturnUrl($request->query('returnUrl'), $app);
        $desktop = $request->boolean('desktop');

        if (!$this->isProviderConfigured('apple')) {
            Log::warning('Apple OAuth attempted but credentials are not configured.');

            return $this->buildOAuthErrorRedirect('apple_not_configured', $this->buildOAuthState($returnUrl, $desktop, $app));
        }

        // Building the authorization URL does not require the Apple private key.
        // Keeping that operation out of the Socialite provider prevents malformed or
        // stale signing credentials from turning the initial login click into a 500.
        return redirect()->away(
            $this->appleAuthorizationUrl($this->encodeOAuthState($returnUrl, $desktop, $app))
        );
    }

    public function handleAppleCallback(Request $request)
    {
        if ($request->filled('error')) {
            Log::warning('Apple OAuth denied by provider: ' . $request->input('error'));

            return $this->buildOAuthErrorRedirect('apple_auth_failed', $this->decodeOAuthState($request->input('state')));
        }

        try {
            $appleUser = Socialite::driver('apple')
                ->stateless()
                ->redirectUrl($this->appleRedirectUrl())
                ->user();
            [$user, $isNewUser] = $this->findOrCreateAppleUser($appleUser);

            try {
                Auth::login($user, true);

                if ($request->hasSession()) {
                    $request->session()->regenerate();
                }
            } catch (\Throwable $e) {
                Log::warning('Apple OAuth session setup skipped: ' . $e->getMessage());
            }

            if ($this->shouldEnsureWelcomeNotification($user, $isNewUser)) {
                try {
                    app(UserNotificationService::class)->ensureWelcomeNotification($user, $this->shouldForceWelcomeEmailRetry($user, $isNewUser));
                } catch (\Throwable $e) {
                    Log::warning('Welcome notification skipped after Apple OAuth: ' . $e->getMessage());
                }
            }

            $accessToken = $this->generateToken($user);
            $oauthState = $this->decodeOAuthState($request->input('state'));

            return $this->buildOAuthCallbackRedirect(
                'apple',
                $oauthState['returnUrl'],
                $accessToken,
                $isNewUser,
                $oauthState['desktop'],
                $oauthState['app']
            );
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Apple OAuth user persistence failed: ' . $e->getMessage());

            return $this->buildOAuthErrorRedirect('apple_auth_failed', $this->decodeOAuthState($request->input('state')));
        } catch (\Throwable $e) {
            Log::error('Apple OAuth callback failed: ' . $e->getMessage());

            return $this->buildOAuthErrorRedirect('apple_auth_failed', $this->decodeOAuthState($request->input('state')));
        }
    }

    private function findOrCreateAppleUser($appleUser): array
    {
        $existingUser = $this->findAppleUser($appleUser);

        if ($existingUser) {
            $this->linkAppleAccount($existingUser, $appleUser);

            return [$existingUser->fresh(), false];
        }

        return [$this->createAppleUser($appleUser), true];
    }

    private function findAppleUser($appleUser): ?User
    {
        $appleId = (string) $appleUser->getId();
        $email = $appleUser->getEmail();

        if ($appleId === '') {
            throw new \RuntimeException('Apple account did not return a user identifier.');
        }

        return User::query()
            ->when(
                Schema::hasColumn('rc_users', 'apple_id'),
                function ($query) use ($appleId, $email) {
                    $query->where(function ($inner) use ($appleId, $email) {
                        $inner->where('apple_id', $appleId);

                        if ($email) {
                            $inner->orWhere('email', $email);
                        }
                    });
                },
                function ($query) use ($email) {
                    if ($email) {
                        $query->where('email', $email);
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                }
            )
            ->first();
    }

    private function linkAppleAccount(User $user, $appleUser): void
    {
        $dirty = false;
        $appleId = (string) $appleUser->getId();

        if (Schema::hasColumn('rc_users', 'apple_id') && !$user->apple_id && $appleId !== '') {
            $hasConflict = User::where('apple_id', $appleId)
                ->where('id', '!=', $user->id)
                ->exists();

            if (!$hasConflict) {
                $user->apple_id = $appleId;
                $dirty = true;
            }
        }

        if (Schema::hasColumn('rc_users', 'email_verified_at') && !$user->email_verified_at) {
            $user->email_verified_at = now();
            $dirty = true;
        }

        if ($dirty) {
            try {
                $user->save();
            } catch (\Illuminate\Database\QueryException $e) {
                Log::warning('Apple account link skipped: ' . $e->getMessage());
            }
        }
    }

    private function createAppleUser($appleUser): User
    {
        $appleId = (string) $appleUser->getId();
        $email = $appleUser->getEmail();

        if (!$email) {
            $email = 'apple_' . $appleId . '@users.robomap.local';
        }

        $fullName = trim((string) ($appleUser->getName() ?? 'Apple User'));
        if ($fullName === '') {
            $fullName = 'Apple User';
        }
        // rc_users.full_name is varchar(45) with STRICT_TRANS_TABLES.
        $fullName = mb_substr($fullName, 0, 45);
        $nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
        $firstName = $nameParts[0] ?? $fullName;
        $lastName = $nameParts[1] ?? '';

        $userAttributes = [
            'username' => $this->generateUniqueUsername($email, 'apple_user'),
            'full_name' => $fullName,
            'country' => 'Unknown',
            'phone_extension' => 0,
            'phone' => 0,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'role' => AccountRole::USER,
            'active_organization' => '0',
            'email_verified_at' => now(),
        ];

        if (Schema::hasColumn('rc_users', 'apple_id')) {
            $userAttributes['apple_id'] = $appleId;
        }

        if (Schema::hasColumn('rc_users', 'name')) {
            $userAttributes['name'] = $firstName;
            $userAttributes['middle_name'] = '';
            $userAttributes['last_name'] = $lastName;
            $userAttributes['address'] = '';
            $userAttributes['city'] = '';
        }

        if (Schema::hasColumn('rc_users', 'account_type')) {
            $userAttributes['account_type'] = 'user';
        }

        return User::create($userAttributes);
    }

    public function logout(Request $request)
    {
         try{
             $user = Auth::user();

             if ($user instanceof User && Schema::hasColumn('rc_users', 'api_token')) {
                 $user->api_token = null;
                 $user->save();
             }

             Auth::logout();

             $request->session()->invalidate();
             $request->session()->regenerateToken();

             return response()->json(['message' => 'Logout of the application successfully.'], 201);

         } catch (\Exception $e) {
             return new JsonResponse(['error' => $e->getMessage()], 400);
         }
    }

    public function logoutAPI(Request $request, TokenRepository $tokenRepository)
    {
        $accessToken = Auth::user()->token();

        $tokenRepository->revokeAccessToken($accessToken);

        return response()->json(['message' => 'Successfully logged out']);
    }

    private function findOrCreateGoogleUser($googleUser): array
    {
        $existingUser = $this->findGoogleUser($googleUser);

        if ($existingUser) {
            $this->linkGoogleAccount($existingUser, $googleUser);

            return [$existingUser->fresh(), false];
        }

        return [$this->createGoogleUser($googleUser), true];
    }

    private function shouldEnsureWelcomeNotification(User $user, bool $isNewUser): bool
    {
        // OAuth welcome mail is transactional; always attempt it on callback.
        return true;
    }

    private function shouldForceWelcomeEmailRetry(User $user, bool $isNewUser): bool
    {
        // Only (re)send the welcome email until it has been successfully handled
        // once. Forcing it on every callback re-triggers the mail transport on
        // each login, which needlessly repeats a network send on the login path.
        return false;
    }

    private function findGoogleUser($googleUser): ?User
    {
        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();

        if (!$email) {
            throw new \RuntimeException('Google account did not return an email address.');
        }

        return User::query()
            ->when(
                Schema::hasColumn('rc_users', 'google_id'),
                function ($query) use ($googleId, $email) {
                    $query->where(function ($inner) use ($googleId, $email) {
                        $inner->where('google_id', $googleId)
                            ->orWhere('email', $email);
                    });
                },
                function ($query) use ($email) {
                    $query->where('email', $email);
                }
            )
            ->first();
    }

    private function linkGoogleAccount(User $user, $googleUser): void
    {
        $dirty = false;

        if (Schema::hasColumn('rc_users', 'google_id') && !$user->google_id) {
            $googleId = (string) $googleUser->getId();
            $hasConflict = User::where('google_id', $googleId)
                ->where('id', '!=', $user->id)
                ->exists();

            if (!$hasConflict) {
                $user->google_id = $googleId;
                $dirty = true;
            }
        }

        if (Schema::hasColumn('rc_users', 'email_verified_at') && !$user->email_verified_at) {
            $user->email_verified_at = now();
            $dirty = true;
        }

        if ($dirty) {
            try {
                $user->save();
            } catch (\Illuminate\Database\QueryException $e) {
                Log::warning('Google account link skipped: ' . $e->getMessage());
            }
        }
    }

    private function createGoogleUser($googleUser): User
    {
        $email = $googleUser->getEmail();
        $fullName = trim((string) ($googleUser->getName() ?? 'Google User'));
        if ($fullName === '') {
            $fullName = 'Google User';
        }
        $fullName = mb_substr($fullName, 0, 45);
        $nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
        $firstName = $nameParts[0] ?? $fullName;
        $lastName = $googleUser->user['family_name'] ?? ($nameParts[1] ?? '');

        $userAttributes = [
            'username' => $this->generateUniqueUsername($email, 'google_user'),
            'full_name' => $fullName,
            'country' => 'Unknown',
            'phone_extension' => 0,
            'phone' => 0,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'role' => AccountRole::USER,
            'active_organization' => '0',
            'email_verified_at' => now(),
        ];

        if (Schema::hasColumn('rc_users', 'google_id')) {
            $userAttributes['google_id'] = $googleUser->getId();
        }

        if (Schema::hasColumn('rc_users', 'name')) {
            $userAttributes['name'] = $firstName;
            $userAttributes['middle_name'] = '';
            $userAttributes['last_name'] = $lastName;
            $userAttributes['address'] = '';
            $userAttributes['city'] = '';
        }

        if (Schema::hasColumn('rc_users', 'account_type')) {
            $userAttributes['account_type'] = 'user';
        }

        return User::create($userAttributes);
    }

    private function resolveUserFromAccessToken(string $token): ?User
    {
        $hashedToken = hash('sha256', $token);

        if (Schema::hasColumn('rc_users', 'api_token')) {
            $user = User::where('api_token', $hashedToken)->first();

            if ($user) {
                return $user;
            }
        }

        $userId = Cache::get(self::OAUTH_TOKEN_CACHE_PREFIX . $hashedToken);

        if (!$userId && Schema::hasTable('oauth_desktop_tokens')) {
            $row = DB::table('oauth_desktop_tokens')
                ->where('token_hash', $hashedToken)
                ->where('expires_at', '>', now())
                ->first();

            if ($row) {
                $userId = $row->user_id;
            }
        }

        if (!$userId) {
            return null;
        }

        return User::find($userId);
    }

    private function sanitizeReturnUrl(?string $returnUrl, string $app = 'main'): string
    {
        $value = trim((string) $returnUrl);

        if ($value === '' || !str_starts_with($value, '/') || str_starts_with($value, '//') || str_starts_with($value, '/auth/')) {
            return FrontendUrl::defaultReturnPath($app);
        }

        return $value;
    }

    private function resolveOAuthApp(?string $app): string
    {
        $normalized = match ($app) {
            'hosting' => 'ws',
            default => $app,
        };

        return in_array($normalized, ['main', 'chat', 'business', 'ws', 'marketing', 'phone', 'analytics'], true) ? $normalized : 'main';
    }

    private function encodeOAuthState(string $returnUrl, bool $desktop = false, string $app = 'main'): string
    {
        $payload = json_encode([
            'returnUrl' => $this->sanitizeReturnUrl($returnUrl, $app),
            'desktop' => $desktop,
            'app' => $this->resolveOAuthApp($app),
        ]);

        return rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    }

    private function decodeOAuthState(?string $state): array
    {
        if (!$state) {
            return ['returnUrl' => FrontendUrl::defaultReturnPath('main'), 'desktop' => false, 'app' => 'main'];
        }

        $normalized = strtr($state, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = json_decode((string) base64_decode($normalized), true);

        if (!is_array($decoded)) {
            return ['returnUrl' => FrontendUrl::defaultReturnPath('main'), 'desktop' => false, 'app' => 'main'];
        }

        $app = $this->resolveOAuthApp($decoded['app'] ?? null);

        return [
            'returnUrl' => $this->sanitizeReturnUrl($decoded['returnUrl'] ?? null, $app),
            'desktop' => (bool) ($decoded['desktop'] ?? false),
            'app' => $app,
        ];
    }

    private function isDesktopOAuthState(?string $state): bool
    {
        return $this->decodeOAuthState($state)['desktop'];
    }

    private function buildOAuthCallbackRedirect(
        string $provider,
        string $returnUrl,
        string $accessToken,
        bool $isNewUser,
        bool $desktop,
        string $app = 'main'
    ) {
        $frontendUrl = FrontendUrl::resolve($app);
        $params = [
            'provider' => $provider,
            'returnUrl' => $returnUrl,
            'access_token' => $accessToken,
        ];

        if ($isNewUser) {
            $params['registered'] = '1';
        }

        $query = http_build_query($params);

        if ($desktop) {
            return redirect()->away('robomap://auth/oauth-callback?' . $query);
        }

        return redirect()->away($frontendUrl . '/auth/oauth-callback?' . $query);
    }

    private function buildOAuthErrorRedirect(string $error, array $oauthState)
    {
        $frontendUrl = FrontendUrl::resolve($oauthState['app'] ?? 'main');

        if ($oauthState['desktop'] ?? false) {
            return redirect()->away('robomap://auth/login?error=' . urlencode($error));
        }

        return redirect()->away($frontendUrl . '/auth/login?error=' . urlencode($error));
    }

    private function generateUniqueUsername(string $email, string $fallback = 'user'): string
    {
        $base = Str::slug(Str::before($email, '@'), '_');

        if ($base === '') {
            $base = $fallback;
        }

        $username = $base;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }

}
