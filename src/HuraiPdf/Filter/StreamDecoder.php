<?php

declare(strict_types=1);

namespace HuraiPdf\Filter;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class StreamDecoder extends Subsystem
{
    /**
     * @param string[] $warnings
     */
    public function decodeStream(
        string $dictionary,
        string $stream,
        array &$warnings,
        int $objectId,
        bool $cacheResult = false
    ): string
    {
        $this->session->budget->guardDeadline();
        if ($cacheResult && $objectId > 0 && array_key_exists($objectId, $this->context->decodedStreamCache)) {
            return $this->context->decodedStreamCache[$objectId];
        }
        if (strlen($stream) > $this->options->maxStreamBytes) {
            throw PdfParseException::resourceLimitExceeded('Compressed PDF stream exceeds maxStreamBytes.');
        }

        if ($this->context->security !== null && $objectId > 0 && $objectId !== $this->context->encryptionObjectId) {
            $plain = $this->context->security->decryptStream($stream, $objectId, $this->context->objectGenerations[$objectId] ?? 0, $dictionary);
            if ($plain === false) {
                $this->session->budget->addWarning($warnings, 'Stream decryption failed on object ' . $objectId . '.');
                return '';
            }
            $stream = $plain;
        }

        $filterPipeline = $this->parseFilterPipeline($dictionary);
        if ($filterPipeline === []) {
            $this->session->budget->accountIntermediateBytes(strlen($stream));
            $this->session->budget->accountDecodedBytes(strlen($stream));
            if ($cacheResult && $objectId > 0) {
                $this->session->budget->cacheStringResult('decodedStreamCache', $objectId, $stream);
            }
            return $stream;
        }

        $decoded = $stream;
        foreach ($filterPipeline as $filterStep) {
            $filter = $filterStep['filter'];
            $decodeParams = $filterStep['decode_params'];

            $result = match ($filter) {
                'Crypt' => $this->context->security !== null ? $decoded : false,
                'FlateDecode', 'Fl' => $this->decodeFlate($decoded),
                'ASCIIHexDecode', 'AHx' => $this->decodeAsciiHex($decoded),
                'ASCII85Decode', 'A85' => $this->decodeAscii85($decoded),
                'LZWDecode', 'LZW' => $this->decodeLzw($decoded, $decodeParams),
                'RunLengthDecode', 'RL' => $this->decodeRunLength($decoded),
                default => false,
            };

            if ($result === false) {
                $this->session->budget->addWarning($warnings, 'Unsupported or failed stream filter "' . $filter . '" on object ' . $objectId . '.');
                if ($cacheResult && $objectId > 0) {
                    $this->context->decodedStreamCache[$objectId] = '';
                }
                return '';
            }

            if (strlen($result) > $this->options->maxStreamBytes) {
                throw PdfParseException::resourceLimitExceeded('Decoded PDF stream exceeds maxStreamBytes.');
            }

            $this->session->budget->accountIntermediateBytes(strlen($result));
            $decoded = $result;

            if (in_array($filter, ['FlateDecode', 'Fl', 'LZWDecode', 'LZW'], true)) {
                $postPredictor = $this->session->predictor->applyPredictor($decoded, $decodeParams);
                if ($postPredictor === false) {
                    $this->session->budget->addWarning($warnings, 'Predictor decode failed for filter "' . $filter . '" on object ' . $objectId . '.');
                    if ($cacheResult && $objectId > 0) {
                        $this->context->decodedStreamCache[$objectId] = '';
                    }
                    return '';
                }
                $decoded = $postPredictor;
            }
        }

        $this->session->budget->accountDecodedBytes(strlen($decoded));
        if ($cacheResult && $objectId > 0) {
            $this->session->budget->cacheStringResult('decodedStreamCache', $objectId, $decoded);
        }

        return $decoded;
    }

    /**
     * @return array<int, array{filter: string, decode_params: array<string, int>}>
     */
    private function parseFilterPipeline(string $dictionary): array
    {
        $filters = [];

        if (preg_match('/\/Filter\s*\[(.*?)\]/s', $dictionary, $arrayMatch) === 1) {
            preg_match_all('/\/([A-Za-z0-9]+)/', $arrayMatch[1], $matches);
            $filters = $matches[1] ?? [];
        } elseif (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $dictionary, $singleMatch) === 1) {
            $filters = [$singleMatch[1]];
        }

        if ($filters === []) {
            return [];
        }

        $decodeParamsByIndex = [];
        if (preg_match('/\/DecodeParms\s*\[(.*?)\]/s', $dictionary, $decodeParmsArrayMatch) === 1) {
            $decodeParamsByIndex = $this->parseDecodeParmsArray($decodeParmsArrayMatch[1]);
        } elseif (preg_match('/\/DecodeParms\s*<<((?:[^>]|>(?!>))*+)>>/s', $dictionary, $decodeParmsDictMatch) === 1) {
            $decodeParamsByIndex[0] = $this->parseDecodeParmsDictionary('<<' . $decodeParmsDictMatch[1] . '>>');
        } elseif (preg_match('/\/DP\s*\[(.*?)\]/s', $dictionary, $dpArrayMatch) === 1) {
            $decodeParamsByIndex = $this->parseDecodeParmsArray($dpArrayMatch[1]);
        } elseif (preg_match('/\/DP\s*<<((?:[^>]|>(?!>))*+)>>/s', $dictionary, $dpDictMatch) === 1) {
            $decodeParamsByIndex[0] = $this->parseDecodeParmsDictionary('<<' . $dpDictMatch[1] . '>>');
        }

