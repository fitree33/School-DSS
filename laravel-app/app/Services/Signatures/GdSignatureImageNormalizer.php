<?php

namespace App\Services\Signatures;

use App\Contracts\Signatures\SignatureImageNormalizer;
use App\Exceptions\ApiProblemException;
use GdImage;
use Throwable;

class GdSignatureImageNormalizer implements SignatureImageNormalizer
{
    public function __construct(private readonly PngStructureValidator $validator) {}

    public function assertAvailable(): void
    {
        $functions = ['gd_info', 'imagetypes', 'imagecreatefrompng', 'imagepng', 'imagesx', 'imagesy', 'imagecreatetruecolor', 'imagealphablending', 'imagesavealpha', 'imagecolorallocatealpha', 'imagefill', 'imagecopy', 'imagecolorat', 'imagecolorsforindex', 'imagesetpixel', 'imageinterlace', 'inflate_init', 'inflate_add', 'inflate_get_status', 'inflate_get_read_len'];
        if (! extension_loaded('gd') || ! defined('IMG_PNG')) {
            $this->fail('signature_decoder_unavailable');
        }
        foreach ($functions as $function) {
            if (! function_exists($function)) {
                $this->fail('signature_decoder_unavailable');
            }
        }
        try {
            if ((gd_info()['PNG Support'] ?? false) !== true || (imagetypes() & IMG_PNG) === 0) {
                $this->fail('signature_decoder_unavailable');
            }
        } catch (Throwable) {
            $this->fail('signature_decoder_unavailable');
        }
    }

