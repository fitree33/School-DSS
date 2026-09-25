<?php

namespace Tests\Support;

/** Handcrafted PNGs independent of the production framing validator and GD encoder. */
final class SignaturePngFixture
{
    public const MAGIC = "\x89PNG\r\n\x1a\n";

    public static function chunk(string $type, string $data = ''): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    public static function header(int $width = 2, int $height = 2, int $depth = 8, int $color = 6, int $compression = 0, int $filter = 0, int $interlace = 0): string
    {
        return self::chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $color, $compression, $filter, $interlace));
    }

    public static function png(string ...$chunks): string
    {
        return self::MAGIC.implode('', $chunks);
    }

    /** Opaque red, partial-alpha green, invisible colored RGB, and opaque blue. */
    public static function rgba(string $beforeIdat = '', string $afterIdat = ''): string
    {
        return self::png(
            self::header(),
            $beforeIdat,
            self::chunk('IDAT', gzcompress("\0\xff\0\0\xff\0\xff\0\x80\0\x57\xa3\xe1\0\0\0\xff\xff")),
            $afterIdat,
            self::chunk('IEND'),
        );
    }

    public static function indexed(): string
    {
        return self::png(
            self::header(2, 1, 8, 3),
            self::chunk('PLTE', "\xff\0\0\x57\xa3\xe1"),
            self::chunk('tRNS', "\xff\0"),
            self::chunk('IDAT', gzcompress("\0\0\1")),
            self::chunk('IEND'),
        );
    }

    /** Eight-by-eight RGBA with every Adam7 pass populated and varied alpha. */
    public static function adam7Rgba(): string
    {
        // Explicit pass coordinates keep this fixture independent of production pass sizing.
        $passes = [
            [[0], [0]],
            [[4], [0]],
            [[0, 4], [4]],
            [[2, 6], [0, 4]],
            [[0, 2, 4, 6], [2, 6]],
            [[1, 3, 5, 7], [0, 2, 4, 6]],
            [[0, 1, 2, 3, 4, 5, 6, 7], [1, 3, 5, 7]],
        ];
        $scanlines = '';
        foreach ($passes as [$columns, $rows]) {
            foreach ($rows as $y) {
                $scanlines .= "\0";
                foreach ($columns as $x) {
                    $scanlines .= pack('CCCC', $x * 31, $y * 29, ($x + $y) * 15, [255, 128, 0, 64][($x + $y) % 4]);
                }
            }
        }

        return self::png(self::header(8, 8, 8, 6, interlace: 1), self::chunk('IDAT', gzcompress($scanlines)), self::chunk('IEND'));
    }

    /** @return array<int, array{type: string, data: string}> */
    public static function chunks(string $png): array
    {
        $chunks = [];
        for ($offset = 8; $offset < strlen($png);) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $chunks[] = ['type' => substr($png, $offset + 4, 4), 'data' => substr($png, $offset + 8, $length)];
            $offset += $length + 12;
        }

        return $chunks;
    }
}
