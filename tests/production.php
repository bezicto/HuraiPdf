<?php

declare(strict_types=1);

use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;

$tests['production operand allocation in isolated workers'] = static function (): void {
    foreach (['arrays', 'strings', 'hex'] as $mode) {
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'max_execution_time=15', __DIR__ . '/operand_worker.php', $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start operand worker'); }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        same(0, proc_close($process), "$mode worker: $error");
        same("PASS bounded operands\n", $out, "$mode bounded allocation");
    }
};

$tests['production operand budget lifetime'] = static function (): void {
    $options = new ParserOptions(maxOperandBytes: 4096);
    limited(static fn() => (new Parser($options))->parseContent(pdf(pageObjects(str_repeat('/' . str_repeat('F', 500) . ' 12 Tf q ', 5)))), 'saved font names retain their allocation charge');
    // A budget limits live operands, not all tokens ever processed.
    same(str_repeat('A', 100), (new Parser($options))->parseContent(pdf(pageObjects('BT ' . str_repeat('(A) Tj ', 100) . 'ET')))->getText(), 'release after each operator');
    $objects = pageObjects('[0 0 0]');
    $objects[3] = str_replace('/Contents 4 0 R', '/Contents [4 0 R 6 0 R]', $objects[3]);
    $objects[6] = pdfStream('[0 0 0]');
    limited(static fn() => (new Parser($options))->parseContent(pdf($objects)), 'operands accumulate across content streams');
    $objects[4] = pdfStream('[0 0 0] n');
    $objects[6] = pdfStream('BT (A) Tj ET');
    same('A', (new Parser($options))->parseContent(pdf($objects))->getText(), 'clear before next stream');
    $objects = pageObjects('[0 0 0]');
    $objects[2] = str_replace(['/Kids [3 0 R]', '/Count 1'], ['/Kids [3 0 R 6 0 R]', '/Count 2'], $objects[2]);
    $objects[6] = $objects[3];
    same(2, count((new Parser($options))->parseContent(pdf($objects))->getPages()), 'unfinished operands released between pages');
    $objects = pageObjects('[0 0 0] /Fm Do');
    $objects[2] = str_replace('/Resources <<', '/Resources << /XObject << /Fm 6 0 R >>', $objects[2]);
    $objects[6] = pdfStream('[0 0 0]', '/Type /XObject /Subtype /Form /BBox [0 0 10 10]');
    limited(static fn() => (new Parser($options))->parseContent(pdf($objects)), 'nested forms share the live allocation budget');
};