    public function normalize(#[\SensitiveParameter] string $inputPath, #[\SensitiveParameter] string $workingDirectory): string
    {
        $this->assertAvailable();
        $owned = [];
        $source = $canvas = $readback = null;
        $failed = null;
        $result = null;
        $warning = false;
        set_error_handler(static function () use (&$warning): bool {
            $warning = true;

            return true;
        });
        try {
            $bytes = file_get_contents($inputPath, false, null, 0, PngStructureValidator::MAX_UPLOAD_BYTES + 1);
            if ($bytes === false || $warning) {
                $this->fail('signature_asset_unavailable');
            }
            $parsed = $this->validator->inspect($bytes);
            unset($bytes);
            $this->validateCompressedPixels($parsed['decoder_png']);
            if ($warning) {
                $this->invalid();
            }
            [$decoderPath, $decoderStream] = $this->createScratch($workingDirectory, $owned);
            $this->writeAll($decoderStream, $parsed['decoder_png']);
            if (! fflush($decoderStream) || $warning) {
                $this->fail('signature_asset_unavailable');
            }
            $source = $this->decode($decoderPath);
            if (! $source instanceof GdImage || $warning || imagesx($source) !== $parsed['width'] || imagesy($source) !== $parsed['height']) {
                $this->invalid();
            }
            $canvas = imagecreatetruecolor($parsed['width'], $parsed['height']);
            if (! $canvas instanceof GdImage || ! imagealphablending($canvas, false) || ! imagesavealpha($canvas, true)) {
                $this->fail('signature_decoder_unavailable');
            }
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            if ($transparent === false || ! imagefill($canvas, 0, 0, $transparent) || ! imagecopy($canvas, $source, 0, 0, 0, 0, $parsed['width'], $parsed['height'])) {
                $this->fail('signature_decoder_unavailable');
            }
            // GD uses seven-bit alpha. Canonicalize all invisible RGB after copying.
            for ($y = 0; $y < $parsed['height']; $y++) {
                for ($x = 0; $x < $parsed['width']; $x++) {
                    $pixel = imagecolorat($canvas, $x, $y);
                    if ($pixel === false) {
                        $this->fail('signature_decoder_unavailable');
                    }
                    if ((($pixel >> 24) & 0x7f) === 127 && ! imagesetpixel($canvas, $x, $y, $transparent)) {
                        $this->fail('signature_decoder_unavailable');
                    }
                }
            }
            imageinterlace($canvas, false);
            [$encodedPath, $encodedStream] = $this->createScratch($workingDirectory, $owned);
            if (! $this->encode($canvas, $encodedStream) || ! fflush($encodedStream) || $warning || fseek($encodedStream, 0) !== 0) {
                $this->fail('signature_asset_unavailable');
            }
            $encoded = stream_get_contents($encodedStream, PngStructureValidator::MAX_STORED_BYTES + 1);
            if ($encoded === false || $encoded === '' || $warning) {
                $this->fail('signature_asset_unavailable');
            }
            $result = $this->validator->stripEncodedAncillary($encoded);
            unset($encoded);
            $dimensions = $this->validator->validateNormalized($result);
            $this->validateCompressedPixels($result);
            [$readbackPath, $readbackStream] = $this->createScratch($workingDirectory, $owned);
            $this->writeAll($readbackStream, $result);
            if (! fflush($readbackStream) || $warning) {
                $this->fail('signature_asset_unavailable');
            }
            $readback = $this->decode($readbackPath);
            if (! $readback instanceof GdImage || imagesx($readback) !== $dimensions['width'] || imagesy($readback) !== $dimensions['height'] || $dimensions['width'] !== $parsed['width'] || $dimensions['height'] !== $parsed['height'] || $warning) {
                $this->fail('signature_decoder_unavailable');
            }
        } catch (Throwable $exception) {
            $failed = $exception instanceof ApiProblemException ? $exception : new ApiProblemException('Signature image processing is unavailable.', 'signature_asset_unavailable', 503);
        } finally {
            unset($source, $canvas, $readback);
            $cleanupFailed = false;
            foreach ($owned as [$path, $stream, $identity]) {
                $current = lstat($path);
                $sameFile = is_array($current) && $current['dev'] === $identity['dev'] && $current['ino'] === $identity['ino'] && ($current['mode'] & 0170000) === 0100000;
                if (is_resource($stream) && ! fclose($stream)) {
                    $cleanupFailed = true;
                }
                // Never unlink a replacement/symlink or a target that exclusive create did not own.
                if (! $sameFile || ! unlink($path)) {
                    $cleanupFailed = true;
                }
            }
            restore_error_handler();
            if ($cleanupFailed) {
                $failed = new ApiProblemException('Signature temporary cleanup was unsuccessful.', 'signature_asset_unavailable', 503);
            }
        }
        if ($failed !== null) {
            throw $failed;
        }

        return $result;
    }

    protected function decode(#[\SensitiveParameter] string $path): GdImage|false
    {
        return imagecreatefrompng($path);
    }

    /** @param resource $stream */
    protected function encode(GdImage $image, #[\SensitiveParameter] mixed $stream): bool
    {
        return imagepng($image, $stream, 6);
    }

    /** @param array<int, array{string, resource, array}> $owned
     * @return array{string, resource}
     */
    private function createScratch(#[\SensitiveParameter] string $directory, #[\SensitiveParameter] array &$owned): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.'png-'.bin2hex(random_bytes(24)).'.tmp';
        $stream = fopen($path, 'x+b');
        if ($stream === false) {
            $this->fail('signature_asset_unavailable');
        }
        $identity = fstat($stream);
        if ($identity === false) {
            fclose($stream);
            // Without an ownership identity, leave this private orphan for controlled reconciliation.
            $this->fail('signature_asset_unavailable');
        }
        $owned[] = [$path, $stream, $identity];

        return [$path, $stream];
    }

    /** @param resource $stream */
    private function writeAll(#[\SensitiveParameter] mixed $stream, #[\SensitiveParameter] string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset, 65536));
            if ($written === false || $written === 0) {
                $this->fail('signature_asset_unavailable');
            }
            $offset += $written;
        }
    }

    /** Validate the bounded deflate stream, including extra data GD may otherwise ignore. */
    private function validateCompressedPixels(#[\SensitiveParameter] string $png): void
    {
        $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', substr($png, 16, 13));
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$header['color']];
        $bitsPerPixel = $channels * $header['depth'];
        $passes = $header['interlace'] === 0 ? [[0, 0, 1, 1]] : [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]];
        $expected = 0;
        foreach ($passes as [$startX, $startY, $stepX, $stepY]) {
            if ($header['width'] <= $startX || $header['height'] <= $startY) {
                continue;
            }
            $width = intdiv($header['width'] - $startX + $stepX - 1, $stepX);
            $height = intdiv($header['height'] - $startY + $stepY - 1, $stepY);
            $expected += (1 + intdiv($width * $bitsPerPixel + 7, 8)) * $height;
        }
        $compressed = '';
        for ($offset = 8; $offset < strlen($png);) {
            $length = unpack('Nlength', substr($png, $offset, 4))['length'];
            if (substr($png, $offset + 4, 4) === 'IDAT') {
                $compressed .= substr($png, $offset + 8, $length);
            }
            $offset += $length + 12;
        }
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            $this->fail('signature_decoder_unavailable');
        }
        $decodedBytes = 0;
        $compressedBytes = strlen($compressed);
        for ($offset = 0; $offset < $compressedBytes; $offset += 256) {
            $decoded = inflate_add($context, substr($compressed, $offset, 256), $offset + 256 >= $compressedBytes ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($decoded === false) {
                $this->invalid();
            }
            $decodedBytes += strlen($decoded);
            unset($decoded);
            if ($decodedBytes > $expected) {
                $this->invalid();
            }
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                break;
            }
        }
        if ($decodedBytes !== $expected || inflate_get_status($context) !== ZLIB_STREAM_END || inflate_get_read_len($context) !== $compressedBytes) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new ApiProblemException('The signature image could not be accepted.', 'signature_image_invalid', 422);
    }

    private function fail(string $code): never
    {
        throw new ApiProblemException('Signature image processing is unavailable.', $code, 503);
    }
}
