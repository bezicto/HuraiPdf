<?php

declare(strict_types=1);

use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;

$tests['incremental trailers and free tombstones'] = static function (): void {
    $objects = pageObjects();
    $objects[6] = '<< /Title (Old title) >>';
    $base = pdf($objects, '/Info 6 0 R');
    preg_match('/startxref\s+(\d+)/', $base, $match);
    $previous = (int) $match[1];
    $newObject = strlen($base);
    $updated = $base . "6 1 obj\n<< /Title (New title) >>\nendobj\n";
    $xref = strlen($updated);
    $updated .= "xref\n6 1\n" . sprintf("%010d 00001 n \n", $newObject)
        . "trailer\n<< /Size 7 /Info 6 1 R /Prev $previous >>\nstartxref\n$xref\n%%EOF\n";
    $parser = new Parser();
    same('New title', $parser->parseContent($updated)->getTitle(), 'latest Info reference and generation');
    same(2, $parser->getLastMetrics()['xref_sections'], 'incremental sections');
    $free = $base . "xref\n4 1\n0000000000 00001 f \n6 1\n0000000000 00001 f \n"
        . "trailer\n<< /Size 7 /Prev $previous >>\nstartxref\n" . strlen($base) . "\n%%EOF\n";
    $doc = $parser->parseContent($free);
    same('', $doc->getText(), 'free content cannot resurrect');
    same(null, $doc->getTitle(), 'free Info cannot resurrect');
    $nullInfo = $base . "xref\n0 1\n0000000000 65535 f \ntrailer\n<< /Size 7 /Info null /Prev $previous >>\nstartxref\n" . strlen($base) . "\n%%EOF\n";
    same(null, $parser->parseContent($nullInfo)->getTitle(), 'latest explicit null Info');
};

/** A compact PDF with compressed catalog/page/Info and either stream or hybrid xref. */
function objectStreamPdf(bool $hybrid): string
{
    $objects = pageObjects();
    $objects[6] = '<< /Title (Compressed title) >>';
    $members = [1, 2, 3, 6];
    $header = $data = '';
    foreach ($members as $id) {
        $header .= "$id " . strlen($data) . ' ';
        $data .= $objects[$id] . "\n";
        unset($objects[$id]);
    }
    $objects[7] = pdfStream(gzcompress($header . $data), '/Type /ObjStm /N 4 /First ' . strlen($header) . ' /Filter /FlateDecode');
    $out = "%PDF-1.7\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($out);
        $out .= "$id 0 obj\n$body\nendobj\n";
    }
    $offsets[8] = strlen($out);
    $entries = "\x00" . pack('Nn', 0, 65535);
    for ($id = 1; $id <= 8; $id++) {
        $member = array_search($id, $members, true);
        $entries .= $member !== false ? "\x02" . pack('Nn', 7, $member)
            : "\x01" . pack('Nn', $offsets[$id], 0);
    }
    $out .= "8 0 obj\n" . pdfStream(gzcompress($entries), '/Type /XRef /Size 9 /W [1 4 2] /Root 1 0 R /Info 6 0 R /Filter /FlateDecode') . "\nendobj\n";
    $start = $offsets[8];
    if ($hybrid) {
        $start = strlen($out);
        $out .= "xref\n0 9\n0000000000 65535 f \n";
        for ($id = 1; $id <= 8; $id++) {
            $out .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 00000 f \n";
        }
        $out .= "trailer\n<< /Size 9 /Root 1 0 R /XRefStm {$offsets[8]} >>\n";
    }
    return $out . "startxref\n$start\n%%EOF\n";
}

$tests['xref streams and hybrid precedence'] = static function (): void {
    foreach ([false, true] as $hybrid) {
        $parser = new Parser();
        $doc = $parser->parseContent(objectStreamPdf($hybrid));
        same('Hello', $doc->getText(), 'compressed pages');
        same('Compressed title', $doc->getTitle(), 'compressed Info');
        same(false, $parser->getLastMetrics()['recovery_path'], 'indexed ObjStm');
    }
};

