<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Security;

use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\CFMNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\TypeNameValue;
use PrinsFrank\PdfParser\Document\Encryption\RC4;
use PrinsFrank\PdfParser\Exception\NotImplementedException;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

readonly class EncryptionContext {
    private string $objectEncryptionKey;
    private string $aesObjectEncryptionKey;

    public function __construct(
        private FileEncryptionKey $fileEncryptionKey,
        private int $objectNumber,
        private int $generationNumber,
    ) {}

    public function getObjectEncryptionKey(CFMNameValue $method = CFMNameValue::V2): string {
        if ($method === CFMNameValue::AESV2 && isset($this->aesObjectEncryptionKey)) {
            return $this->aesObjectEncryptionKey;
        }
        if ($method !== CFMNameValue::AESV2 && isset($this->objectEncryptionKey)) {
            return $this->objectEncryptionKey;
        }

        $key = substr(
            md5(
                $this->fileEncryptionKey->value
                . chr($this->objectNumber & 0xFF)
                . chr(($this->objectNumber >> 8) & 0xFF)
                . chr(($this->objectNumber >> 16) & 0xFF)
                . chr($this->generationNumber & 0xFF)
                . chr(($this->generationNumber >> 8) & 0xFF)
                . ($method === CFMNameValue::AESV2 ? 'sAlT' : ''),
                true,
            ),
            0,
            min(strlen($this->fileEncryptionKey->value) + 5, 16),
        );

        return $method === CFMNameValue::AESV2
            ? $this->aesObjectEncryptionKey = $key
            : $this->objectEncryptionKey = $key;
    }

    public function decryptString(string $content): string {
        return $this->decrypt($content, $this->fileEncryptionKey->stringEncryptionMethod);
    }

    public function decryptStream(string $content, ?TypeNameValue $type = null, ?CFMNameValue $method = null): string {
        if ($type === TypeNameValue::X_REF
            || ($type === TypeNameValue::METADATA && $this->fileEncryptionKey->encryptMetadata === false)) {
            return $content;
        }

        return $this->decrypt($content, $method ?? $this->fileEncryptionKey->streamEncryptionMethod);
    }

    private function decrypt(string $content, CFMNameValue $method): string {
        if ($method === CFMNameValue::None) {
            return $content;
        }

        if ($method === CFMNameValue::V2) {
            return RC4::crypt($this->getObjectEncryptionKey(), $content);
        }

        if (!function_exists('openssl_decrypt')) {
            throw new NotImplementedException('AES decryption requires the OpenSSL extension.');
        }

        if (strlen($this->fileEncryptionKey->value) !== 16 || strlen($content) < 32 || strlen($content) % 16 !== 0) {
            throw new ParseFailureException('Invalid AESV2 key or encrypted data length.');
        }

        $plaintext = openssl_decrypt(substr($content, 16), 'aes-128-cbc', $this->getObjectEncryptionKey($method), OPENSSL_RAW_DATA, substr($content, 0, 16));
        if ($plaintext === false) {
            throw new ParseFailureException('Unable to decrypt AESV2 data.');
        }

        return $plaintext;
    }
}
