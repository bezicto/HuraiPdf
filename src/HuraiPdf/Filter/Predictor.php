<?php

declare(strict_types=1);

namespace HuraiPdf\Filter;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class Predictor extends Subsystem
{
    /**
     * @param array<string, int> $decodeParams
     */
    public function applyPredictor(string $data, array $decodeParams): string|false
    {
        $this->session->budget->guardDeadline();
        if (strlen($data) > $this->options->maxStreamBytes) {
            throw PdfParseException::resourceLimitExceeded('Predictor input exceeds maxStreamBytes.');
        }
        $predictor = (int) ($decodeParams['Predictor'] ?? 1);
        if ($predictor <= 1) {
            return $data;
        }

        $colors = max(1, (int) ($decodeParams['Colors'] ?? 1));
        $bitsPerComponent = max(1, (int) ($decodeParams['BitsPerComponent'] ?? 8));
        $columns = max(1, (int) ($decodeParams['Columns'] ?? 1));

        if (!in_array($bitsPerComponent, [1, 2, 4, 8, 16], true)) {
            return false;
        }

        if ($colors > 65536 || $columns > 65536 || $bitsPerComponent > 16) { return false; }
        $bytesPerPixel = max(1, intdiv(($colors * $bitsPerComponent) + 7, 8));
        $rowBytes = intdiv(($columns * $colors * $bitsPerComponent) + 7, 8);

        if ($rowBytes <= 0 || $rowBytes > 65536) {
            return false; // sanity cap: text PDFs never need rows wider than 64 KB
        }

        if (strlen($data) > $this->options->maxDecodedBytesTotal - $this->context->intermediateDecodedBytes) {
            throw PdfParseException::resourceLimitExceeded('Predictor output exceeds maxDecodedBytesTotal.');
        }
        $result = match (true) {
            $predictor === 2 => $this->applyTiffPredictor($data, $rowBytes, $colors, $columns, $bitsPerComponent),
            $predictor >= 10 && $predictor <= 15 => $this->applyPngPredictor($data, $rowBytes, $bytesPerPixel),
            default => false,
        };
        if ($result !== false) { $this->session->budget->accountIntermediateBytes(strlen($result)); }
        return $result;
    }

    private function applyTiffPredictor(string $data, int $rowBytes, int $colors, int $columns, int $bits): string|false
    {
        if (strlen($data) % $rowBytes !== 0) { return false; }
        $out = '';
        $mask = (1 << $bits) - 1;
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += $rowBytes) {
            $this->session->budget->guardDeadline();
            $row = substr($data, $offset, $rowBytes);
            // TIFF predicts components, not bytes; 16-bit samples carry between
            // bytes, and 1/2/4-bit samples share their containing byte.
            for ($sample = $colors; $sample < $columns * $colors; $sample++) {
                if (($sample & 1023) === 0) { $this->session->budget->guardDeadline(); }
                $position = $sample * $bits;
                $leftPosition = ($sample - $colors) * $bits;
                if ($bits === 16) {
                    $byte = intdiv($position, 8);
                    $leftByte = intdiv($leftPosition, 8);
                    $value = ((ord($row[$byte]) << 8) | ord($row[$byte + 1]))
                        + ((ord($row[$leftByte]) << 8) | ord($row[$leftByte + 1]));
                    $row[$byte] = chr(($value >> 8) & 255);
                    $row[$byte + 1] = chr($value & 255);
                } else {
                    $byte = intdiv($position, 8);
                    $shift = 8 - $bits - ($position % 8);
                    $leftShift = 8 - $bits - ($leftPosition % 8);
                    $value = ((ord($row[$byte]) >> $shift) & $mask)
                        + ((ord($row[intdiv($leftPosition, 8)]) >> $leftShift) & $mask);
                    $row[$byte] = chr((ord($row[$byte]) & ~($mask << $shift)) | (($value & $mask) << $shift));
                }
            }
            $out .= $row;
        }
        return $out;
    }

    private function applyPngPredictor(string $data, int $rowBytes, int $bytesPerPixel): string|false
    {
        $length = strlen($data);
        if ($length === 0) {
            return '';
        }

        $offset = 0;
        $out = '';
        $previousRow = str_repeat("\x00", $rowBytes);

        while ($offset < $length) {
            $this->session->budget->guardDeadline();
            if ($offset + 1 > $length) {
                break;
            }

            $filterType = ord($data[$offset]);
            $offset++;

            if ($offset + $rowBytes > $length) {
                return false;
            }

            $encodedRow = substr($data, $offset, $rowBytes);
            $offset += $rowBytes;

            $decodedRow = str_repeat("\x00", $rowBytes);
            for ($i = 0; $i < $rowBytes; $i++) {
                $raw = ord($encodedRow[$i]);
                $left = $i >= $bytesPerPixel ? ord($decodedRow[$i - $bytesPerPixel]) : 0;
                $up = ord($previousRow[$i]);
                $upLeft = $i >= $bytesPerPixel ? ord($previousRow[$i - $bytesPerPixel]) : 0;

                $value = match ($filterType) {
                    0 => $raw,
                    1 => ($raw + $left) & 0xFF,
                    2 => ($raw + $up) & 0xFF,
                    3 => ($raw + intdiv($left + $up, 2)) & 0xFF,
                    4 => ($raw + $this->paethPredictor($left, $up, $upLeft)) & 0xFF,
                    default => -1,
                };

                if ($value < 0) {
                    return false;
                }

                $decodedRow[$i] = chr($value);
            }

            $out .= $decodedRow;
            $previousRow = $decodedRow;
        }

        return $out;
    }

    private function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }
}