$tests['recursion, arrays, pages and cache eviction'] = static function (): void {
    $objects = pageObjects('BT [[[[[(Nested)]]]]] TJ ET');
    limited(static fn() => (new Parser(new ParserOptions(maxRecursionDepth: 3)))->parseContent(pdf($objects)), 'array depth');
    $objects = pageObjects('BT [(a) (b) (c)] TJ ET');
    limited(static fn() => (new Parser(new ParserOptions(maxArrayElements: 2)))->parseContent(pdf($objects)), 'array elements');
    $objects = pageObjects();
    $objects[2] = '<< /Type /Pages /Kids [2 0 R] /Count 1 >>';
    limited(static fn() => (new Parser(new ParserOptions(maxRecursionDepth: 3)))->parseContent(pdf($objects)), 'page cycle');
    $objects = pageObjects();
    $objects[2] = '<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 2 /Resources << /Font << /F1 5 0 R >> >> >>';
    $objects[6] = $objects[3];
    limited(static fn() => (new Parser(new ParserOptions(maxPages: 1)))->parseContent(pdf($objects)), 'page count');
    $parser = new Parser(new ParserOptions(maxCacheBytes: 512));
    same("Hello\n\nHello", $parser->parseContent(pdf($objects))->getText(), 'shared stream reloaded after eviction');
    same(true, $parser->getLastMetrics()['cache_evictions'] > 0, 'cache eviction');
    same('Hello', $parser->parseContent(pdf($objects), 2, 2)->getText(), 'selected second page');
};

$tests['font encodings and multibyte predictors'] = static function (): void {
    foreach ([['Symbol', 'ab', 'αβ'], ['ZapfDingbats', '!', '✁'], ['Helvetica', "\256\257", 'ﬁﬂ']] as [$font, $bytes, $expected]) {
        $objects = pageObjects('BT /F1 12 Tf <' . bin2hex($bytes) . '> Tj ET');
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /$font >>";
        same($expected, (new Parser())->parseContent(pdf($objects))->getText(), $font);
    }
    $predictor = session()->predictor;
    same("\x12\x34", $predictor->applyPredictor("\x11\x11", ['Predictor' => 2, 'BitsPerComponent' => 4, 'Columns' => 4]), 'TIFF packed samples');
    same("\x00\xFF\x01\x00", $predictor->applyPredictor("\x00\xFF\x00\x01", ['Predictor' => 2, 'BitsPerComponent' => 16, 'Columns' => 2]), 'TIFF 16-bit carry');
    same(false, $predictor->applyPredictor('a', ['Predictor' => 2, 'Columns' => 2]), 'TIFF truncated row');
    same("abc\ndef\n", $predictor->applyPredictor("\x00abc\n\x02\x03\x03\x03\x00", ['Predictor' => 15, 'Columns' => 4]), 'PNG Up across rows');
};

$tests['forward Prev chain used by linearized PDFs'] = static function (): void {
    $out = "%PDF-1.7\n9 0 obj\n<< /Linearized 1 /L LLLLLLLLLL /H [0 0] /O 3 /E EEEEEEEEEE /N 1 /T TTTTTTTTTT >>\nendobj\n";
    $firstXref = strlen($out);
    $out .= "xref\n0 1\n0000000000 65535 f \ntrailer\n<< /Root 1 0 R /Size 10 /Prev PPPPPPPPPP >>\n";
    $offsets = [];
    foreach (pageObjects() as $id => $body) {
        $offsets[$id] = strlen($out);
        $out .= "$id 0 obj\n$body\nendobj\n";
    }
    $lastXref = strlen($out);
    $out .= "xref\n1 5\n";
    $entriesOffset = strlen($out);
    foreach ($offsets as $offset) { $out .= sprintf("%010d 00000 n \n", $offset); }
    $out .= "trailer\n<< /Size 10 /Root 1 0 R >>\nstartxref\n$firstXref\n%%EOF\n";
    $out = str_replace(['LLLLLLLLLL', 'EEEEEEEEEE', 'TTTTTTTTTT', 'PPPPPPPPPP'],
        array_map(static fn(int $n): string => sprintf('%010d', $n), [strlen($out), $lastXref, $entriesOffset, $lastXref]), $out);
    $parser = new Parser();
    same('Hello', $parser->parseContent($out)->getText(), 'forward Prev');
    same(2, $parser->getLastMetrics()['xref_sections'], 'linearized sections');
    same(false, $parser->getLastMetrics()['recovery_path'], 'linearized index path');
};

