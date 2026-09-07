# Regression tests

Run from the repository root:

```sh
php tests/run.php
php -d disable_functions=mb_convert_encoding,mb_list_encodings,iconv tests/run.php
```

Tests use synthetic documents, strict error reporting, real encryption fixtures,
and public parser entry points. There is no test-framework or Composer dependency.
Temporary streaming files are removed by the tests.

`generate_encrypted_fixtures.py` was run with pypdf 6.17.0 and cryptography 50.0.1.
Those tools are only needed to regenerate the committed fixtures, not to run PHP
tests or use the library. Regeneration uses random IVs/salts, so PDF bytes change
while expected text stays the same. No uploaded or real-world documents are
included. User passwords are empty or `secret`; the owner password is
`owner-secret`.

The object-stream fixtures use pypdf's encryption implementation and a small
explicit xref-stream serializer. They include compressed catalogs, page trees,
and Info dictionaries, verifying that container contents are decrypted once.
The generated structural tests also cover forward `/Prev` chains used in
linearized files, hybrid placeholders, and incremental free-entry tombstones.

Security implementation references:

- [Adobe PDF reference](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.7old.pdf), section 3.5 (legacy Standard security).
- [qpdf encryption implementation](https://github.com/qpdf/qpdf/blob/main/libqpdf/QPDF_encryption.cc), for checking revision 6 hashing and termination.

Font encoding data sources and the Unicode permission notice are attached to
`src/HuraiPdf/Font/EncodingTables.php`.

Production regressions cover cumulative operand allocation across arrays, streams
and nested Forms, release between operators/pages, and literal/hex string limits.
The runner starts isolated PHP workers with a 64 MiB limit; it requires `proc_open`.
Dictionary fixtures cover escaped keys/name values, string/comment/nested-dictionary
decoys, indirect filters and predictor parameters, inherited resources, ToUnicode,
and encoding Differences through memory, file, generator and recovery paths.
