<?php

namespace Tests\Unit\Signatures;

use App\Exceptions\ApiProblemException;
use App\Services\Signatures\PngStructureValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SignaturePngFixture as Png;

class PngStructureValidatorTest extends TestCase
{
    #[DataProvider('legalHeaders')]
    public function test_legal_color_depth_combinations_are_accepted(int $color, int $depth): void
    {
        $palette = $color === 3 ? Png::chunk('PLTE', "\0\0\0") : '';
        $png = Png::png(Png::header(1, 1, $depth, $color), $palette, Png::chunk('IDAT', 'framing-only'), Png::chunk('IEND'));
        $parsed = (new PngStructureValidator)->inspect($png);

        $this->assertSame(1, $parsed['width']);
        $this->assertSame(1, $parsed['height']);
        $this->assertSame($png, $parsed['decoder_png']);
    }

    public static function legalHeaders(): iterable
    {
        foreach ([0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]] as $color => $depths) {
            foreach ($depths as $depth) {
                yield "color-$color-depth-$depth" => [$color, $depth];
            }
        }
    }

    public function test_ancillary_metadata_is_removed_before_decoder_input(): void
    {
        $metadata = Png::chunk('tEXt', "Comment\0private metadata").Png::chunk('zTXt', "Comment\0\0".str_repeat('untrusted-compressed-data', 20)).Png::chunk('vpAg', 'unknown ancillary payload');
        $parsed = (new PngStructureValidator)->inspect(Png::rgba($metadata));

        $this->assertSame(Png::rgba(), $parsed['decoder_png']);
        $this->assertSame(['IHDR', 'IDAT', 'IEND'], array_column(Png::chunks($parsed['decoder_png']), 'type'));
    }

    public function test_palette_and_transparency_survive_decoder_filtering(): void
    {
        $parsed = (new PngStructureValidator)->inspect(Png::indexed());

        $this->assertSame(Png::indexed(), $parsed['decoder_png']);
        $this->assertSame(['IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND'], array_column(Png::chunks($parsed['decoder_png']), 'type'));
    }

    #[DataProvider('invalidPngs')]
    public function test_rejects_malformed_or_disallowed_png(string $png, string $code = 'signature_image_invalid', int $status = 422): void
    {
        try {
            (new PngStructureValidator)->inspect($png);
            $this->fail('The invalid PNG was accepted.');
        } catch (ApiProblemException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
        }
    }

    public static function invalidPngs(): iterable
    {
        $header = Png::header();
        $data = Png::chunk('IDAT', 'compressed-stream');
        $end = Png::chunk('IEND');
        $palette = Png::chunk('PLTE', "\xff\0\0");
        $indexed = Png::header(1, 1, 8, 3);
        yield 'empty' => ['', 'signature_format_unsupported'];
        yield 'wrong signature' => ['GIF89a00', 'signature_format_unsupported'];
        yield 'signature without chunks' => [Png::MAGIC];
        yield 'truncated chunk header' => [Png::MAGIC."\0\0\0"];
        yield 'truncated chunk payload' => [substr(Png::rgba(), 0, -3)];
        yield 'declared chunk larger than file' => [Png::MAGIC.pack('N', 1000).'IHDR'.str_repeat("\0", 20)];
        yield 'forbidden chunk length high bit' => [Png::MAGIC.pack('N', 0x80000000).'IHDR'.str_repeat("\0", 20)];
        $corrupt = Png::rgba();
        $corrupt[29] = chr(ord($corrupt[29]) ^ 1);
        yield 'CRC mismatch' => [$corrupt];
        yield 'nonletter chunk type' => [Png::png($header, Png::chunk('t3Xt'), $data, $end)];
        yield 'reserved lowercase bit' => [Png::png($header, Png::chunk('texT'), $data, $end)];
        yield 'unknown critical' => [Png::png($header, Png::chunk('ABCD'), $data, $end), 'signature_format_unsupported'];
        foreach (['acTL', 'fcTL', 'fdAT'] as $apng) {
            yield "APNG $apng" => [Png::png($header, Png::chunk($apng, str_repeat("\0", 8)), $data, $end), 'signature_format_unsupported'];
        }
        yield 'IHDR not first' => [Png::png(Png::chunk('tEXt', "Key\0value"), $header, $data, $end)];
        yield 'duplicate IHDR' => [Png::png($header, $header, $data, $end)];
        yield 'wrong IHDR length' => [Png::png(Png::chunk('IHDR', str_repeat("\0", 12)), $data, $end)];
        yield 'missing IEND' => [Png::png($header, $data)];
        yield 'duplicate IEND' => [Png::png($header, $data, $end, $end)];
        yield 'IEND payload' => [Png::png($header, $data, Png::chunk('IEND', 'x'))];
        yield 'IEND before data' => [Png::png($header, $end)];
        yield 'trailing payload' => [Png::rgba().'<?php payload ?>'];
        yield 'empty IDAT only' => [Png::png($header, Png::chunk('IDAT'), $end)];
        yield 'interrupted IDAT stream' => [Png::png($header, $data, Png::chunk('tEXt', "Key\0value"), $data, $end)];
        foreach ([[0, 1], [1, 0], [2049, 1], [1, 1025], [2048, 513]] as [$width, $height]) {
            yield "invalid dimensions $width x $height" => [Png::png(Png::header($width, $height), $data, $end), 'signature_image_limits_exceeded'];
        }
        foreach ([[6, 1], [2, 4], [3, 16], [4, 4], [1, 8], [5, 8], [7, 8], [0, 3]] as [$color, $depth]) {
            yield "illegal color $color depth $depth" => [Png::png(Png::header(1, 1, $depth, $color), $data, $end)];
        }
        yield 'compression method' => [Png::png(Png::header(compression: 1), $data, $end)];
        yield 'filter method' => [Png::png(Png::header(filter: 1), $data, $end)];
        yield 'interlace method' => [Png::png(Png::header(interlace: 2), $data, $end)];
        yield 'indexed palette missing' => [Png::png($indexed, $data, $end)];
        yield 'duplicate palette' => [Png::png($indexed, $palette, $palette, $data, $end)];
        yield 'palette after IDAT' => [Png::png($header, $data, $palette, $end)];
        yield 'empty palette' => [Png::png($indexed, Png::chunk('PLTE'), $data, $end)];
        yield 'palette incomplete entry' => [Png::png($indexed, Png::chunk('PLTE', 'ab'), $data, $end)];
        yield 'palette over 256 entries' => [Png::png($indexed, Png::chunk('PLTE', str_repeat('a', 771)), $data, $end)];
        yield 'palette exceeds bit depth' => [Png::png(Png::header(1, 1, 1, 3), Png::chunk('PLTE', str_repeat('a', 9)), $data, $end)];
        foreach ([0, 4] as $color) {
            yield "grayscale $color palette" => [Png::png(Png::header(1, 1, 8, $color), $palette, $data, $end)];
        }
        yield 'indexed tRNS before palette' => [Png::png($indexed, Png::chunk('tRNS', "\0"), $palette, $data, $end)];
        yield 'indexed tRNS longer than palette' => [Png::png($indexed, $palette, Png::chunk('tRNS', "\0\0"), $data, $end)];
        yield 'indexed empty tRNS' => [Png::png($indexed, $palette, Png::chunk('tRNS'), $data, $end)];
        yield 'duplicate tRNS' => [Png::png($indexed, $palette, Png::chunk('tRNS', "\0"), Png::chunk('tRNS', "\0"), $data, $end)];
        yield 'tRNS after IDAT' => [Png::png($indexed, $palette, $data, Png::chunk('tRNS', "\0"), $end)];
        foreach ([4, 6] as $color) {
            yield "alpha color $color tRNS" => [Png::png(Png::header(1, 1, 8, $color), Png::chunk('tRNS', "\0\0"), $data, $end)];
        }
        yield 'gray wrong tRNS length' => [Png::png(Png::header(1, 1, 8, 0), Png::chunk('tRNS', "\0"), $data, $end)];
        yield 'gray tRNS sample out of range' => [Png::png(Png::header(1, 1, 8, 0), Png::chunk('tRNS', "\1\0"), $data, $end)];
        yield 'RGB wrong tRNS length' => [Png::png(Png::header(1, 1, 8, 2), Png::chunk('tRNS', "\0\0"), $data, $end)];
        yield 'RGB tRNS sample out of range' => [Png::png(Png::header(1, 1, 8, 2), Png::chunk('tRNS', "\0\0\0\0\1\0"), $data, $end)];
        yield 'palette after tRNS' => [Png::png(Png::header(1, 1, 8, 2), Png::chunk('tRNS', str_repeat("\0", 6)), $palette, $data, $end)];
        yield 'ancillary limit exceeded' => [Png::rgba(Png::chunk('vpAg', str_repeat('x', 65525))), 'signature_image_limits_exceeded'];
        yield 'chunk count exceeded' => [Png::png($header, str_repeat(Png::chunk('IDAT', 'x'), 1023), $end), 'signature_image_limits_exceeded'];
        yield 'upload limit exceeded' => [Png::png($header, Png::chunk('IDAT', str_repeat('x', 2097152 - 56)), $end), 'signature_upload_too_large', 413];
    }

    public function test_exact_upload_chunk_ancillary_and_dimension_limits_are_accepted(): void
    {
        $validator = new PngStructureValidator;
        $large = Png::png(Png::header(2048, 512), Png::chunk('IDAT', str_repeat('x', 2097152 - 57)), Png::chunk('IEND'));
        $this->assertSame(2097152, strlen($large));
        $this->assertSame(2048, $validator->inspect($large)['width']);
        $this->assertSame(1024, $validator->inspect(Png::png(Png::header(1024, 1024), Png::chunk('IDAT', 'x'), Png::chunk('IEND')))['height']);
        $this->assertSame(Png::rgba(), $validator->inspect(Png::rgba(Png::chunk('vpAg', str_repeat('x', 65524))))['decoder_png']);
        $many = Png::png(Png::header(), str_repeat(Png::chunk('IDAT', 'x'), 1022), Png::chunk('IEND'));
        $this->assertSame($many, $validator->inspect($many)['decoder_png']);
    }

    public function test_normalized_form_requires_noninterlaced_eight_bit_rgba_without_metadata(): void
    {
        $validator = new PngStructureValidator;
        $this->assertSame(['width' => 2, 'height' => 2], $validator->validateNormalized(Png::rgba()));
        $this->assertSame(Png::rgba(), $validator->stripEncodedAncillary(Png::rgba(Png::chunk('pHYs', pack('NNC', 3780, 3780, 1)))));
        foreach ([Png::indexed(), Png::rgba(Png::chunk('tEXt', "Key\0value")), Png::png(Png::header(depth: 16), Png::chunk('IDAT', 'x'), Png::chunk('IEND')), Png::png(Png::header(interlace: 1), Png::chunk('IDAT', 'x'), Png::chunk('IEND'))] as $png) {
            try {
                $validator->validateNormalized($png);
                $this->fail('A noncanonical stored PNG was accepted.');
            } catch (ApiProblemException $exception) {
                $this->assertSame('signature_image_invalid', $exception->errorCode);
            }
        }
    }
}
