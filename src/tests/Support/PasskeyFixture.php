<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Software authenticator for WebAuthn tests (packed self-attestation, ES256).
 * Reads rpId/challenge/etc. from the server's own options payloads so the
 * fixtures exercise the real CBOR + signature validation path end-to-end.
 */
final class PasskeyFixture
{
    /** @var \OpenSSLAsymmetricKey */
    public readonly mixed $privateKey;

    /**
     * @param  string  $credentialId  raw bytes
     * @param  string  $x  raw 32-byte coordinate
     * @param  string  $y  raw 32-byte coordinate
     */
    private function __construct(
        public readonly string $credentialId,
        public readonly string $x,
        public readonly string $y,
        mixed $privateKeyPem,
    ) {
        $key = openssl_pkey_get_private($privateKeyPem);
        \assert($key !== false);
        $this->privateKey = $key;
    }

    public static function generate(): self
    {
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        \assert($res !== false);
        openssl_pkey_export($res, $pem);
        $details = openssl_pkey_get_details($res);
        \assert($details !== null && isset($details['ec']));

        return new self(
            credentialId: random_bytes(32),
            x: str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT),
            y: str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
            privateKeyPem: $pem,
        );
    }

    /**
     * @param  array<string, mixed>  $options  publicKey from prepare-attestation response
     * @param  string  $origin  e.g. http://localhost
     * @return array<string, mixed> JSON payload for validate-attestation endpoint
     */
    public function attestationPayload(array $options, string $origin): array
    {
        $clientData = self::json_encode_b64std([
            'type' => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ]);

        $coseKey = self::cborMap([
            1 => 2,          // kty: EC2
            3 => -7,         // alg: ES256
            -1 => 1,         // crv: P-256
            -2 => $this->x,
            -3 => $this->y,
        ]);

        $attestedCredentialData = hash('sha256', (string) $options['rp']['id'], true)
            ."\x45" // UP|UV|AT
            .pack('N', 0)
            .str_repeat("\0", 16) // aaguid
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$coseKey;

        $authData = $attestedCredentialData === '' ? '' : $attestedCredentialData;
        \assert(strlen($authData) > 37);

        $clientDataHash = hash('sha256', self::b64std_decode($clientData), true);
        $signature = $this->sign($authData.$clientDataHash);

        $attestationObject = self::cborMap([
            'fmt' => 'packed',
            'attStmt' => ['alg' => -7, 'sig' => $signature],
            'authData' => $authData,
        ]);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientData,
                'attestationObject' => self::b64url($attestationObject),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /**
     * @param  array<string, mixed>  $options  publicKey from prepare-assertion response
     * @param  string  $userHandle  base64url user handle from registration options
     */
    public function assertionPayload(array $options, string $origin, string $userHandle, int $counter = 1): array
    {
        $clientData = self::json_encode_b64std([
            'type' => 'webauthn.get',
            'challenge' => $options['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ]);

        $authData = hash('sha256', (string) $options['rpId'], true)
            ."\x05" // UP|UV
            .pack('N', $counter);

        $clientDataHash = hash('sha256', self::b64std_decode($clientData), true);
        $signature = $this->sign($authData.$clientDataHash);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientData,
                'authenticatorData' => base64_encode($authData), // package converts std→url
                'signature' => self::b64url($signature),
                'userHandle' => $userHandle,
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /** DER signature → COSE raw r||s (64 bytes). */
    private function sign(string $tbs): string
    {
        $der = '';
        \assert(openssl_sign($tbs, $der, $this->privateKey, OPENSSL_ALGO_SHA256));

        $offset = 0;
        \assert(ord($der[$offset++]) === 0x30);
        $seqLen = ord($der[$offset++]);
        if (($seqLen & 0x80) !== 0) {
            $offset += $seqLen & 0x7F;
        }

        $readInt = function () use ($der, &$offset): string {
            \assert(ord($der[$offset++]) === 0x02);
            $len = ord($der[$offset++]);
            $value = substr($der, $offset, $len);
            $offset += $len;

            return ltrim($value, "\x00");
        };

        return str_pad($readInt(), 32, "\0", STR_PAD_LEFT)
            .str_pad($readInt(), 32, "\0", STR_PAD_LEFT);
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    /** Standard base64 (padded) — the asbiin package decodes this then re-encodes url-safe. */
    private static function json_encode_b64std(array $data): string
    {
        return base64_encode(json_encode($data, JSON_THROW_ON_ERROR));
    }

    private static function b64std_decode(string $data): string
    {
        $decoded = base64_decode($data, true);
        \assert($decoded !== false);

        return $decoded;
    }

    /** Minimal deterministic CBOR map encoder (int/text keys; int, bool, byte/string values, nested maps). */
    private static function cborMap(array $map): string
    {
        $out = self::cborHead(5, count($map));

        foreach ($map as $key => $value) {
            $out .= is_int($key) ? self::cborInt($key) : self::cborHead(3, strlen($key)).$key;
            $out .= match (true) {
                is_array($value) => self::cborMap($value),
                is_int($value) => self::cborInt($value),
                default => self::cborHead(2, strlen($value)).$value,
            };
        }

        return $out;
    }

    private static function cborInt(int $n): string
    {
        return $n < 0
            ? self::cborHead(1, -1 - $n)
            : self::cborHead(0, $n);
    }

    private static function cborHead(int $major, int $argument): string
    {
        return match (true) {
            $argument < 24 => chr(($major << 5) | $argument),
            $argument < 0x100 => chr(($major << 5) | 24).chr($argument),
            $argument < 0x10000 => chr(($major << 5) | 25).pack('n', $argument),
            default => chr(($major << 5) | 26).pack('N', $argument),
        };
    }
}
