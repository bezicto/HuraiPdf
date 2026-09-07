<?php

declare(strict_types=1);

// Run in a separate PHP process with openssl_get_cipher_methods,
// openssl_encrypt, and openssl_decrypt disabled. Simulate the extension check
// because this development PHP binary has OpenSSL compiled in.
namespace HuraiPdf\Security {
    function extension_loaded(string $extension): bool
    {
        return $extension === 'openssl' ? false : \extension_loaded($extension);
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    foreach (['openssl_get_cipher_methods', 'openssl_encrypt', 'openssl_decrypt'] as $function) {
        same(false, function_exists($function), $function . ' must be disabled for this test');
    }

    $fixtures = array_map(static fn(int $revision): string => "r$revision-empty", range(2, 6));
    $fixtures = array_merge($fixtures, ['r4-object-stream', 'r6-object-stream']);
    foreach ($fixtures as $fixture) {
        $path = __DIR__ . '/fixtures/' . $fixture . '.pdf';
        $bytes = file_get_contents($path);
        $parser = new HuraiPdf\Parser(new HuraiPdf\ParserOptions(streamingThreshold: 0));
        foreach (['file', 'buffer', 'recovery', 'generator', 'callback'] as $mode) {
            $result = match ($mode) {
                'file' => $parser->parseFile($path),
                'buffer' => $parser->parseContent($bytes),
                'recovery' => $parser->parseContent(preg_replace('/startxref\s+\d+/', "startxref\n0", $bytes)),
                'generator' => iterator_to_array($parser->parseFilePages($path)),
                'callback' => $parser->extractFile($path, static function (): void {
                    throw new RuntimeException('Encrypted pages must not reach the callback');
                }),
            };
            if ($result instanceof HuraiPdf\Document) {
                same(true, $result->isEncrypted(), "$fixture $mode document encryption");
                same('', $result->getText(), "$fixture $mode text");
                same([], $result->getPages(), "$fixture $mode pages");
            } elseif ($mode === 'generator') {
                same([], $result, "$fixture generator pages");
            }
            $metadata = $parser->getLastMetadata();
            same(true, $metadata['is_encrypted'], "$fixture $mode encrypted metadata");
            same(false, $metadata['is_decrypted'], "$fixture $mode decrypted metadata");
            same(0, $metadata['page_count'], "$fixture $mode page count");
            same(true, in_array('Encrypted PDF detected. Extraction quality may be limited.', $metadata['warnings'], true), "$fixture $mode warning");

            $plain = $parser->parseContent(pdf(pageObjects()));
            same('Hello', $plain->getText(), "$fixture $mode unencrypted reuse");
            same(false, $plain->isEncrypted(), "$fixture $mode reset encryption");
            same([], $plain->getWarnings(), "$fixture $mode reset warnings");
        }
    }
    echo "PASS simulated missing OpenSSL: 7 fixtures, 5 entry paths, and unencrypted parser reuse.\n";
}
