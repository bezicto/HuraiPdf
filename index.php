<?php

declare(strict_types=1);

use HuraiPdf\Filter\StopWordFilter;
use HuraiPdf\Parser;

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
ini_set('max_execution_time', '300');  // 5 minutes
ini_set('memory_limit', '512M');

$errorMessage = '';
$result = null;

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                        // parseFile() opens the PDF, walks the object graph, decodes compressed streams,
                        // resolves font encoding maps, and returns a Document with one Page per parsed page.
                        // Files above the streamingThreshold are read object-by-object to limit peak RAM use.
                        // PdfParseException is thrown for unrecoverable errors (bad header, no pages, etc.).
                        // Non-fatal issues are silently collected and available via $document->getWarnings().
                        $parser   = new Parser();
                        $document = $parser->parseFile($savedPdfPath, $fromPage, $toPage);

                        // getText() concatenates text from all parsed pages into one string.
                        // StopWordFilter removes common English and Malay stopwords, leaving keywords only.
                        // Skip the filter if you want the raw unfiltered text:
                        //   $text = $document->getText();
                        // For large documents, stream page-by-page to avoid building one large string:
                        //   foreach ($document->getTextGenerator() as $pageText) { ... }
                        $stopWordFilter = new StopWordFilter();
                        $text = $stopWordFilter->filter($document->getText());

                        $savedBaseName = pathinfo($savedPdfPath, PATHINFO_FILENAME);
                        $textOutputPath = $outputDir . '/' . $savedBaseName . '.txt';
                        $metaOutputPath = $outputDir . '/' . $savedBaseName . '.json';

                        $textWritten = file_put_contents($textOutputPath, $text);
                        $metaWritten = file_put_contents(
                            $metaOutputPath,
                            json_encode(
                                [
                                    'source_file' => basename($savedPdfPath),
                                    'source_original_name' => $originalName,
                                    'extracted_at_utc' => gmdate(DATE_ATOM),
                                    'engine' => 'HuraiPdf/Parser',
                                    'engines_tried' => ['HuraiPdf/Parser'],
                                    'pdf_version' => $document->getPdfVersion(),
                                    'page_count' => $document->getPageCount(),
                                    'from_page'  => $fromPage,
                                    'to_page'    => $toPage,
                                    'is_encrypted' => $document->isEncrypted(),
                                    'warnings' => $document->getWarnings(),
                                    'text_length' => strlen($text),
                                ],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                            )
                        );

                        if ($textWritten === false || $metaWritten === false) {
                            $errorMessage = 'Extraction succeeded but saving output files failed.';
                        } else {
                            $result = [
                                'source_pdf' => basename($savedPdfPath),
                                'page_range' => 'page ' . $fromPage . ($toPage !== null ? ' to ' . $toPage : ' to end'),
                                'text_file' => 'output/' . basename($textOutputPath),
                                'meta_file' => 'output/' . basename($metaOutputPath),
                                'engine' => 'HuraiPdf/Parser',
                                'engines_tried' => ['HuraiPdf/Parser'],
                                'warnings' => $document->getWarnings(),
                                'preview' => $text,
                            ];

                            $successMessage = 'Extraction complete.';
                        }
                    } catch (Throwable $exception) {
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
    <?php endif; ?>
</body>
</html>
