<?php

namespace App\Http\Controllers\Api\Application\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\AccountUnsuspended;
use App\Transformers\Api\Application\UserTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class UserSuspensionController extends Controller
{
    /**
     * Suspend a user account.
     */
    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string',
            'suspend_until' => 'nullable|date|after:now',
        ]);

        $user->update([
            'suspended' => true,
            'suspended_at' => now(),
            'suspension_reason' => $data['reason'],
            'suspend_until' => $data['suspend_until'] ?? null,
        ]);

        // Suspend all user's servers
        $user->servers()->update(['status' => 'suspended']);

        // Send notification
        $user->notify(new AccountSuspended($data['reason'], $data['suspend_until'] ?? null));

        return new JsonResponse([
            'object' => 'user',
            'attributes' => (new UserTransformer())->transform($user),
        ]);
    }

    /**
     * Unsuspend a user account.
     */
    public function unsuspend(User $user): JsonResponse
    {
        $user->update([
            'suspended' => false,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspend_until' => null,
        ]);

        // Unsuspend all user's servers that were suspended
        $user->servers()->where('status', 'suspended')->update(['status' => null]);

        // Send notification
        $user->notify(new AccountUnsuspended());

        return new JsonResponse([
            'object' => 'user',
            'attributes' => (new UserTransformer())->transform($user),
        ]);
    }
}
