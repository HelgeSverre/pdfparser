<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Security;

use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\CFMNameValue;
use SensitiveParameter;

readonly class FileEncryptionKey {
    public function __construct(
        #[SensitiveParameter]
        public string $value,
        public CFMNameValue $streamEncryptionMethod = CFMNameValue::V2,
        public CFMNameValue $stringEncryptionMethod = CFMNameValue::V2,
        public bool $encryptMetadata = true,
    ) {}

    public function toHex(): string {
        return bin2hex($this->value);
    }
}