        $pipeline = [];
        foreach ($filters as $index => $filter) {
            $pipeline[] = [
                'filter' => $filter,
                'decode_params' => $decodeParamsByIndex[$index] ?? [],
            ];
        }

        return $pipeline;
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function parseDecodeParmsArray(string $decodeParmsArrayBody): array
    {
        $items = $this->session->syntax->parsePdfArrayItems($decodeParmsArrayBody);
        $params = [];

        foreach ($items as $index => $item) {
            $trimmed = trim($item);
            if ($trimmed === '' || $trimmed === 'null') {
                $params[$index] = [];
                continue;
            }

            if (str_starts_with($trimmed, '<<') && str_contains($trimmed, '>>')) {
                $params[$index] = $this->parseDecodeParmsDictionary($trimmed);
                continue;
            }

            $params[$index] = [];
        }

        return $params;
    }

    /**
     * @return array<string, int>
     */
    private function parseDecodeParmsDictionary(string $dictionaryBody): array
    {
        return [
            'Predictor' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['Predictor'], 1),
            'Colors' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['Colors'], 1),
            'BitsPerComponent' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['BitsPerComponent', 'BPC'], 8),
            'Columns' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['Columns'], 1),
            'EarlyChange' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['EarlyChange'], 1),
        ];
    }

    /**
     * @param string[] $keys
     */
    private function parseIntegerDictionaryValueAliases(string $dictionaryBody, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            $value = $this->parseIntegerDictionaryValue($dictionaryBody, $key, PHP_INT_MIN);
            if ($value !== PHP_INT_MIN) {
                return $value;
            }
        }

        return $default;
    }

    private function parseIntegerDictionaryValue(string $dictionaryBody, string $key, int $default): int
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '\s+(-?\d+)/', $dictionaryBody, $match) === 1) {
            return (int) $match[1];
        }

        return $default;
    }

    private function decodeFlate(string $stream): string|false
    {
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_GZIP, ZLIB_ENCODING_RAW] as $encoding) {
            $inflater = inflate_init($encoding);
            if ($inflater === false) { continue; }
            $out = '';
            for ($offset = 0, $length = strlen($stream); $offset < $length; $offset += 1024) {
                $this->session->budget->guardDeadline();
                $part = @inflate_add($inflater, substr($stream, $offset, 1024), ZLIB_SYNC_FLUSH);
                if ($part === false) { break; }
                if (strlen($part) > $this->options->maxStreamBytes - strlen($out)) {
                    throw PdfParseException::resourceLimitExceeded('Flate stream exceeds maxStreamBytes.');
                }
                if (strlen($out) + strlen($part) > $this->options->maxDecodedBytesTotal - $this->context->intermediateDecodedBytes) {
                    throw PdfParseException::resourceLimitExceeded('Flate stream exceeds maxDecodedBytesTotal.');
                }
                $out .= $part;
                if (inflate_get_status($inflater) === ZLIB_STREAM_END) { return $out; }
            }
        }
        return false;
    }

    private function decodeAsciiHex(string $stream): string|false
    {
        $clean = preg_replace('/\s+/', '', $stream);
        if ($clean === null) {
            return false;
        }

        $clean = rtrim($clean, '>');
        if ($clean === '') {
            return '';
        }

        if (strlen($clean) % 2 === 1) {
            $clean .= '0';
        }

        $decoded = @hex2bin($clean);
        if ($decoded === false) {
            return false;
        }

        return $decoded;
    }

    private function decodeAscii85(string $stream): string|false
    {
        $clean = preg_replace('/\s+/', '', $stream);
        if ($clean === null) {
            return false;
        }

        $clean = str_replace(['<~', '~>'], '', $clean);
        if ($clean === '') {
            return '';
        }

        $result = '';
        $group = '';
        $length = strlen($clean);

        for ($i = 0; $i < $length; $i++) {
            if (($i & 1023) === 0) { $this->session->budget->guardDeadline(); }
            $char = $clean[$i];

            if ($char === 'z') {
                if ($group !== '') {
                    return false;
                }
                if (strlen($result) + 4 > $this->options->maxStreamBytes) {
                    throw PdfParseException::resourceLimitExceeded('ASCII85 stream exceeds maxStreamBytes.');
                }
                $result .= "\x00\x00\x00\x00";
                continue;
            }

            $ord = ord($char);
            if ($ord < 33 || $ord > 117) {
                continue;
            }

            $group .= $char;

            if (strlen($group) === 5) {
                $value = 0;
                for ($j = 0; $j < 5; $j++) {
                    $value = ($value * 85) + (ord($group[$j]) - 33);
                }

                $result .= pack('N', $value);
                $group = '';
            }
        }

        if ($group !== '') {
            $missing = 5 - strlen($group);
            $group .= str_repeat('u', $missing);

            $value = 0;
            for ($j = 0; $j < 5; $j++) {
                $value = ($value * 85) + (ord($group[$j]) - 33);
            }

            $packed = pack('N', $value);
            $result .= substr($packed, 0, 4 - $missing);
        }

        return $result;
    }

    /**
     * @param array<string, int> $decodeParams
     */
    public function decodeLzw(string $stream, array $decodeParams, ?int &$consumedBytes = null, bool $discardOutput = false, int $startOffset = 0): string|false
    {
        $consumedBytes = null;
        $dataLength = strlen($stream);
        if ($dataLength === 0) {
            return '';
        }

        $earlyChange = ($decodeParams['EarlyChange'] ?? 1) === 0 ? 0 : 1;
        $clearCode = 256;
        $eodCode = 257;
        $maxCode = 4095;
        $byteOffset = $startOffset;
        $bitBuffer = 0;
        $bitsInBuffer = 0;

        $dictionary = [];
        for ($i = 0; $i <= 255; $i++) {
            $dictionary[$i] = chr($i);
        }
        $codeWidth = 9;
        $nextCode = 258;
        $previousCode = null;
        $output = '';
        $outputBytes = 0;
        $codesRead = 0;

        while (true) {
            while ($bitsInBuffer < $codeWidth && $byteOffset < $dataLength) {
                $bitBuffer = ($bitBuffer << 8) | ord($stream[$byteOffset++]);
                $bitsInBuffer += 8;
            }
            if ($bitsInBuffer < $codeWidth) {
                break;
            }
            $bitsInBuffer -= $codeWidth;
            $code = ($bitBuffer >> $bitsInBuffer) & ((1 << $codeWidth) - 1);
            $bitBuffer = $bitsInBuffer === 0
                ? 0
                : $bitBuffer & ((1 << $bitsInBuffer) - 1);
            $codesRead++;
            if (($codesRead & 4095) === 0) {
                $this->session->budget->guardDeadline();
            }

            if ($code === $clearCode) {
                $dictionary = [];
                for ($i = 0; $i <= 255; $i++) {
                    $dictionary[$i] = chr($i);
                }
                $codeWidth = 9;
                $nextCode = 258;
                $previousCode = null;
                continue;
            }

            if ($code === $eodCode) {
                $consumedBytes = $byteOffset - $startOffset;
                break;
            }

            if (isset($dictionary[$code])) {
                $entry = $dictionary[$code];
            } elseif ($previousCode !== null && $code === $nextCode) {
                $previousValue = $dictionary[$previousCode] ?? '';
                if ($previousValue === '') {
                    return false;
                }
                $entry = $previousValue . $previousValue[0];
            } else {
                return false;
            }

            $outputBytes += strlen($entry);
            if ($outputBytes > $this->options->maxStreamBytes) {
                throw PdfParseException::resourceLimitExceeded('LZW stream exceeds maxStreamBytes.');
            }
            if ($discardOutput) { $this->session->budget->accountIntermediateBytes(strlen($entry)); }
            else {
                if ($outputBytes > $this->options->maxDecodedBytesTotal - $this->context->intermediateDecodedBytes) {
                    throw PdfParseException::resourceLimitExceeded('LZW stream exceeds maxDecodedBytesTotal.');
                }
                $output .= $entry;
            }

            if ($previousCode !== null) {
                $previousValue = $dictionary[$previousCode] ?? '';
                if ($previousValue !== '' && $nextCode <= $maxCode) {
                    $dictionary[$nextCode] = $previousValue . $entry[0];
                    $nextCode++;

                    $threshold = (1 << $codeWidth) - $earlyChange;
                    if ($codeWidth < 12 && $nextCode >= $threshold) {
                        $codeWidth++;
                    }
                }
            }

            $previousCode = $code;
        }

        return $output;
    }

    private function decodeRunLength(string $stream): string|false
    {
        $length = strlen($stream);
        if ($length === 0) {
            return '';
        }

        $out = '';
        $offset = 0;

        while ($offset < $length) {
            $this->session->budget->guardDeadline();
            $runLength = ord($stream[$offset]);
            $offset++;

            if ($runLength === 128) {
                break;
            }

            if ($runLength <= 127) {
                $literalLength = $runLength + 1;
                if ($offset + $literalLength > $length) {
                    return false;
                }

                $out .= substr($stream, $offset, $literalLength);
                if (strlen($out) > $this->options->maxStreamBytes) {
                    throw PdfParseException::resourceLimitExceeded('RunLength stream exceeds maxStreamBytes.');
                }
                $offset += $literalLength;
                continue;
            }

            if ($offset >= $length) {
                return false;
            }

            $repeatCount = 257 - $runLength;
            $out .= str_repeat($stream[$offset], $repeatCount);
            if (strlen($out) > $this->options->maxStreamBytes) {
                throw PdfParseException::resourceLimitExceeded('RunLength stream exceeds maxStreamBytes.');
            }
            $offset++;
        }

        return $out;
    }
}
