import { api } from '@/lib/api';
import type { BootPayload } from '@/types';

/**
 * The browser half of a passkey.
 *
 * ── Why this is hand-written and not a package ───────────────────────────────
 *
 * The whole ceremony is about sixty lines: ask the server for a challenge,
 * convert a few base64url strings into ArrayBuffers because the WebAuthn API
 * insists on them, hand it to `navigator.credentials`, and convert what comes
 * back the other way. A dependency for that is a dependency to audit, update
 * and eventually work around.
 *
 * The conversions are the only part with any subtlety. WebAuthn speaks
 * ArrayBuffer and JSON does not, so everything crossing that line is base64url
 * — which is *not* base64: it uses - and _ instead of + and /, and drops the
 * padding. Feeding one to `atob` produces silent corruption rather than an
 * error, and the result is an assertion the server rejects with no useful
 * explanation.
 */

export type PasskeyLoginResult = {
    two_factor_required: boolean;
    redirect: string;
    boot?: BootPayload;
};

/** Whether this browser can do any of this at all. */
export function isPasskeySupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.PublicKeyCredential !== 'undefined' &&
        typeof navigator.credentials?.create === 'function'
    );
}

function toBuffer(value: string): ArrayBuffer {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
}

function toBase64Url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

type Descriptor = { id: string; type: string; transports?: string[] };

function decodeDescriptors(list: Descriptor[] | undefined): PublicKeyCredentialDescriptor[] {
    return (list ?? []).map((entry) => ({
        id: toBuffer(entry.id),
        type: 'public-key' as const,
        transports: entry.transports as AuthenticatorTransport[] | undefined,
    }));
}

/**
 * Register a passkey on this device.
 *
 * @param name what to call it in the user's list of devices
 */
export async function registerPasskey(name?: string): Promise<void> {
    const options = await api.post<{
        challenge: string;
        rp: PublicKeyCredentialRpEntity;
        user: { id: string; name: string; displayName: string };
        pubKeyCredParams: PublicKeyCredentialParameters[];
        timeout?: number;
        excludeCredentials?: Descriptor[];
        authenticatorSelection?: AuthenticatorSelectionCriteria;
        attestation?: AttestationConveyancePreference;
    }>('/passkeys/options');

    // Any DOMException from here — a cancelled prompt, a refused device — is
    // left to travel up as it is. The caller distinguishes "the user changed
    // their mind", which deserves no message at all, from a real failure.
    const credential = (await navigator.credentials.create({
        publicKey: {
            ...options,
            challenge: toBuffer(options.challenge),
            user: { ...options.user, id: toBuffer(options.user.id) },
            excludeCredentials: decodeDescriptors(options.excludeCredentials),
        },
    })) as PublicKeyCredential | null;

    if (!credential) {
        throw new Error('No passkey was created.');
    }

    const response = credential.response as AuthenticatorAttestationResponse;

    await api.post('/passkeys', {
        id: credential.id,
        rawId: toBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: toBase64Url(response.clientDataJSON),
            attestationObject: toBase64Url(response.attestationObject),
        },
        name,
    });
}

/**
 * Sign in with a passkey.
 *
 * No email is asked for: the browser offers whichever passkeys it holds for
 * this origin and the user picks one. That is both the pleasant path and the
 * private one — an endpoint that takes an address before issuing a challenge is
 * an endpoint that can be asked which addresses have accounts.
 */
export async function signInWithPasskey(): Promise<PasskeyLoginResult> {
    const options = await api.post<{
        challenge: string;
        timeout?: number;
        rpId?: string;
        allowCredentials?: Descriptor[];
        userVerification?: UserVerificationRequirement;
    }>('/auth/passkey/options');

    const assertion = (await navigator.credentials.get({
        publicKey: {
            ...options,
            challenge: toBuffer(options.challenge),
            allowCredentials: decodeDescriptors(options.allowCredentials),
        },
    })) as PublicKeyCredential | null;

    if (!assertion) {
        throw new Error('No passkey was offered.');
    }

    const response = assertion.response as AuthenticatorAssertionResponse;

    return api.post<PasskeyLoginResult>('/auth/passkey/login', {
        id: assertion.id,
        rawId: toBase64Url(assertion.rawId),
        type: assertion.type,
        response: {
            clientDataJSON: toBase64Url(response.clientDataJSON),
            authenticatorData: toBase64Url(response.authenticatorData),
            signature: toBase64Url(response.signature),
            userHandle: response.userHandle ? toBase64Url(response.userHandle) : null,
        },
    });
}
