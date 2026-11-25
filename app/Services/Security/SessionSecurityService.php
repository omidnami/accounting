<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revokes authenticated sessions after credential or access changes.
 *
 * Rotating the remember token stops "remember me" cookies, while deleting the
 * server-side session rows immediately ends already-authenticated browsers.
 */
class SessionSecurityService
{
    public function revokeOtherSessions(User $user, ?string $keepSessionId = null): void
    {
        $this->revoke($user, $keepSessionId);
    }

    public function revokeAllSessions(User $user): void
    {
        $this->revoke($user, null);
    }

    private function revoke(User $user, ?string $keepSessionId): void
    {
        DB::transaction(function () use ($user, $keepSessionId): void {
            $user->forceFill(['remember_token' => Str::random(60)])->save();

            if (config('session.driver') !== 'database') {
                return;
            }

            $connection = config('session.connection') ?: config('database.default');

            DB::connection($connection)
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when(
                    $keepSessionId !== null && $keepSessionId !== '',
                    fn ($query) => $query->where('id', '!=', $keepSessionId),
                )
                ->delete();
        });
    }
}
