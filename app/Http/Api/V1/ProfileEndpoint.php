<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
use App\Http\Api\Endpoint;
use App\Support\BootPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileEndpoint extends Endpoint
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'has_password' => $user->hasPassword(),
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email:filter', 'max:255',
                // Unique within the account, not globally — the same person can
                // legitimately hold a login at two subscribers.
                Rule::unique('users', 'email')
                    ->where('account_id', $user->account_id)
                    ->ignore($user->getKey()),
            ],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $emailChanged = mb_strtolower(trim($validated['email'])) !== $user->email;

        $user->fill([
            'name' => $validated['name'],
            'email' => mb_strtolower(trim($validated['email'])),
            'timezone' => $validated['timezone'] ?: null,
        ]);

        if ($emailChanged) {
            // The old address was verified; the new one is not. Carrying the
            // verification across would let anyone point a verified account at
            // an address they do not control.
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        // The shell shows the name and the verification chip, so it comes back
        // with the save. One request, not two.
        return response()->json([
            'message' => 'Profile updated.',
            'boot' => BootPayload::build(),
        ]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'avatar' => ['required', 'image', 'max:2048'], // 2MB max
        ]);

        try {
            // Store the avatar in the public disk under avatars directory
            $path = $request->file('avatar')->store('avatars', 'public');
            
            // Delete old avatar if it exists
            if ($user->avatar_path && \Storage::disk('public')->exists($user->avatar_path)) {
                \Storage::disk('public')->delete($user->avatar_path);
            }

            $user->update(['avatar_path' => $path]);

            return response()->json([
                'message' => 'Profile picture updated.',
                'boot' => BootPayload::build(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Avatar upload failed.',
                'errors' => ['avatar' => ['Could not upload the image.']],
            ], 422);
        }
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Delete the avatar file if it exists
        if ($user->avatar_path && \Storage::disk('public')->exists($user->avatar_path)) {
            \Storage::disk('public')->delete($user->avatar_path);
        }

        $user->update(['avatar_path' => null]);

        return response()->json([
            'message' => 'Profile picture removed.',
            'boot' => BootPayload::build(),
        ]);
    }

    /** The timezone list, for the picker. Static for the life of a deploy. */
    public function timezones(): JsonResponse
    {
        return response()->json(['data' => \DateTimeZone::listIdentifiers()])
            ->header('Cache-Control', 'public, max-age=86400, immutable');
    }

    /**
     * Delete the account (soft delete with 60-day retention).
     *
     * This marks the account as deleted, cancels any active subscriptions, and
     * schedules permanent deletion after 60 days. The account can be restored
     * by support during this period.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Only the account owner can delete the account
        if (!$user->ownsAccount()) {
            return response()->json([
                'message' => 'Only the account owner can delete the account.',
            ], 403);
        }

        // Laravel may not parse DELETE request body automatically
        // Get data from JSON payload explicitly
        $data = $request->json()->all();
        
        $validated = validator($data, [
            'confirmation' => ['required', 'string', 'in:DELETE'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $account = $user->account;

        if (!$account) {
            return response()->json([
                'message' => 'Account not found.',
            ], 404);
        }

        try {
            \DB::transaction(function () use ($account, $user, $validated) {
                // Cancel active subscription if exists
                $subscription = $account->subscription;
                if ($subscription && in_array($subscription->status, ['active', 'trialing'], true)) {
                    // Here you would integrate with your payment provider to cancel
                    // For now, we'll mark it as cancelled
                    $subscription->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'ended_at' => now(), // Use ended_at, not ends_at
                    ]);
                }

                // Mark account as deleted with tracking
                $account->update([
                    'status' => \App\Domain\Tenancy\Models\Account::STATUS_CANCELLED,
                    'deleted_by_user_id' => $user->id,
                    'deletion_reason' => 'user_requested',
                    'deletion_notes' => $validated['reason'] ?? 'No reason provided',
                    'permanent_deletion_at' => now()->addDays(60),
                ]);

                // Soft delete the account
                $account->delete();
            });

            // Log out the user
            $request->user()->tokens()->delete();

            return response()->json([
                'message' => 'Your account has been deleted. It will be permanently removed after 60 days. Contact support if you need to restore it.',
            ]);
        } catch (\Throwable $e) {
            \Log::error('Account deletion failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to delete account. Please try again or contact support.',
            ], 500);
        }
    }
}
