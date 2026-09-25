<?php

namespace Tests\Unit\Signatures;

use App\Exceptions\ApiProblemException;
use App\Services\Signatures\GdSignatureImageNormalizer;
use App\Services\Signatures\PngStructureValidator;
use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SignaturePngFixture as Png;

class GdSignatureImageNormalizerTest extends TestCase
{
    private static string $privateRoot;

    private string $directory;

    public static function setUpBeforeClass(): void
    {
        self::$privateRoot = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'.foundation-runtime'.DIRECTORY_SEPARATOR.'phase6b2-20260915'.DIRECTORY_SEPARATOR.'normalizer-private-tests';
        // Provisioning this dedicated private parent belongs to the runtime/ACL gate.
        // Image unit tests only create isolated children within the existing parent.
        if (! is_dir(self::$privateRoot) || ! is_readable(self::$privateRoot) || ! is_writable(self::$privateRoot)) {
            throw new \RuntimeException('Dedicated normalizer test directory is unavailable.');
        }
        if (realpath(self::$privateRoot) !== self::$privateRoot || is_link(self::$privateRoot)) {
            throw new \RuntimeException('Dedicated normalizer test directory is not canonical.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = self::$privateRoot.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16));
        $this->assertTrue(mkdir($this->directory, 0700));
        $this->assertSame($this->directory, realpath($this->directory));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (new \DirectoryIterator($this->directory) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if ($entry->isLink() || ! $entry->isFile() || dirname((string) $entry->getRealPath()) !== $this->directory) {
                    throw new \RuntimeException('Refusing cleanup of an unowned normalizer test entry.');
                }
                unlink($entry->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_real_decode_new_canvas_alpha_canonicalization_reencode_and_readback(): void
    {
        $source = Png::rgba(Png::chunk('tEXt', "Comment\0private metadata"));
        $input = $this->input($source);
        $normalizer = $this->normalizer();
        $result = $normalizer->normalize($input, $this->directory);

        $this->assertSame(2, count($normalizer->decodedInputs));
        $this->assertSame(Png::rgba(), $normalizer->decodedInputs[0]);
        $this->assertSame($result, $normalizer->decodedInputs[1]);
        $this->assertSame(1, $normalizer->encodeCalls);
        $this->assertTrue($normalizer->canvasWasNew);
        $this->assertNotSame($source, $result);
        $this->assertSame($source, file_get_contents($input));
        $this->assertNormalizedFraming($result);
        $this->assertSame(['width' => 2, 'height' => 2], (new PngStructureValidator)->validateNormalized($result));
        $image = imagecreatefromstring($result);
        $this->assertInstanceOf(GdImage::class, $image);
        $this->assertTrue(imageistruecolor($image));
        $this->assertSame(['red' => 255, 'green' => 0, 'blue' => 0, 'alpha' => 0], $this->pixel($image, 0, 0));
        $this->assertSame(['red' => 0, 'green' => 255, 'blue' => 0, 'alpha' => 63], $this->pixel($image, 1, 0));
        $this->assertSame(['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 127], $this->pixel($image, 0, 1));
        $this->assertSame(['red' => 0, 'green' => 0, 'blue' => 255, 'alpha' => 0], $this->pixel($image, 1, 1));
        $this->assertOnlyOriginalRemains();
    }

    public function test_palette_transparency_becomes_canonical_truecolor_alpha(): void
    {
        $result = $this->normalizer()->normalize($this->input(Png::indexed()), $this->directory);
        $image = imagecreatefromstring($result);

        $this->assertNormalizedFraming($result);
        $this->assertTrue(imageistruecolor($image));
        $this->assertSame(['red' => 255, 'green' => 0, 'blue' => 0, 'alpha' => 0], $this->pixel($image, 0, 0));
        $this->assertSame(['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 127], $this->pixel($image, 1, 0));
        $this->assertOnlyOriginalRemains();
    }

    #[DataProvider('decodableFormats')]
    public function test_supported_source_formats_are_decoded_and_canonicalized(string $png, array $expectedPixel): void
    {
        $result = $this->normalizer()->normalize($this->input($png), $this->directory);
        $image = imagecreatefromstring($result);

        $this->assertInstanceOf(GdImage::class, $image);
        $this->assertTrue(imageistruecolor($image));
        $this->assertSame(['width' => 1, 'height' => 1], (new PngStructureValidator)->validateNormalized($result));
        $this->assertSame($expectedPixel, $this->pixel($image, 0, 0));
        $this->assertNormalizedFraming($result);
        $this->assertOnlyOriginalRemains();
    }

    public static function decodableFormats(): iterable
    {
        foreach ([
            [0, 1, "\x80", [255, 255, 255, 0]],
            [0, 8, "\x80", [128, 128, 128, 0]],
            [0, 16, "\x80\0", [128, 128, 128, 0]],
            [2, 8, "\x80\x40\x20", [128, 64, 32, 0]],
            [2, 16, "\x80\0\x40\0\x20\0", [128, 64, 32, 0]],
            [4, 8, "\x80\x80", [128, 128, 128, 63]],
            [4, 16, "\x80\0\x80\0", [128, 128, 128, 63]],
            [6, 16, "\x80\0\x40\0\x20\0\x80\0", [128, 64, 32, 63]],
        ] as [$color, $depth, $pixel, $expected]) {
            yield "color-$color-depth-$depth" => [
                Png::png(Png::header(1, 1, $depth, $color), Png::chunk('IDAT', gzcompress("\0".$pixel)), Png::chunk('IEND')),
                array_combine(['red', 'green', 'blue', 'alpha'], $expected),
            ];
        }
    }

    #[DataProvider('transparentFormats')]
    public function test_grayscale_and_rgb_transparency_keys_preserve_opaque_pixels(string $png, array $opaquePixel): void
    {
        $result = $this->normalizer()->normalize($this->input($png), $this->directory);
        $image = imagecreatefromstring($result);

        $this->assertInstanceOf(GdImage::class, $image);
        $this->assertTrue(imageistruecolor($image));
        $this->assertSame($opaquePixel, $this->pixel($image, 0, 0));
        $this->assertSame(['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 127], $this->pixel($image, 1, 0));
        $this->assertNormalizedFraming($result);
        $this->assertOnlyOriginalRemains();
    }

    public static function transparentFormats(): iterable
    {
        yield 'grayscale tRNS' => [
            Png::png(Png::header(2, 1, 8, 0), Png::chunk('tRNS', pack('n', 128)), Png::chunk('IDAT', gzcompress("\0\x40\x80")), Png::chunk('IEND')),
            ['red' => 64, 'green' => 64, 'blue' => 64, 'alpha' => 0],
        ];
        yield 'RGB tRNS' => [
            Png::png(Png::header(2, 1, 8, 2), Png::chunk('tRNS', pack('nnn', 87, 163, 225)), Png::chunk('IDAT', gzcompress("\0\x80\x40\x20\x57\xa3\xe1")), Png::chunk('IEND')),
            ['red' => 128, 'green' => 64, 'blue' => 32, 'alpha' => 0],
        ];
    }

    public function test_all_seven_adam7_passes_preserve_pixels_and_produce_noninterlaced_output(): void
    {
        $normalizer = $this->normalizer();
        $source = Png::adam7Rgba();
        $result = $normalizer->normalize($this->input($source), $this->directory);
        $image = imagecreatefromstring($result);

        $this->assertInstanceOf(GdImage::class, $image);
        $this->assertTrue(imageistruecolor($image));
        $this->assertTrue($normalizer->canvasWasNew);
        $this->assertSame([$source, $result], $normalizer->decodedInputs);
        $this->assertSame(['width' => 8, 'height' => 8], (new PngStructureValidator)->validateNormalized($result));
        $this->assertNormalizedFraming($result);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $alpha = [0, 63, 127, 95][($x + $y) % 4];
                $this->assertSame([
                    'red' => $alpha === 127 ? 0 : $x * 31,
                    'green' => $alpha === 127 ? 0 : $y * 29,
                    'blue' => $alpha === 127 ? 0 : ($x + $y) * 15,
                    'alpha' => $alpha,
                ], $this->pixel($image, $x, $y), "Adam7 pixel ($x, $y)");
            }
        }
        $this->assertOnlyOriginalRemains();
    }

    public function test_decoder_never_receives_untrusted_compressed_ancillary_contents(): void
    {
        $metadata = Png::chunk('iCCP', "Profile\0\0".str_repeat('not-a-profile', 200)).Png::chunk('zTXt', "Comment\0\0".str_repeat('not-deflate', 200));
        $normalizer = $this->normalizer();
        $result = $normalizer->normalize($this->input(Png::rgba($metadata)), $this->directory);

        $this->assertSame(Png::rgba(), $normalizer->decodedInputs[0]);
        $this->assertStringNotContainsString('not-a-profile', $result);
        $this->assertStringNotContainsString('not-deflate', $result);
        $this->assertOnlyOriginalRemains();
    }

    public function test_structural_rejection_happens_before_gd_decode_or_scratch_creation(): void
    {
        $normalizer = $this->normalizer();
        $this->assertProblem(fn () => $normalizer->normalize($this->input(Png::rgba(Png::chunk('acTL', str_repeat("\0", 8)))), $this->directory), 'signature_format_unsupported', 422);

        $this->assertSame([], $normalizer->decodedInputs);
        $this->assertSame(0, $normalizer->encodeCalls);
        $this->assertOnlyOriginalRemains();
    }

    #[DataProvider('invalidCompressedPixels')]
    public function test_rejects_invalid_or_extra_compressed_pixels_before_decode(string $compressed): void
    {
        $normalizer = $this->normalizer();
        $input = $this->input(Png::png(Png::header(1, 1), Png::chunk('IDAT', $compressed), Png::chunk('IEND')));
        $this->assertProblem(fn () => $normalizer->normalize($input, $this->directory), 'signature_image_invalid', 422);

        $this->assertSame([], $normalizer->decodedInputs);
        $this->assertOnlyOriginalRemains();
    }

    public static function invalidCompressedPixels(): iterable
    {
        $valid = gzcompress("\0\xff\0\0\xff");
        yield 'invalid deflate' => ['not a zlib stream'];
        yield 'truncated stream' => [substr($valid, 0, -2)];
        yield 'raw trailing data' => [$valid.'hidden payload'];
        yield 'second deflate stream' => [$valid.gzcompress('hidden payload')];
        yield 'short pixel data' => [gzcompress("\0\xff\0\0")];
        yield 'extra pixel data' => [gzcompress("\0\xff\0\0\xff\0")];
        yield 'bounded compressed expansion' => [gzcompress(str_repeat("\0", 2 * 1024 * 1024))];
    }

    public function test_real_gd_decoder_rejects_an_invalid_scanline_filter(): void
    {
        $input = $this->input(Png::png(Png::header(1, 1), Png::chunk('IDAT', gzcompress("\5\xff\0\0\xff")), Png::chunk('IEND')));
        $normalizer = $this->normalizer();
        $this->assertProblem(fn () => $normalizer->normalize($input, $this->directory), 'signature_image_invalid', 422);

        $this->assertCount(1, $normalizer->decodedInputs);
        $this->assertSame(0, $normalizer->encodeCalls);
        $this->assertOnlyOriginalRemains();
    }

    #[DataProvider('injectedFailures')]
    public function test_processing_failures_clean_only_owned_scratch_and_restore_error_handler(string $failure, string $code, int $status): void
    {
        $input = $this->input(Png::rgba());
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'unrelated.keep', 'unrelated request sentinel');
        $handler = static fn (): bool => false;
        set_error_handler($handler);
        try {
            $this->assertProblem(fn () => $this->normalizer($failure)->normalize($input, $this->directory), $code, $status);
            $previous = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            $this->assertSame($handler, $previous);
        } finally {
            restore_error_handler();
        }

        $entries = array_values(array_diff(scandir($this->directory), ['.', '..']));
        $this->assertSame(['original.upload', 'unrelated.keep'], $entries);
        $this->assertSame('unrelated request sentinel', file_get_contents($this->directory.DIRECTORY_SEPARATOR.'unrelated.keep'));
        $this->assertSame(Png::rgba(), file_get_contents($input));
    }

    public static function injectedFailures(): iterable
    {
        yield 'first decode returns false' => ['decode-first', 'signature_image_invalid', 422];
        yield 'decoder warning is rejected' => ['decode-warning', 'signature_image_invalid', 422];
        yield 'decoder throws' => ['decode-throw', 'signature_asset_unavailable', 503];
        yield 'decoder raises an error' => ['decode-error', 'signature_asset_unavailable', 503];
        yield 'encode returns false' => ['encode-false', 'signature_asset_unavailable', 503];
        yield 'encoder warning is rejected' => ['encode-warning', 'signature_asset_unavailable', 503];
        yield 'encoder throws' => ['encode-throw', 'signature_asset_unavailable', 503];
        yield 'encoder raises an error' => ['encode-error', 'signature_asset_unavailable', 503];
        yield 'readback decode fails' => ['decode-final', 'signature_decoder_unavailable', 503];
        yield 'readback decoder warning is rejected' => ['decode-final-warning', 'signature_decoder_unavailable', 503];
        yield 'readback decoder throws' => ['decode-final-throw', 'signature_asset_unavailable', 503];
    }

    public function test_unavailable_private_working_directory_fails_without_fallback(): void
    {
        $normalizer = $this->normalizer();
        $input = $this->input(Png::rgba());
        $missing = $this->directory.DIRECTORY_SEPARATOR.'missing-private-directory';
        $this->assertProblem(fn () => $normalizer->normalize($input, $missing), 'signature_asset_unavailable', 503);

        $this->assertSame([], $normalizer->decodedInputs);
        $this->assertDirectoryDoesNotExist($missing);
        $this->assertOnlyOriginalRemains();
    }

    public function test_original_above_upload_limit_is_rejected_before_decode(): void
    {
        $normalizer = $this->normalizer();
        $input = $this->input(str_repeat('x', PngStructureValidator::MAX_UPLOAD_BYTES + 1));
        $this->assertProblem(fn () => $normalizer->normalize($input, $this->directory), 'signature_upload_too_large', 413);

        $this->assertSame([], $normalizer->decodedInputs);
        $this->assertOnlyOriginalRemains();
    }

    private function input(string $bytes): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'original.upload';
        $stream = fopen($path, 'x+b');
        $this->assertIsResource($stream);
        $this->assertSame(strlen($bytes), fwrite($stream, $bytes));
        fclose($stream);

        return $path;
    }

    private function assertOnlyOriginalRemains(): void
    {
        $this->assertSame(['original.upload'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    private function assertNormalizedFraming(string $png): void
    {
        $chunks = Png::chunks($png);
        $this->assertSame(['IHDR', 'IDAT', 'IEND'], array_column($chunks, 'type'));
        $this->assertSame(8, ord($chunks[0]['data'][8]), 'Normalized depth must be eight bits.');
        $this->assertSame(6, ord($chunks[0]['data'][9]), 'Normalized color must be truecolor with alpha.');
        $this->assertSame(0, ord($chunks[0]['data'][12]), 'Normalized output must not be interlaced.');
    }

    /** @return array{red:int,green:int,blue:int,alpha:int} */
    private function pixel(GdImage $image, int $x, int $y): array
    {
        return imagecolorsforindex($image, imagecolorat($image, $x, $y));
    }

    private function assertProblem(callable $operation, string $code, int $status): void
    {
        try {
            $operation();
            $this->fail('An invalid image or unavailable processing step was accepted.');
        } catch (ApiProblemException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
            $this->assertStringNotContainsString($this->directory, $exception->getMessage());
            $this->assertStringNotContainsString('private locator', $exception->getMessage());
        }
    }

    private function normalizer(string $failure = ''): GdSignatureImageNormalizer
    {
        return new class(new PngStructureValidator, $failure) extends GdSignatureImageNormalizer
        {
            public array $decodedInputs = [];

            public int $encodeCalls = 0;

            public bool $canvasWasNew = false;

            private ?GdImage $sourceImage = null;

            public function __construct(PngStructureValidator $validator, private readonly string $failure)
            {
                parent::__construct($validator);
            }

            protected function decode(string $path): GdImage|false
            {
                $this->decodedInputs[] = file_get_contents($path);
                if (($this->failure === 'decode-first' && count($this->decodedInputs) === 1) || ($this->failure === 'decode-final' && count($this->decodedInputs) === 2)) {
                    return false;
                }
                if ($this->failure === 'decode-warning' || ($this->failure === 'decode-final-warning' && count($this->decodedInputs) === 2)) {
                    trigger_error('decoder warning with private locator', E_USER_WARNING);
                }
                if ($this->failure === 'decode-throw' || ($this->failure === 'decode-final-throw' && count($this->decodedInputs) === 2)) {
                    throw new \RuntimeException('decoder failure with private locator');
                }
                if ($this->failure === 'decode-error') {
                    throw new \Error('decoder error with private locator');
                }
                $image = parent::decode($path);
                if (count($this->decodedInputs) === 1 && $image instanceof GdImage) {
                    $this->sourceImage = $image;
                }

                return $image;
            }

            protected function encode(GdImage $image, mixed $stream): bool
            {
                $this->encodeCalls++;
                $this->canvasWasNew = $image !== $this->sourceImage;
                if (str_starts_with($this->failure, 'encode-')) {
                    fwrite($stream, 'partial encoded output');
                }
                if ($this->failure === 'encode-false') {
                    return false;
                }
                if ($this->failure === 'encode-warning') {
                    trigger_error('encoder warning with private locator', E_USER_WARNING);
                }
                if ($this->failure === 'encode-throw') {
                    throw new \RuntimeException('encoder failure with private locator');
                }
                if ($this->failure === 'encode-error') {
                    throw new \Error('encoder error with private locator');
                }

                return parent::encode($image, $stream);
            }
        };
    }
}
