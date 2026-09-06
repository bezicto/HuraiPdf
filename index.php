<?php

declare(strict_types=1);

use HuraiPdf\Filter\StopWordFilter;
use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;

$baseDir = __DIR__;
$outputDir = $baseDir . '/output';
$uploadDir = $baseDir . '/uploads';

spl_autoload_register(
    static function (string $class) use ($baseDir): void {
        $prefix = 'HuraiPdf\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $filePath = $baseDir . '/src/HuraiPdf/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($filePath)) {
            require $filePath;
        }
    }
);

// ---------------------------------------------------------------------------
// Upload & runtime limits — adjust these to match your server's needs.
// MAX_UPLOAD_BYTES : maximum accepted PDF file size (default: 60 MB).
// max_execution_time : seconds PHP is allowed to spend parsing one file.
// memory_limit      : peak RAM PHP may use during a single parse operation.
// ---------------------------------------------------------------------------
define('MAX_UPLOAD_BYTES', 60 * 1024 * 1024); // 60 MB, the 60 means 60MB
define('MAX_PREVIEW_BYTES', 100 * 1024); // Keep browser responses bounded.
ini_set('max_execution_time', '300');  // 5 minutes
$memoryLimit = '512M';
$maxMemoryLimit = ini_get('max_memory_limit');
$maxMemoryBytes = is_string($maxMemoryLimit) ? huraiPdfIniSizeToBytes($maxMemoryLimit) : null;
if ($maxMemoryBytes !== null && $maxMemoryBytes < 512 * 1024 * 1024) {
    $memoryLimit = (string) $maxMemoryBytes;
}
ini_set('memory_limit', $memoryLimit);

