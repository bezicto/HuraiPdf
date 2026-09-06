<?php

declare(strict_types=1);

namespace HuraiPdf\Security;

use HuraiPdf\Internal\Subsystem;
use HuraiPdf\Exception\PdfParseException;

/** @internal Standard security handler for revisions 2 through 6. */
final class StandardSecurityHandler extends Subsystem
{
    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private string $key = '';
    private int $revision = 0;
    private string $streamFilter = 'V2';
    private string $stringFilter = 'V2';
    private bool $encryptMetadata = true;
    /** @var array<string, string> */
    private array $filters = ['Identity' => 'None'];

    public function authenticate(string $dictionary, string $documentId, string $password = ''): bool
    {
        $this->session->budget->guardDeadline();
        $this->key = '';
        // Manual installations may bypass Composer's extension requirements.
        if (!extension_loaded('openssl')) {
            return false;
        }
        $d = $this->session->syntax->dictionaryEntries($dictionary);
        if (($d['Filter'] ?? '') !== '/Standard') { return false; }
        $this->revision = (int) ($d['R'] ?? 0);
        $version = (int) ($d['V'] ?? 0);
        if (!in_array([$this->revision, $version], [[2, 1], [3, 2], [4, 4], [5, 5], [6, 5]], true)) { return false; }
        $this->encryptMetadata = ($d['EncryptMetadata'] ?? 'true') !== 'false';
        $bytes = fn(string $key): string => $this->session->syntax->stringBytes($d[$key] ?? '') ?? '';
        $owner = $bytes('O');
        $user = $bytes('U');
        if (!isset($d['P'])) { return false; }
        $permissions = (int) $d['P'];

        if ($version >= 4) {
            $this->filters = ['Identity' => 'None'];
            foreach ($this->session->syntax->dictionaryEntries($d['CF'] ?? '') as $name => $filter) {
                $cf = $this->session->syntax->dictionaryEntries($filter);
                $method = ltrim($cf['CFM'] ?? '/None', '/');
                if (!in_array($method, $version === 5 ? ['None', 'AESV3'] : ['None', 'V2', 'AESV2'], true)) { return false; }
                $this->filters[$name] = $method;
            }
            $streamName = ltrim($d['StmF'] ?? '/Identity', '/');
            $stringName = ltrim($d['StrF'] ?? '/Identity', '/');
            if (!isset($this->filters[$streamName], $this->filters[$stringName])) { return false; }
            $this->streamFilter = $this->filters[$streamName];
            $this->stringFilter = $this->filters[$stringName];
        }

        if ($this->revision >= 5) {
            if (strlen($owner) !== 48 || strlen($user) !== 48 || strlen($bytes('UE')) !== 32 || strlen($bytes('OE')) !== 32) { return false; }
            // Empty and ASCII passwords need no SASLprep. Other supplied passwords
            // are accepted as UTF-8 bytes, capped to the Standard handler's 127 bytes.
            $password = substr($password, 0, 127);
            if (hash_equals(substr($user, 0, 32), $this->passwordHash($password, substr($user, 32, 8)))) {
                $wrappingKey = $this->passwordHash($password, substr($user, 40, 8));
                $wrapped = $bytes('UE');
            } elseif (hash_equals(substr($owner, 0, 32), $this->passwordHash($password, substr($owner, 32, 8), $user))) {
                $wrappingKey = $this->passwordHash($password, substr($owner, 40, 8), $user);
                $wrapped = $bytes('OE');
            } else { return false; }
            $key = openssl_decrypt($wrapped, 'aes-256-cbc', $wrappingKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
            if ($key === false || strlen($key) !== 32 || strlen($bytes('Perms')) !== 16) { return false; }
            $perms = openssl_decrypt($bytes('Perms'), 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            $expected = pack('V', $permissions) . str_repeat("\xFF", 4) . ($this->encryptMetadata ? 'T' : 'F') . 'adb';
            if ($perms === false || !hash_equals($expected, substr($perms, 0, 12))) { return false; }
            $this->key = $key;
            return true;
        }

        if (strlen($owner) !== 32 || strlen($user) !== 32 || $documentId === '') { return false; }
        $bits = $this->revision === 2 ? 40 : (int) ($d['Length'] ?? 40);
        if ($bits < 40 || $bits > 128 || $bits % 8 !== 0) { return false; }
        if (($this->streamFilter === 'AESV2' || $this->stringFilter === 'AESV2') && $bits !== 128) { return false; }
        $length = intdiv($bits, 8);
        $padded = substr($password . self::PADDING, 0, 32);
        $key = $this->legacyKey($padded, $owner, $permissions, $documentId, $length);
        if (!$this->validateLegacyUser($key, $user, $documentId)) {
            // An owner password recovers the padded user password stored in /O.
            $ownerKey = md5($padded, true);
            if ($this->revision >= 3) {
                for ($i = 0; $i < 50; $i++) { $ownerKey = md5($ownerKey, true); }
            }
            $ownerKey = substr($ownerKey, 0, $length);
            $paddedUser = $owner;
            for ($i = $this->revision >= 3 ? 19 : 0; $i >= 0; $i--) {
                $paddedUser = $this->rc4($paddedUser, $ownerKey ^ str_repeat(chr($i), $length));
            }
            $key = $this->legacyKey($paddedUser, $owner, $permissions, $documentId, $length);
            if (!$this->validateLegacyUser($key, $user, $documentId)) { return false; }
        }
        $this->key = $key;
        return true;
    }

    private function legacyKey(string $padded, string $owner, int $permissions, string $id, int $length): string
    {
        $material = $padded . $owner . pack('V', $permissions) . $id;
        if ($this->revision >= 4 && !$this->encryptMetadata) { $material .= "\xFF\xFF\xFF\xFF"; }
        $hash = md5($material, true);
        if ($this->revision >= 3) {
            for ($i = 0; $i < 50; $i++) { $hash = md5(substr($hash, 0, $length), true); }
        }
        return substr($hash, 0, $length);
    }

    private function validateLegacyUser(string $key, string $user, string $id): bool
    {
        $value = $this->revision === 2 ? self::PADDING : md5(self::PADDING . $id, true);
        for ($i = 0, $rounds = $this->revision === 2 ? 1 : 20; $i < $rounds; $i++) {
            $value = $this->rc4($value, $key ^ str_repeat(chr($i), strlen($key)));
        }
        return hash_equals($value, substr($user, 0, strlen($value)));
    }

    private function passwordHash(string $password, string $salt, string $user = ''): string
    {
        $hash = hash('sha256', $password . $salt . $user, true);
        if ($this->revision === 5) { return $hash; }
        $round = 0;
        do {
            $this->session->budget->guardDeadline();
            $block = str_repeat($password . $hash . $user, 64);
            $this->session->budget->accountIntermediateBytes(strlen($block));
            $encrypted = openssl_encrypt($block, 'aes-128-cbc', substr($hash, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($hash, 16, 16));
            if ($encrypted === false) { return ''; }
            $sum = 0;
            for ($i = 0; $i < 16; $i++) { $sum += ord($encrypted[$i]); }
            $hash = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $encrypted, true);
            $round++;
        } while ($round < 64 || ord($encrypted[strlen($encrypted) - 1]) > $round - 32);
        return substr($hash, 0, 32);
    }

    public function decryptStream(string $data, int $id, int $generation, string $dictionary): string|false
    {
        $d = $this->session->syntax->dictionaryEntries($dictionary);
        if (($d['Type'] ?? '') === '/XRef' || (!$this->encryptMetadata && ($d['Type'] ?? '') === '/Metadata')) { return $data; }
        $method = $this->streamFilter;
        $filters = ($d['Filter'][0] ?? '') === '['
            ? $this->session->syntax->parsePdfArrayItems(substr($d['Filter'], 1, -1)) : [$d['Filter'] ?? ''];
        $crypt = array_search('/Crypt', $filters, true);
        if ($crypt !== false) {
            // Crypt must be first in a filter pipeline, before decompression.
            if ($crypt !== 0) { return false; }
            $parameters = $d['DecodeParms'] ?? '';
            if (str_starts_with($parameters, '[')) {
                $parameters = $this->session->syntax->parsePdfArrayItems(substr($parameters, 1, -1))[0] ?? '';
            }
            $name = ltrim($this->session->syntax->dictionaryEntries($parameters)['Name'] ?? '/Identity', '/');
            $method = $this->filters[$name] ?? 'Unsupported';
        }
        return $this->decrypt($data, $id, $generation, $method);
    }

    public function decryptString(string $data, int $id, int $generation): string|false
    {
        return $this->decrypt($data, $id, $generation, $this->stringFilter);
    }

    private function decrypt(string $data, int $id, int $generation, string $method): string|false
    {
        $this->session->budget->guardDeadline();
        if ($method === 'None') { return $data; }
        if ($this->key === '' || !in_array($method, ['V2', 'AESV2', 'AESV3'], true)) { return false; }
        if (strlen($data) > $this->options->maxStreamBytes) {
            throw PdfParseException::resourceLimitExceeded('Encrypted data exceeds maxStreamBytes.');
        }
        $this->session->budget->accountIntermediateBytes(strlen($data));
        $key = $this->key;
        if ($method !== 'AESV3') {
            $material = $key . substr(pack('V', $id), 0, 3) . substr(pack('v', $generation), 0, 2);
            if ($method === 'AESV2') { $material .= 'sAlT'; }
            $key = substr(md5($material, true), 0, min(strlen($key) + 5, 16));
        }
        if ($method === 'V2') { return $this->rc4($data, $key); }
        if (strlen($data) < 32 || strlen($data) % 16 !== 0) { return false; }
        return openssl_decrypt(substr($data, 16), $method === 'AESV3' ? 'aes-256-cbc' : 'aes-128-cbc', $key, OPENSSL_RAW_DATA, substr($data, 0, 16));
    }

    private function rc4(string $data, string $key): string
    {
        // OpenSSL 3 often disables the legacy provider. Never change host config.
        if (in_array('rc4', openssl_get_cipher_methods(), true)) {
            $result = openssl_decrypt($data, 'rc4', $key, OPENSSL_RAW_DATA | OPENSSL_DONT_ZERO_PAD_KEY);
            if ($result !== false) { return $result; }
        }
        $state = range(0, 255);
        $j = 0;
        for ($i = 0, $length = strlen($key); $i < 256; $i++) {
            $j = ($j + $state[$i] + ord($key[$i % $length])) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }
        $i = $j = 0;
        $out = $data;
        for ($p = 0, $length = strlen($data); $p < $length; $p++) {
            if (($p & 4095) === 0) { $this->session->budget->guardDeadline(); }
            $i = ($i + 1) & 255;
            $j = ($j + $state[$i]) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $out[$p] = chr(ord($data[$p]) ^ $state[($state[$i] + $state[$j]) & 255]);
        }
        return $out;
    }
}
