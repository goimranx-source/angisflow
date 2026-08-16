<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the authentication audit trail.
 *
 * ── Why this is a plain insert and not an Eloquent model ─────────────────────
 *
 * A model would give timestamps nobody wants, events nobody listens to, and an
 * object hydrated for a row that is never read back in the same request. This
 * table is written to on every sign-in attempt in the system — including the
 * failed ones, which is to say including whatever volume an attacker decides —
 * so it is a query-builder insert and nothing more.
 *
 * ── Why failures are swallowed ───────────────────────────────────────────────
 *
 * A full disk or a locked table must never be able to stop somebody signing in.
 * The audit trail is important; it is not more important than the product
 * working. A failure here is logged and the request continues.
 */
final class AuthEventRecorder
{
    public const LOGIN = 'login';

    public const LOGIN_FAILED = 'login.failed';

    public const LOGOUT = 'logout';

    public const REGISTERED = 'registered';

    public const PASSWORD_RESET = 'password.reset';

    public const PASSWORD_CHANGED = 'password.changed';

    public const TWO_FACTOR_PASSED = 'two_factor.passed';

    public const TWO_FACTOR_FAILED = 'two_factor.failed';

    public const TWO_FACTOR_ENABLED = 'two_factor.enabled';

    public const TWO_FACTOR_DISABLED = 'two_factor.disabled';

    public const RECOVERY_CODE_USED = 'two_factor.recovery_used';

    public const PASSKEY_REGISTERED = 'passkey.registered';

    public const PASSKEY_REMOVED = 'passkey.removed';

    public const LOCKED_OUT = 'login.locked_out';

    public static function record(
        string $event,
        ?User $user = null,
        ?string $email = null,
        ?string $method = null,
        array $context = [],
    ): void {
        try {
            $request = request();

            $agent = substr((string) $request->userAgent(), 0, 512);
            $now = now();

            DB::table('auth_events')->insert([
                'account_id' => $user?->account_id,
                'user_id' => $user?->getKey(),
                'event' => $event,
                'method' => $method,
                // Lowercased so a spray using mixed case still groups into one
                // row when somebody comes to count it.
                'email' => $email !== null ? mb_strtolower(trim($email)) : $user?->email,
                'ip_address' => self::clientIp($request),
                'user_agent_hash' => $agent === '' ? null : md5($agent),
                'user_agent' => $agent === '' ? null : $agent,
                'context' => $context === [] ? null : json_encode($context),
                'occurred_at' => $now->format('Y-m-d H:i:s.v'),
                'occurred_on' => $now->toDateString(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The client address.
     *
     * Laravel resolves this from the proxy headers it has been told to trust,
     * which is the only correct source: reading REMOTE_ADDR behind a load
     * balancer records the balancer on every row, and reading X-Forwarded-For
     * without trusting a proxy records whatever the client claimed.
     */
    private static function clientIp(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip !== null ? substr($ip, 0, 45) : null;
    }
}