$errorMessage = '';
$result = null;
$excludeNumbers = false;

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $excludeNumbers = (string) ($_POST['exclude_numbers'] ?? '') === '1';

    if (!isset($_FILES['pdf_file'])) {
        $errorMessage = 'No file uploaded.';
    } else {
        $uploadedFile = $_FILES['pdf_file'];
        $uploadError = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errorMessage = 'Upload failed with error code: ' . $uploadError;
        } else {
            $tmpPath = (string) ($uploadedFile['tmp_name'] ?? '');
            $originalName = (string) ($uploadedFile['name'] ?? 'document.pdf');
            $fileSize = (int) ($uploadedFile['size'] ?? 0);

            if ($fileSize <= 0) {
                $errorMessage = 'Uploaded file is empty.';
            } elseif ($fileSize > MAX_UPLOAD_BYTES) {
                $errorMessage = 'File is too large. Maximum allowed size is ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.';
            } elseif (!is_uploaded_file($tmpPath)) {
                $errorMessage = 'Invalid upload source.';
            } elseif (!isPdf($tmpPath, $originalName)) {
                $errorMessage = 'Only PDF files are allowed.';
            } else {
                $savedPdfPath = buildSavedPdfPath($uploadDir, $originalName);

                if (!move_uploaded_file($tmpPath, $savedPdfPath)) {
                    $errorMessage = 'Failed to save uploaded file.';
                } else {
                    try {
                        // Page range defaults: start at page 1, parse to the end of the document.
                        // $toPage = null tells the library to parse all remaining pages.
                        // Both values are overridden below only if the user supplied them in the form.
                        $fromPage    = 1;
                        $toPage      = null;
                        $fromPageRaw = trim((string) ($_POST['from_page'] ?? ''));
                        $toPageRaw   = trim((string) ($_POST['to_page'] ?? ''));

                        // Validate and apply $fromPage only when the field was filled in.
                        // filter_var with FILTER_VALIDATE_INT rejects floats, negative numbers, and strings.
                        if ($fromPageRaw !== '') {
                            $parsed = filter_var($fromPageRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                            if ($parsed === false) {
                                throw new \InvalidArgumentException('From page must be a positive integer.');
                            }
                            $fromPage = $parsed;
                        }

                        // Same validation for $toPage. Leaving this empty means "parse to the last page".
                        if ($toPageRaw !== '') {
                            $parsed = filter_var($toPageRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                            if ($parsed === false) {
                                throw new \InvalidArgumentException('To page must be a positive integer.');
                            }
                            $toPage = $parsed;
                        }

                        // --- CALL THE LIBRARY ---
                        // Parser is the main entry point. No arguments needed for default settings.
                        // To tune memory/performance thresholds, pass a ParserOptions object:
                        //
                        //   use HuraiPdf\ParserOptions;
                        //   $parser = new Parser(new ParserOptions(
                        //       streamingThreshold: 10 * 1024 * 1024, // use streaming mode for files > 10 MB
                        //       maxCMapSize:        2_097_152,         // max bytes to decode from a font CMap
                        //       objectChunkSize:    524_288,           // bytes read per chunk in streaming mode
                        //   ));
                        //
                        // extractFile() walks the object graph, decodes compressed streams, resolves font
                        // encoding maps, and invokes a callback as each requested page is extracted.
                        // Files above the streamingThreshold are read lazily to limit peak RAM use.
                        // PdfParseException is thrown for unrecoverable errors (bad header, no pages, etc.).
                        // Non-fatal issues and performance counters are returned after extraction.
                        $savedBaseName = pathinfo($savedPdfPath, PATHINFO_FILENAME);
                        $textOutputPath = $outputDir . '/' . $savedBaseName . '.txt';
                        $metaOutputPath = $outputDir . '/' . $savedBaseName . '.json';
                        $textTemporaryPath = $textOutputPath . '.part';
                        $metaTemporaryPath = $metaOutputPath . '.part';
                        $textHandle = fopen($textTemporaryPath, 'wb');
                        if ($textHandle === false) {
                            throw new \RuntimeException('Unable to create the output text file.');
                        }

                        $parser = new Parser(new ParserOptions(
                            maxObjects: 200_000,
                            maxPages: 20_000,
                            maxStreamBytes: 64 * 1024 * 1024,
                            maxDecodedBytesTotal: 256 * 1024 * 1024,
                            maxContentOperators: 5_000_000,
                            deadlineSeconds: 280.0,
                        ));
                        $stopWordFilter = new StopWordFilter();
                        $textLength = 0;
                        $preview = '';
                        $hasWrittenText = false;

                        try {
                            $extraction = $parser->extractFile(
                                $savedPdfPath,
                                static function ($page) use (
                                    $stopWordFilter,
                                    $textHandle,
                                    $excludeNumbers,
                                    &$textLength,
                                    &$preview,
                                    &$hasWrittenText
                                ): void {
                                    $filtered = $stopWordFilter->filter($page->getText(), $excludeNumbers);
                                    if ($filtered === '') {
                                        return;
                                    }
                                    $chunk = ($hasWrittenText ? ' ' : '') . $filtered;
                                    writeAll($textHandle, $chunk);
                                    $hasWrittenText = true;
                                    $textLength += strlen($chunk);
                                    if (strlen($preview) < MAX_PREVIEW_BYTES) {
                                        $preview .= substr($chunk, 0, MAX_PREVIEW_BYTES - strlen($preview));
                                    }
                                },
                                $fromPage,
                                $toPage
                            );
                        } finally {
                            fclose($textHandle);
                        }

                        $metadata = $extraction['metadata'];
                        $metrics = $extraction['metrics'];
                        $metaJson = json_encode(
                            [
                                'source_file' => basename($savedPdfPath),
                                'source_original_name' => $originalName,
                                'extracted_at_utc' => gmdate(DATE_ATOM),
                                'engine' => 'HuraiPdf/Parser',
                                'engines_tried' => ['HuraiPdf/Parser'],
                                'pdf_version' => $metadata['pdf_version'],
                                'page_count' => $metadata['page_count'],
                                'from_page' => $fromPage,
                                'to_page' => $toPage,
                                'exclude_numbers' => $excludeNumbers,
                                'is_encrypted' => $metadata['is_encrypted'],
                                'warnings' => $metadata['warnings'],
                                'text_length' => $textLength,
                                'performance' => $metrics,
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        );
                        writeAllFile($metaTemporaryPath, $metaJson);

                        if (!rename($textTemporaryPath, $textOutputPath)) {
                            throw new \RuntimeException('Unable to publish the output text file.');
                        }
                        if (!rename($metaTemporaryPath, $metaOutputPath)) {
                            @unlink($textOutputPath);
                            throw new \RuntimeException('Unable to publish the output metadata file.');
                        }

                        $result = [
                            'source_pdf' => basename($savedPdfPath),
                            'page_range' => 'page ' . $fromPage . ($toPage !== null ? ' to ' . $toPage : ' to end'),
                            'text_file' => 'output/' . basename($textOutputPath),
                            'meta_file' => 'output/' . basename($metaOutputPath),
                            'engine' => 'HuraiPdf/Parser',
                            'engines_tried' => ['HuraiPdf/Parser'],
                            'exclude_numbers' => $excludeNumbers,
                            'warnings' => $metadata['warnings'],
                            'preview' => $preview,
                            'preview_truncated' => $textLength > strlen($preview),
                        ];

                        $successMessage = 'Extraction complete.';
                    } catch (Throwable $exception) {
                        if (isset($textTemporaryPath)) {
                            @unlink($textTemporaryPath);
                        }
                        if (isset($metaTemporaryPath)) {
                            @unlink($metaTemporaryPath);
                        }
                        if (isset($savedPdfPath)) {
                            @unlink($savedPdfPath);
                        }
                        // Do not expose internal file paths from FILE_NOT_READABLE exceptions
                        if (
                            $exception instanceof \HuraiPdf\Exception\PdfParseException &&
                            $exception->getCode() === \HuraiPdf\Exception\PdfParseException::FILE_NOT_READABLE
                        ) {
                            $errorMessage = 'The file could not be read.';
                        } else {
                            $errorMessage = $exception->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// Validate that the uploaded file is a PDF by checking extension, MIME type, and file signature.
function isPdf(string $tmpPath, string $originalName): bool
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);
    if (in_array($mimeType, ['application/pdf', 'application/x-pdf'], true)) {
        return true;
    }

    $signature = file_get_contents($tmpPath, false, null, 0, 5);

    return $signature === '%PDF-';
}

// Build a safe file path for the uploaded PDF to be saved on the server.
function buildSavedPdfPath(string $uploadDir, string $originalName): string
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base) ?: 'document';
    try {
        $randomPart = bin2hex(random_bytes(4));
    } catch (Throwable) {
        $randomPart = uniqid();
    }
    $suffix = gmdate('Ymd_His') . '_' . $randomPart;

    return $uploadDir . '/' . $safeBase . '_' . $suffix . '.pdf';
}

// Convert the simple byte-size format used by PHP INI memory directives.
// A null result represents an unlimited or unrecognised value.
function huraiPdfIniSizeToBytes(string $value): ?int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return null;
    }

    if (preg_match('/^(\d+)\s*([KMG])?$/i', $value, $matches) !== 1) {
        return null;
    }

    $amount = (int) $matches[1];
    $multiplier = match (strtoupper($matches[2] ?? '')) {
        'G' => 1024 * 1024 * 1024,
        'M' => 1024 * 1024,
        'K' => 1024,
        default => 1,
    };

    if ($amount > intdiv(PHP_INT_MAX, $multiplier)) {
        return PHP_INT_MAX;
    }

    return $amount * $multiplier;
}

/** @param resource $handle */
function writeAll($handle, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($handle, substr($data, $offset, 8192));
        if ($written === false || $written === 0) {
            throw new \RuntimeException('Failed while writing extracted text.');
        }
        $offset += $written;
    }
}

function writeAllFile(string $path, string $data): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new \RuntimeException('Unable to create the output metadata file.');
    }
    try {
        writeAll($handle, $data);
    } finally {
        fclose($handle);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HuraiPdf Parser</title>
</head>
<body>
    <h1>HuraiPdf Parser Usage Example</h1>
    <form method="post" enctype="multipart/form-data">
        <label for="pdf_file">PDF file:</label>
        <input id="pdf_file" name="pdf_file" type="file" accept="application/pdf,.pdf" required>
        <br><br>
        <label for="from_page">From page (optional):</label>
        <input id="from_page" name="from_page" type="number" min="1" value="<?php echo htmlspecialchars((string) ($_POST['from_page'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        &nbsp;
        <label for="to_page">To page (optional):</label>
        <input id="to_page" name="to_page" type="number" min="1" value="<?php echo htmlspecialchars((string) ($_POST['to_page'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <br><br>
        <label>
            <input name="exclude_numbers" type="checkbox" value="1"<?php echo $excludeNumbers ? ' checked' : ''; ?>>
            Exclude numbers/digits from the final output
        </label>
        <br><br>
        <button type="submit">Upload and Extract</button>
    </form>

    <?php if ($errorMessage !== ''): ?>
        <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <h2>Result</h2>
        <p>Source PDF: <?php echo htmlspecialchars($result['source_pdf'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Pages parsed: <?php echo htmlspecialchars($result['page_range'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Engine used: <?php echo htmlspecialchars($result['engine'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Engines tried: <?php echo htmlspecialchars(implode(', ', $result['engines_tried']), ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Numbers/digits excluded: <?php echo $result['exclude_numbers'] ? 'Yes' : 'No'; ?></p>
        <p>Text output: <a href="<?php echo htmlspecialchars($result['text_file'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($result['text_file'], ENT_QUOTES, 'UTF-8'); ?></a></p>
        <p>Meta output: <a href="<?php echo htmlspecialchars($result['meta_file'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($result['meta_file'], ENT_QUOTES, 'UTF-8'); ?></a></p>

        <?php if ($result['warnings'] !== []): ?>
            <h3>Warnings</h3>
            <ul>
                <?php foreach ($result['warnings'] as $warning): ?>
                    <li><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Extracted Text</h3>
        <pre style="white-space:pre-wrap;word-break:break-word;max-height:80vh;overflow-y:auto;border:1px solid #ccc;padding:1em;"><?php echo htmlspecialchars($result['preview'], ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php if ($result['preview_truncated']): ?>
            <p>Preview truncated. Download the text output for the complete extraction.</p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