$tests['production token-aware stream and font dictionaries'] = static function (): void {
    $cases = [];
    $plain = 'BT /F1 12 Tf (Hello) Tj ET';
    $objects = pageObjects();
    $objects[4] = pdfStream(gzcompress($plain), '/Note (/Filter /ASCIIHexDecode) /Nested << /Filter /RunLengthDecode >> /Fil#74er /Flate#44ecode');
    $cases['escaped filter and decoys'] = [$objects, 'Hello'];

    $objects = pageObjects();
    $objects[4] = pdfStream(gzcompress("\0" . $plain), '/Fil#74er 6 0 R /Decode#50arms 7 0 R');
    $objects[6] = '[/Flate#44ecode]';
    $objects[7] = '[8 0 R]';
    $objects[8] = '<< /Note (/Predictor 2 /Columns 1) /Pre#64ictor 15 /Col#75mns 9 0 R >>';
    $objects[9] = (string) strlen($plain);
    $cases['indirect filters and predictor parameters'] = [$objects, 'Hello'];

    $objects = pageObjects('BT /F1 12 Tf (A) Tj ET');
    $objects[3] = str_replace('/Parent', '/Note (/Resources << /Font << /F1 99 0 R >> >>) /Pa#72ent', $objects[3]);
    $objects[2] = str_replace('/Resources << /Font << /F1 5 0 R >> >>', '/Res#6furces 7 0 R', $objects[2]);
    $objects[7] = '<< /Note (/Font 99 0 R) /Fo#6et 8 0 R >>';
    $objects[8] = '<< /F#31 5 0 R >>';
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Note (/ToUnicode 99 0 R) /ToUni#63ode 6 0 R >>';
    $objects[6] = pdfStream(gzcompress('1 beginbfchar <41> <0042> endbfchar'), '/Fil#74er 9 0 R');
    $objects[9] = '/Flate#44ecode';
    $cases['inherited resources and Unicode mapping'] = [$objects, 'B'];

    $objects = pageObjects('BT /F1 12 Tf (A) Tj ET');
    $objects[5] = '<< /Type /Font /Sub#74ype /Ty#70e1 /Base#46ont /Helvetica /Note (/Encoding /SymbolEncoding) /Enco#64ing 6 0 R >>';
    $objects[6] = '<< /Note (/BaseEncoding /SymbolEncoding /Differences [65 /Z]) /BaseEnco#64ing /WinAnsi#45ncoding /Differ#65nces 7 0 R >>';
    $objects[7] = "[% 65 /Z\n65 /B]";
    $cases['encoding Differences and comments'] = [$objects, 'B'];

    $objects = pageObjects('/Fm Do');
    $objects[2] = str_replace('/Resources <<', '/Resources << /XOb#6aect 7 0 R', $objects[2]);
    $objects[7] = '<< /Fm 6 0 R >>';
    $objects[6] = pdfStream('BT /F1 12 Tf (Hello) Tj ET', '/Ty#70e /XOb#6aect /Sub#74ype /Fo#72m /BBox [0 0 10 10]');
    $cases['escaped Form resources'] = [$objects, 'Hello'];

    $path = tempnam(sys_get_temp_dir(), 'hurai-production-');
    try {
        foreach ($cases as $name => [$objects, $expected]) {
            foreach ([true, false] as $xref) {
                $input = pdf($objects, xref: $xref);
                file_put_contents($path, $input);
                $parser = new Parser();
                same($expected, $parser->parseContent($input)->getText(), "$name memory xref=$xref");
                if ($xref) { same([], $parser->getLastMetadata()['warnings'], "$name no warnings"); }
                same($expected, $parser->parseFile($path)->getText(), "$name file");
                $pages = iterator_to_array($parser->parseFilePages($path), false);
                same($expected, implode("\n\n", array_map(static fn($page) => $page->getText(), $pages)), "$name generator");
            }
        }
    } finally { unlink($path); }
};

$tests['production escaped security names and stream lengths'] = static function (): void {
    foreach (['r4-empty', 'r4-identity-stream', 'r6-empty'] as $fixture) {
        $original = file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.pdf');
        $expected = (new Parser())->parseContent($original)->getText();
        $changed = str_replace(['/Standard', '/AESV2', '/AESV3', '/StdCF', '/Identity', '/Crypt'],
            ['/Stan#64ard', '/AES#562', '/AES#563', '/Std#43F', '/Iden#74ity', '/Cry#70t'], $original);
        // Changing dictionary byte lengths invalidates xref offsets; explicitly
        // exercise recovery while preserving all encrypted payload bytes.
        $changed = preg_replace('/startxref\s+\d+/', "startxref\n0", $changed);
        $parser = new Parser();
        same($expected, $parser->parseContent($changed)->getText(), "$fixture escaped security names");
        same(true, $parser->getLastMetadata()['is_decrypted'], "$fixture authenticated");
    }
    $objects = pageObjects();
    $plain = 'BT (Hello endstream endobj world) Tj ET';
    $objects[4] = '<< /Note (/Length 1) /Len#67th 6 0 R >>' . "\nstream\n$plain\nendstream";
    $objects[6] = (string) strlen($plain);
    same('Hello endstream endobj world', (new Parser())->parseContent(pdf($objects))->getText(), 'indirect escaped Length ignores string decoy');
};
