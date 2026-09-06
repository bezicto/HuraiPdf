<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$composerAutoload = $projectRoot . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class) use ($projectRoot): void {
        foreach (['HuraiPdf\\Tests\\' => '/tests/', 'HuraiPdf\\' => '/src/HuraiPdf/'] as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                $path = $projectRoot . $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) {
                    require $path;
                }
                return;
            }
        }
    });
}

use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;
use HuraiPdf\Tests\Support\PdfFixtureFactory;

$pages = array_map(static fn(int $page): string => str_repeat('Page ' . $page . ' performance text ', 50), range(1, 250));
$pdf = PdfFixtureFactory::textPdf($pages, 3000, 256);
$path = tempnam(sys_get_temp_dir(), 'hurai-pdf-bench-');
if ($path === false || file_put_contents($path, $pdf) !== strlen($pdf)) {
    throw new RuntimeException('Unable to create benchmark fixture.');
}

try {
    foreach ([['range', 100, 110], ['full', 1, null]] as [$name, $from, $to]) {
        $parser = new Parser(new ParserOptions(streamingThreshold: 1));
        $started = hrtime(true);
        $document = $parser->parseFile($path, $from, $to);
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $metrics = $parser->getLastMetrics();
        printf(
            "%s: %.2f ms, %d pages, %d/%d objects loaded, %d object bytes, %d decoded bytes, %d operators, %.2f MiB peak\n",
            $name,
            $elapsed,
            $document->getPageCount(),
            $metrics['objects_loaded'],
            $metrics['objects_indexed'],
            $metrics['object_bytes_read'],
            $metrics['decoded_bytes'],
            $metrics['content_operators'],
            $metrics['peak_memory_bytes'] / 1048576
        );
    }

    $parser = new Parser(new ParserOptions(streamingThreshold: 1));
    $textBytes = 0;
    $started = hrtime(true);
    $result = $parser->extractFile($path, static function ($page) use (&$textBytes): void {
        $textBytes += strlen($page->getText());
    });
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $metrics = $result['metrics'];
    printf(
        "sink: %.2f ms, %d pages, %d text bytes, %d/%d objects loaded, %.2f MiB peak\n",
        $elapsed,
        $result['metadata']['page_count'],
        $textBytes,
        $metrics['objects_loaded'],
        $metrics['objects_indexed'],
        $metrics['peak_memory_bytes'] / 1048576
    );
} finally {
    @unlink($path);
}
