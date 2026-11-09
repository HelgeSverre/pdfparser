These small fixtures contain only synthetic, public text. `source.pdf` is an
unencrypted one-page document containing Helvetica text, document information,
and XML metadata. The encrypted variants were generated with qpdf 12.4.2:

```sh
qpdf --static-id --allow-weak-crypto --encrypt user-password owner-password 40 -- source.pdf legacy-40.pdf
qpdf --static-id --allow-weak-crypto --encrypt '' owner-password 40 -- source.pdf legacy-40-empty-password.pdf
qpdf --static-id --allow-weak-crypto --object-streams=generate --encrypt user-password owner-password 128 --use-aes=n -- source.pdf rc4-object-stream.pdf
qpdf --static-id --static-aes-iv --object-streams=generate --encrypt user-password owner-password 128 --use-aes=y -- source.pdf aes-object-stream.pdf
qpdf --static-id --static-aes-iv --encrypt user-password owner-password 128 --use-aes=y --cleartext-metadata -- source.pdf aes-cleartext-metadata.pdf
php -r 'foreach (glob("legacy-40*.pdf") as $path) { file_put_contents($path, str_replace("/Length 40", "          ", file_get_contents($path))); }'
```

The static identifiers and IVs are exclusively for reproducible test data.
Expected file encryption keys were independently obtained with
`qpdf --password=user-password --show-encryption --show-encryption-key FILE`.
The 40-bit fixtures intentionally omit the optional encryption dictionary Length.
Object-stream fixtures store the information dictionary inside an encrypted
object stream, where its strings must not be decrypted a second time.
