<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Exif;
use OCA\Memories\Service\VideoFrame;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\VideoFrame
 */
final class VideoFrameTest extends TestCase
{
    public function testExifPlacesFrameAfterVideo(): void
    {
        // Video at 2023-03-05 18:58:17 local, UTC+05:30
        $local = gmmktime(18, 58, 17, 3, 5, 2023);
        $exif = ['DateTimeEpoch' => $local - 19800, 'Make' => 'samsung', 'Model' => 'SM-G986B'];

        $data = VideoFrame::exif($local, $exif, 62.3456);
        self::assertSame('2023:03:05 18:59:19', $data['DateTimeOriginal']);
        self::assertSame('346', $data['SubSecTimeOriginal']);
        self::assertSame('+05:30', $data['OffsetTimeOriginal']);
        self::assertSame('samsung', $data['Make']);
        self::assertSame('SM-G986B', $data['Model']);
        self::assertArrayNotHasKey('GPSLatitude', $data);

        // Same parse path as the indexer: local date + offset gives the epoch
        $date = Exif::parseExifDate(['SubSecDateTimeOriginal' => "{$data['DateTimeOriginal']}.{$data['SubSecTimeOriginal']}{$data['OffsetTimeOriginal']}"]);
        self::assertSame($exif['DateTimeEpoch'] + 62, $date->getTimestamp());
        self::assertSame($local + 62, Exif::forgetTimezone($date)->getTimestamp());
    }

    public function testExifNegativeOffsetAndGps(): void
    {
        $data = VideoFrame::exif(1000000, ['DateTimeEpoch' => 1000000 + 4.5 * 3600, 'GPSLatitude' => -33.8688, 'GPSLongitude' => 151.2093], 0);
        self::assertSame('-04:30', $data['OffsetTimeOriginal']);
        self::assertSame('000', $data['SubSecTimeOriginal']);
        self::assertSame(33.8688, $data['GPSLatitude']);
        self::assertSame('S', $data['GPSLatitudeRef']);
        self::assertSame(151.2093, $data['GPSLongitude']);
        self::assertSame('E', $data['GPSLongitudeRef']);

        // Unindexed epoch falls back to UTC
        self::assertSame('+00:00', VideoFrame::exif(1000000, [], 1)['OffsetTimeOriginal']);
    }

    public function testName(): void
    {
        self::assertSame('VID_001_frame_01-02-346.jpg', VideoFrame::name('VID_001.mp4', 62.3456));
        self::assertSame('clip.final_frame_00-00-000.jpg', VideoFrame::name('clip.final.MOV', 0));
        self::assertSame('long_frame_125-00-500.jpg', VideoFrame::name('long.mkv', 7500.5));
    }

    public function testArgs(): void
    {
        $args = VideoFrame::args('ffmpeg', '/v.mp4', 1.5, '/o.jpg');
        self::assertSame(['-ss', '1.500000', '-i', '/v.mp4'], \array_slice($args, 5, 4));
        self::assertSame('/o.jpg', end($args));
        self::assertNotContains('-vf', $args);

        $args = VideoFrame::args('ffmpeg', '/v.mp4', 1.5, '/o.jpg', 'tonemap');
        self::assertSame('tonemap', $args[array_search('-vf', $args, true) + 1]);
    }
}
