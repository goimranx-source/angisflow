<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time codes, and the recovery codes behind them.
 *
 * ── The window, and why it is one step and not four ──────────────────────────
 *
 * A TOTP code is valid for thirty seconds. Accepting the previous and next step
 * as well — a window of one — covers a phone whose clock has drifted by up to
 * half a minute, which is common enough to matter. Widening it further is a
 * tempting way to make support tickets go away and it multiplies the number of
 * codes an attacker's guess could match; the answer to a badly drifted clock is
 * to fix the clock.
 *
 * ── Recovery codes are hashed ────────────────────────────────────────────────
 *
 * They are passwords: eight strings that each bypass the second factor
 * completely. Storing them in plain text — which is what most implementations
 * do, including well-known ones — means a database read hands over the second
 * factor for every user who has ever enabled it. They are shown once, at the
 * moment they are generated, and only their hashes are kept.
 */
final class TwoFactor
{
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * Whether a six-digit code is the one this secret expects right now.
     *
     * Timing-safe by construction — the underlying comparison is a hash
     * equality, not a string one — so this cannot be used to learn the code a
     * digit at a time.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     *
     * The issuer is the tool and the label is the address, so somebody with
     * three Prism accounts can tell them apart in a list that shows nothing but
     * labels.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('prism.brand.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * The QR code as inline SVG.
     *
     * SVG rather than a PNG data URI: it prints and scales cleanly, it is
     * smaller, and it needs no image library at runtime. Rendered on the server
     * so the secret never has to reach a JavaScript QR library — a secret is
     * only as private as the least careful thing that has touched it.
     */
    public function qrCodeSvg(string $uri, int $size = 200): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, 0),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($uri);
    }

    /**
     * Fresh recovery codes, in plain text.
     *
     * The caller shows these once and stores only what hashedCodes() returns.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        $count = (int) config('prism.auth.recovery_code_count', 8);

        return collect(range(1, $count))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function hashCodes(array $codes): array
    {
        return array_map(
            static fn (string $code) => hash('sha256', $code),
            $codes,
        );
    }

    /**
     * Spend a recovery code, if it matches one that has not been used.
     *
     * Compared as a hash and removed on success, so each works exactly once.
     */
    public function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $candidate = Str::lower(trim($candidate));
        $hash = hash('sha256', $candidate);

        $remaining = [];
        $found = false;

        foreach ($user->recoveryCodes() as $stored) {
            if (! $found && hash_equals($stored, $hash)) {
                $found = true;

                continue;
            }

            $remaining[] = $stored;
        }

        if ($found) {
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $found;
    }
}