$tests['filter pipelines, LZW images and split text state'] = static function (): void {
    $s = session();
    $warnings = [];
    $binary = '';
    foreach ([256, 97, 98, 99, 257] as $code) { $binary .= str_pad(decbin($code), 9, '0', STR_PAD_LEFT); }
    $binary = str_pad($binary, (int) ceil(strlen($binary) / 8) * 8, '0');
    $lzw = '';
    foreach (str_split($binary, 8) as $byte) { $lzw .= chr(bindec($byte)); }
    same('abc', $s->decoder->decodeStream('<< /Filter /LZWDecode >>', $lzw, $warnings, 0), 'LZW');
    $encoded = bin2hex(gzcompress("\x01a\x01\x01")) . '>';
    same('abc', $s->decoder->decodeStream('<< /Filter [/ASCIIHexDecode /FlateDecode] /DecodeParms [null << /Predictor 15 /Columns 3 >>] >>', $encoded, $warnings, 0), 'filter and predictor alignment');
    $objects = pageObjects('BT /F1 12 Tf (Before) Tj ET BI /F /LZW ID ' . $lzw . ' EI BT (After) Tj ET');
    same("Before\nAfter", (new Parser())->parseContent(pdf($objects))->getText(), 'inline LZW skip');
    $objects = pageObjects('BT /F1 12 Tf (Part one) Tj');
    $objects[3] = '<< /Type /Page /Parent 2 0 R /Contents [4 0 R 6 0 R] >>';
    $objects[6] = pdfStream('0 -12 Td (Part two) Tj ET');
    same("Part one\nPart two", (new Parser())->parseContent(pdf($objects))->getText(), 'split text state');
};

$tests['malformed security and metadata budgets'] = static function (): void {
    $bytes = file_get_contents(__DIR__ . '/fixtures/r6-empty.pdf');
    $bytes = preg_replace_callback('/\/Perms\s*<([0-9a-f]+)>/i', static function (array $m): string {
        $hex = $m[1];
        $hex[0] = $hex[0] === '0' ? '1' : '0';
        return str_replace($m[1], $hex, $m[0]);
    }, $bytes);
    same(true, (new Parser())->parseContent($bytes)->isEncrypted(), 'tampered permissions block');
    $bytes = str_replace('/Standard', '/PubOther', file_get_contents(__DIR__ . '/fixtures/r4-empty.pdf'));
    same(true, (new Parser())->parseContent($bytes)->isEncrypted(), 'unsupported security handler');
    $objects = pageObjects();
    $objects[6] = '<< /Title (Long metadata value) >>';
    limited(static fn() => (new Parser(new ParserOptions(maxExtractedTextBytes: 8)))->parseContent(pdf($objects, '/Info 6 0 R')), 'metadata bytes');
    limited(static fn() => (new Parser(new ParserOptions(maxDecodedBytesTotal: 1000)))->parseFile(__DIR__ . '/fixtures/r6-empty.pdf'), 'R6 hashing work');
};

