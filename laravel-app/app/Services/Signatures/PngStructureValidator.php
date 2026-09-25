<?php

namespace App\Services\Signatures;

use App\Exceptions\ApiProblemException;

/** Bounded PNG framing validation. Ancillary contents never reach the image decoder. */
final class PngStructureValidator
{
    public const MAX_UPLOAD_BYTES = 2097152;

    public const MAX_STORED_BYTES = 8388608;

    public const MAX_WIDTH = 2048;

    public const MAX_HEIGHT = 1024;

    public const MAX_PIXELS = 1048576;

    public const MAX_CHUNKS = 1024;

    public const MAX_ANCILLARY_BYTES = 65536;

    public const MAGIC = "\x89PNG\r\n\x1a\n";

    /** @return array{width: int, height: int, decoder_png: string} */
    public function inspect(#[\SensitiveParameter] string $bytes): array
    {
        return $this->parse($bytes, self::MAX_UPLOAD_BYTES, false);
    }

    /** @return array{width: int, height: int} */
    public function validateNormalized(#[\SensitiveParameter] string $bytes): array
    {
        $parsed = $this->parse($bytes, self::MAX_STORED_BYTES, true);

        return ['width' => $parsed['width'], 'height' => $parsed['height']];
    }

    /** Only call for newly GD-encoded bytes, never as a substitute for normalization. */
    public function stripEncodedAncillary(#[\SensitiveParameter] string $bytes): string
    {
        $parsed = $this->parse($bytes, self::MAX_STORED_BYTES, false);
        $this->validateNormalized($parsed['decoder_png']);

        return $parsed['decoder_png'];
    }

    /** @return array{width: int, height: int, decoder_png: string} */
    private function parse(#[\SensitiveParameter] string $bytes, int $maximumBytes, bool $normalized): array
    {
        $size = strlen($bytes);
        if ($size > $maximumBytes) {
            $this->fail($maximumBytes === self::MAX_UPLOAD_BYTES ? 'signature_upload_too_large' : 'signature_image_limits_exceeded', $maximumBytes === self::MAX_UPLOAD_BYTES ? 413 : 422);
        }
        if ($size < 8 || substr($bytes, 0, 8) !== self::MAGIC) {
            $this->fail('signature_format_unsupported');
        }

        $offset = 8;
        $count = 0;
        $ancillaryBytes = 0;
        $width = $height = $depth = $color = $paletteEntries = 0;
        $idatBytes = 0;
        $idatEnded = false;
        $seen = [];
        $decoder = self::MAGIC;
        while ($offset < $size) {
            if (++$count > self::MAX_CHUNKS) {
                $this->fail('signature_image_limits_exceeded');
            }
            if ($size - $offset < 12) {
                $this->fail();
            }
            $length = unpack('Nlength', substr($bytes, $offset, 4))['length'];
            $type = substr($bytes, $offset + 4, 4);
            if ($length > 2147483647 || $length > $size - $offset - 12 || ! preg_match('/\A[A-Za-z]{2}[A-Z][A-Za-z]\z/D', $type)) {
                $this->fail();
            }
            $data = substr($bytes, $offset + 8, $length);
            if (! hash_equals(hash('crc32b', $type.$data, true), substr($bytes, $offset + 8 + $length, 4))) {
                $this->fail();
            }
            $chunk = substr($bytes, $offset, $length + 12);
            $offset += $length + 12;
            if (in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) {
                $this->fail('signature_format_unsupported');
            }
            $critical = ord($type[0]) < 97;
            if ($critical && ! in_array($type, ['IHDR', 'PLTE', 'IDAT', 'IEND'], true)) {
                $this->fail('signature_format_unsupported');
            }
            if (! $critical) {
                $ancillaryBytes += $length + 12;
                if ($ancillaryBytes > self::MAX_ANCILLARY_BYTES) {
                    $this->fail('signature_image_limits_exceeded');
                }
            }
            if ($normalized && ! in_array($type, ['IHDR', 'IDAT', 'IEND'], true)) {
                $this->fail();
            }
            if (($count === 1 && $type !== 'IHDR') || ($count > 1 && $type === 'IHDR')) {
                $this->fail();
            }
            if (isset($seen['IDAT']) && $type !== 'IDAT') {
                $idatEnded = true;
            }
            switch ($type) {
                case 'IHDR':
                    if ($length !== 13) {
                        $this->fail();
                    }
                    $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                    $width = $header['width'];
                    $height = $header['height'];
                    $depth = $header['depth'];
                    $color = $header['color'];
                    if ($width < 1 || $height < 1 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT || $width > intdiv(self::MAX_PIXELS, $height)) {
                        $this->fail('signature_image_limits_exceeded');
                    }
                    $legal = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];
                    if (! isset($legal[$color]) || ! in_array($depth, $legal[$color], true) || $header['compression'] !== 0 || $header['filter'] !== 0 || ! in_array($header['interlace'], [0, 1], true)) {
                        $this->fail();
                    }
                    if ($normalized && ($depth !== 8 || $color !== 6 || $header['interlace'] !== 0)) {
                        $this->fail();
                    }
                    break;
                case 'PLTE':
                    if (isset($seen['PLTE']) || isset($seen['IDAT']) || isset($seen['tRNS']) || isset($seen['bKGD']) || isset($seen['hIST']) || in_array($color, [0, 4], true) || $length < 3 || $length > 768 || $length % 3 !== 0) {
                        $this->fail();
                    }
                    $paletteEntries = intdiv($length, 3);
                    if ($color === 3 && $paletteEntries > (1 << $depth)) {
                        $this->fail();
                    }
                    break;
                case 'tRNS':
                    if (isset($seen['tRNS']) || isset($seen['IDAT']) || in_array($color, [4, 6], true)) {
                        $this->fail();
                    }
                    if (($color === 0 && $length !== 2) || ($color === 2 && $length !== 6) || ($color === 3 && ($paletteEntries === 0 || $length < 1 || $length > $paletteEntries))) {
                        $this->fail();
                    }
                    if ($color !== 3) {
                        foreach (unpack('n*', $data) as $sample) {
                            if ($sample > (1 << $depth) - 1) {
                                $this->fail();
                            }
                        }
                    }
                    break;
                case 'IDAT':
                    if ($idatEnded || ($color === 3 && $paletteEntries === 0)) {
                        $this->fail();
                    }
                    $idatBytes += $length;
                    break;
                case 'IEND':
                    if ($length !== 0 || ! isset($seen['IDAT']) || $idatBytes === 0 || $offset !== $size) {
                        $this->fail();
                    }
                    break;
                default:
                    $this->validateAncillary($type, $data, $seen, $color, $depth, $paletteEntries);
            }
            $seen[$type] = true;
            if (in_array($type, ['IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND'], true)) {
                $decoder .= $chunk;
            }
        }
        if (! isset($seen['IEND'])) {
            $this->fail();
        }

        return ['width' => $width, 'height' => $height, 'decoder_png' => $decoder];
    }

    /** @param array<string, bool> $seen */
    private function validateAncillary(string $type, #[\SensitiveParameter] string $data, array $seen, int $color, int $depth, int $paletteEntries): void
    {
        $length = strlen($data);
        $single = ['cHRM', 'gAMA', 'iCCP', 'sBIT', 'sRGB', 'cICP', 'mDCV', 'cLLI', 'bKGD', 'hIST', 'pHYs', 'eXIf', 'tIME'];
        if (in_array($type, $single, true) && isset($seen[$type])) {
            $this->fail();
        }
        if (in_array($type, ['cHRM', 'gAMA', 'iCCP', 'sBIT', 'sRGB', 'cICP', 'mDCV', 'cLLI'], true) && (isset($seen['PLTE']) || isset($seen['IDAT']))) {
            $this->fail();
        }
        if (in_array($type, ['bKGD', 'hIST', 'pHYs', 'sPLT'], true) && isset($seen['IDAT'])) {
            $this->fail();
        }
        $fixed = ['cHRM' => 32, 'gAMA' => 4, 'sRGB' => 1, 'cICP' => 4, 'mDCV' => 24, 'cLLI' => 8, 'pHYs' => 9, 'tIME' => 7];
        if (isset($fixed[$type]) && $length !== $fixed[$type]) {
            $this->fail();
        }
        if (($type === 'gAMA' && $data === "\0\0\0\0") || ($type === 'sRGB' && (ord($data[0]) > 3 || isset($seen['iCCP']))) || ($type === 'iCCP' && isset($seen['sRGB'])) || ($type === 'pHYs' && ord($data[8]) > 1)) {
            $this->fail();
        }
        if ($type === 'sBIT') {
            $channels = [0 => 1, 2 => 3, 3 => 3, 4 => 2, 6 => 4][$color];
            if ($length !== $channels) {
                $this->fail();
            }
            foreach (unpack('C*', $data) as $bits) {
                if ($bits < 1 || $bits > ($color === 3 ? 8 : $depth)) {
                    $this->fail();
                }
            }
        }
        if ($type === 'bKGD') {
            $required = [0 => 2, 2 => 6, 3 => 1, 4 => 2, 6 => 6][$color];
            if ($length !== $required || ($color === 3 && ($paletteEntries === 0 || ord($data[0]) >= $paletteEntries))) {
                $this->fail();
            }
            if ($color !== 3) {
                foreach (unpack('n*', $data) as $sample) {
                    if ($sample > (1 << $depth) - 1) {
                        $this->fail();
                    }
                }
            }
        }
        if ($type === 'hIST' && ($paletteEntries === 0 || $length !== $paletteEntries * 2)) {
            $this->fail();
        }
        if ($type === 'tIME') {
            $time = unpack('nyear/Cmonth/Cday/Chour/Cminute/Csecond', $data);
            if (! checkdate($time['month'], $time['day'], $time['year']) || $time['hour'] > 23 || $time['minute'] > 59 || $time['second'] > 60) {
                $this->fail();
            }
        }
        if (in_array($type, ['tEXt', 'zTXt', 'iTXt', 'iCCP', 'sPLT'], true)) {
            $separator = strpos($data, "\0");
            if ($separator === false || $separator < 1 || $separator > 79) {
                $this->fail();
            }
            $keyword = substr($data, 0, $separator);
            if (! preg_match('/\A[\x20-\x7e\xa1-\xff]+\z/D', $keyword) || $keyword[0] === ' ' || str_ends_with($keyword, ' ') || str_contains($keyword, '  ')) {
                $this->fail();
            }
            $rest = substr($data, $separator + 1);
            if (in_array($type, ['zTXt', 'iCCP'], true) && (strlen($rest) < 2 || $rest[0] !== "\0")) {
                $this->fail();
            }
            if ($type === 'tEXt' && str_contains($rest, "\0")) {
                $this->fail();
            }
            if ($type === 'iTXt' && (strlen($rest) < 4 || ! in_array(ord($rest[0]), [0, 1], true) || $rest[1] !== "\0" || substr_count(substr($rest, 2), "\0") < 2)) {
                $this->fail();
            }
            if ($type === 'sPLT' && ($rest === '' || ! in_array(ord($rest[0]), [8, 16], true) || strlen($rest) === 1 || (strlen($rest) - 1) % (ord($rest[0]) === 8 ? 6 : 10) !== 0)) {
                $this->fail();
            }
        }
    }

    private function fail(string $code = 'signature_image_invalid', int $status = 422): never
    {
        throw new ApiProblemException('The signature image could not be accepted.', $code, $status);
    }
}
