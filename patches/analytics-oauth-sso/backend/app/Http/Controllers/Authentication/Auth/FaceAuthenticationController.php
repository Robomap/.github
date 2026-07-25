<?php

namespace App\Http\Controllers\Authentication\Auth;

use App\Exceptions\AzureFaceException;
use App\Models\FaceEnrollment;
use App\Models\FaceLoginChallenge;
use App\Models\User;
use App\Services\Azure\AzureFaceService;
use App\Support\AuthUserPayload;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FaceAuthenticationController extends Controller
{
    public function __construct(private readonly AzureFaceService $faceService)
    {
    }

    public function availability(): JsonResponse
    {
        return response()->json([
            'available' => $this->isAvailable(),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        if (!$this->isAvailable()) {
            return response()->json(['message' => 'face_service_unavailable'], 503);
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'device_id' => 'required|uuid',
            'app' => 'nullable|in:main,chat,business,hosting,ws,marketing,phone,analytics',
            'return_url' => 'nullable|string|max:2048',
            'remember' => 'nullable|boolean',
        ]);
        $user = User::where('email', $validated['email'])->first();
        $enrollment = $user
            ? FaceEnrollment::where('user_id', $user->id)->first()
            : null;

        if (!$user || !$enrollment) {
            return response()->json(['message' => 'face_not_enrolled'], 404);
        }

        $this->cleanupExpiredChallenges($user->id);
        $referenceImage = base64_decode((string) $enrollment->reference_image, true);

        if ($referenceImage === false) {
            Log::error('Stored Face ID reference image could not be decoded.', ['user_id' => $user->id]);

            return response()->json(['message' => 'face_service_unavailable'], 503);
        }

        $challengeId = (string) Str::uuid();
        $sessionId = null;

        try {
            $session = $this->faceService->createLivenessWithVerify(
                $referenceImage,
                $enrollment->mime_type,
                $validated['device_id']
            );
            $sessionId = (string) ($session['sessionId'] ?? '');
            $authToken = (string) ($session['authToken'] ?? '');

            if ($sessionId === '' || $authToken === '') {
                throw new AzureFaceException('Azure Face returned an incomplete session.', 'InvalidSessionResponse');
            }

            $app = (string) ($validated['app'] ?? 'main');
            $callbackUrl = $this->callbackUrl(
                $app,
                $challengeId,
                $this->sanitizeReturnUrl($validated['return_url'] ?? null, $app)
            );
            $quickLink = $this->faceService->createQuickLink($authToken, $callbackUrl);

            FaceLoginChallenge::create([
                'id' => $challengeId,
                'user_id' => $user->id,
                'azure_session_id' => $sessionId,
                'status' => 'pending',
                'remember' => (bool) ($validated['remember'] ?? true),
                'expires_at' => now()->addMinutes(10),
            ]);

            return response()->json([
                'challenge' => $challengeId,
                'url' => $quickLink,
            ]);
        } catch (AzureFaceException $exception) {
            if ($sessionId !== null && $sessionId !== '') {
                $this->deleteAzureSession($sessionId);
            }

            return $this->azureErrorResponse($exception);
        } catch (\Throwable $exception) {
            if ($sessionId !== null && $sessionId !== '') {
                $this->deleteAzureSession($sessionId);
            }

            throw $exception;
        }
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => 'required|uuid',
        ]);
        $challenge = FaceLoginChallenge::with('user')->find($validated['challenge']);

        if (!$challenge || $challenge->status !== 'pending' || $challenge->expires_at->isPast()) {
            return response()->json(['message' => 'face_challenge_expired'], 410);
        }

        try {
            $session = $this->faceService->getLivenessWithVerifySession($challenge->azure_session_id);
        } catch (AzureFaceException $exception) {
            return $this->azureErrorResponse($exception);
        }

        $attempt = $this->latestSuccessfulAttempt($session);

        if ($attempt === null) {
            if ($this->hasFailedAttempt($session)) {
                return $this->failChallenge($challenge, 'face_verification_failed');
            }

            return response()->json(['status' => 'pending'], 202);
        }

        $result = is_array($attempt['result'] ?? null) ? $attempt['result'] : [];
        $verifyResult = is_array($result['verifyResult'] ?? null) ? $result['verifyResult'] : [];
        $isLive = strtolower((string) ($result['livenessDecision'] ?? '')) === 'realface';
        $isIdentical = ($verifyResult['isIdentical'] ?? false) === true;
        $confidence = (float) ($verifyResult['matchConfidence'] ?? 0);
        $threshold = (float) config('services.azure.face.verify_threshold', 0.7);

        if (!$isLive || !$isIdentical || $confidence < $threshold) {
            return $this->failChallenge($challenge, 'face_verification_failed');
        }

        if (
            !$challenge->user
            || (Schema::hasColumn('rc_users', 'email_verified_at') && $challenge->user->email_verified_at === null)
        ) {
            return $this->failChallenge($challenge, 'email_not_verified');
        }

        $claimed = FaceLoginChallenge::where('id', $challenge->id)
            ->where('status', 'pending')
            ->update(['status' => 'completed']);

        if ($claimed !== 1) {
            return response()->json(['message' => 'face_challenge_expired'], 410);
        }

        Auth::login($challenge->user, $challenge->remember);
        $request->session()->regenerate();
        $this->deleteAzureSession($challenge->azure_session_id);
        $challenge->delete();

        return response()->json([
            'message' => 'Authenticated successfully',
            'user' => AuthUserPayload::from($challenge->user),
            'access_token' => null,
        ]);
    }

    private function isAvailable(): bool
    {
        return $this->faceService->isConfigured()
            && Schema::hasTable('rc_face_enrollments')
            && Schema::hasTable('rc_face_login_challenges');
    }

    private function callbackUrl(string $app, string $challengeId, string $returnUrl): string
    {
        return FrontendUrl::resolve($app) . '/auth/login?' . http_build_query([
            'faceChallenge' => $challengeId,
            'returnUrl' => $returnUrl,
        ]);
    }

    private function sanitizeReturnUrl(?string $returnUrl, string $app): string
    {
        $value = trim((string) $returnUrl);

        if ($value === '' || !str_starts_with($value, '/') || str_starts_with($value, '//') || str_starts_with($value, '/auth/')) {
            return FrontendUrl::defaultReturnPath($app);
        }

        return $value;
    }

    private function latestSuccessfulAttempt(array $session): ?array
    {
        $attempts = is_array($session['results']['attempts'] ?? null)
            ? $session['results']['attempts']
            : [];
        $successfulAttempt = null;
        $latestAttemptId = -1;

        foreach ($attempts as $attempt) {
            if (!is_array($attempt) || ($attempt['attemptStatus'] ?? '') !== 'Succeeded') {
                continue;
            }

            $attemptId = (int) ($attempt['attemptId'] ?? 0);

            if ($attemptId > $latestAttemptId) {
                $successfulAttempt = $attempt;
                $latestAttemptId = $attemptId;
            }
        }

        return $successfulAttempt;
    }

    private function hasFailedAttempt(array $session): bool
    {
        $attempts = is_array($session['results']['attempts'] ?? null)
            ? $session['results']['attempts']
            : [];

        foreach ($attempts as $attempt) {
            if (is_array($attempt) && ($attempt['attemptStatus'] ?? '') === 'Failed') {
                return true;
            }
        }

        return false;
    }

    private function failChallenge(FaceLoginChallenge $challenge, string $message): JsonResponse
    {
        $this->deleteAzureSession($challenge->azure_session_id);
        $challenge->delete();

        return response()->json(['message' => $message], 401);
    }

    private function deleteAzureSession(string $sessionId): void
    {
        try {
            $this->faceService->deleteLivenessWithVerifySession($sessionId);
        } catch (AzureFaceException $exception) {
            Log::warning('Unable to delete Azure Face login session.', [
                'code' => $exception->serviceCode,
            ]);
        }
    }

    private function azureErrorResponse(AzureFaceException $exception): JsonResponse
    {
        Log::warning('Azure Face authentication request failed.', [
            'code' => $exception->serviceCode,
            'status' => $exception->statusCode,
        ]);

        if ($exception->serviceCode === 'UnsupportedFeature') {
            return response()->json(['message' => 'face_service_pending_approval'], 503);
        }

        return response()->json(['message' => 'face_service_unavailable'], 503);
    }

    private function cleanupExpiredChallenges(int $userId): void
    {
        $challenges = FaceLoginChallenge::where('user_id', $userId)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($challenges as $challenge) {
            $this->deleteAzureSession($challenge->azure_session_id);
            $challenge->delete();
        }
    }
}