$tests['audit dictionary keys and indirect page lists'] = static function (): void {
    $cases = [];
    $objects = pageObjects();
    $objects[2] = str_replace('/Kids [3 0 R]', '/Kids 6 0 R', $objects[2]);
    $objects[6] = '[3 0 R]';
    $cases['indirect Kids'] = [$objects, 'Hello'];

    $objects = pageObjects();
    $objects[1] = '<< /Ty#70e /Cata#6Cog /Pa#67es 2 0 R >>';
    $objects[2] = str_replace(['/Type /Pages', '/Kids', '/Count'], ['/Ty#70e /Pa#67es', '/Ki#64s', '/Co#75nt'], $objects[2]);
    $objects[3] = str_replace(['/Type /Page', '/Contents'], ['/Ty#70e /Pa#67e', '/Cont#65nts'], $objects[3]);
    $cases['escaped structural names'] = [$objects, 'Hello'];

    $objects = pageObjects();
    $objects[1] = str_replace('/Pages', '/Note (/Pages 99 0 R) /Pages', $objects[1]);
    $objects[2] = str_replace('/Kids', '/Note << /Kids [99 0 R] >> /Kids', $objects[2]);
    $objects[3] = str_replace('/Type /Page', '/Type /Page /Note (/Type /Pages /Contents 6 0 R) /Extra << /Contents [6 0 R] >>', $objects[3]);
    $objects[6] = pdfStream('BT (Wrong text) Tj ET');
    $cases['string and nested dictionary decoys'] = [$objects, 'Hello'];

    $objects = pageObjects();
    $objects[2] = str_replace('[3 0 R]', "[% 99 0 R\n3 0 R]", $objects[2]);
    $objects[3] = str_replace('/Contents 4 0 R', "/Contents [% 6 0 R\n4 0 R 4 0 R]", $objects[3]);
    $objects[6] = pdfStream('BT (Wrong text) Tj ET');
    $cases['comments and repeated content'] = [$objects, "Hello\nHello"];

    $objects = pageObjects('BT (First) Tj ET');
    $objects[2] = str_replace(['/Kids [3 0 R]', '/Count 1'], ['/Kids 8 0 R', '/Count 2'], $objects[2]);
    $objects[6] = str_replace('/Contents 4 0 R', '/Contents 7 0 R', $objects[3]);
    $objects[7] = pdfStream('BT (Second) Tj ET');
    $objects[8] = '[6 0 R 3 0 R]';
    $cases['indirect Kids preserves page order'] = [$objects, "Second\n\nFirst"];

    $path = tempnam(sys_get_temp_dir(), 'hurai-audit-');
    try {
        foreach ($cases as $label => [$objects, $expected]) {
            foreach ([true, false] as $xref) {
                $input = pdf($objects, xref: $xref);
                file_put_contents($path, $input);
                $parser = new Parser();
                same($expected, $parser->parseContent($input)->getText(), "$label memory / xref=$xref");
                same($expected, $parser->parseFile($path)->getText(), "$label file / xref=$xref");
                $pages = iterator_to_array($parser->parseFilePages($path), false);
                same($expected, implode("\n\n", array_map(static fn($page) => $page->getText(), $pages)), "$label streaming / xref=$xref");
                if ($label === 'indirect Kids preserves page order') {
                    same('First', $parser->parseContent($input, 2, 2)->getText(), 'selected page follows Kids order');
                }
            }
        }
    } finally { unlink($path); }
};

$tests['audit page tree traversal bounds'] = static function (): void {
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    for ($id = 2; $id < 32; $id++) {
        $child = $id + 1;
        $objects[$id] = "<< /Type /Pages /Count 0 /Kids [$child 0 R $child 0 R] >>";
    }
    $objects[32] = '<< /Type /Pages /Count 0 /Kids [] >>';
    $path = tempnam(sys_get_temp_dir(), 'hurai-tree-');
    try {
        foreach ([true, false] as $xref) {
            $input = pdf($objects, xref: $xref);
            file_put_contents($path, $input);
            foreach (['memory', 'file', 'streaming'] as $mode) {
                // The deadline keeps a future traversal regression from hanging CI;
                // require the repeated-reference error, not a deadline exception.
                $parser = new Parser(new ParserOptions(maxObjects: 40, maxPages: 1, maxArrayElements: 10, deadlineSeconds: 1.0));
                try {
                    match ($mode) {
                        'memory' => $parser->parseContent($input),
                        'file' => $parser->parseFile($path),
                        'streaming' => iterator_to_array($parser->parseFilePages($path)),
                    };
                    throw new RuntimeException('Repeated page tree was accepted');
                } catch (HuraiPdf\Exception\PdfParseException $error) {
                    same(HuraiPdf\Exception\PdfParseException::RESOURCE_LIMIT_EXCEEDED, $error->getCode(), 'catchable traversal failure');
                    same('Page tree contains a repeated object reference.', $error->getMessage(), 'traversal stops before deadline');
                }
                same('Hello', $parser->parseContent(pdf(pageObjects()))->getText(), 'parser reusable after traversal failure');
            }
        }
    } finally { unlink($path); }
    $objects = pageObjects();
    $objects[2] = str_replace('[3 0 R]', '[2 0 R]', $objects[2]);
    limited(static fn() => (new Parser())->parseContent(pdf($objects)), 'direct page tree cycle');
    $objects[2] = str_replace('[2 0 R]', '[' . implode(' ', array_map(static fn($id) => $id . ' 0 R', range(99, 109))) . ']', $objects[2]);
    limited(static fn() => (new Parser(new ParserOptions(maxArrayElements: 10)))->parseContent(pdf($objects)), 'Kids array bound');
};
