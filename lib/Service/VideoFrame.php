<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;

/**
 * Extract single full resolution frames from videos.
 */
class VideoFrame
{
    public const FOLDER = 'Video frames';

    private const TIMEOUT = 60000;

    /** HDR (PQ/HLG) to SDR; zscale supplies linear light (same chain as go-vod) */
    private const TONEMAP = 'zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,'
        .'tonemap=hable,zscale=t=bt709:m=bt709:r=pc,format=yuvj420p';

    /**
     * Write the frame at the given time to a JPEG file.
     *
     * @throws \Exception if no frame could be extracted
     */
    public static function extract(string $video, float $time, string $out): void
    {
        $ffmpeg = BinExt::detectFFmpeg() ?? throw new \Exception('ffmpeg not found');

        // Fall back to the untouched frame if zscale is unavailable
        if (self::isHdr($video) && self::run(self::args($ffmpeg, $video, $time, $out, self::TONEMAP), $out)) {
            return;
        }

        if (!self::run(self::args($ffmpeg, $video, $time, $out), $out)) {
            throw new \Exception('Could not extract video frame');
        }
    }

    /**
     * ffmpeg arguments for one high quality JPEG frame.
     * Seeking before the input is frame accurate when decoding.
     *
     * @return string[]
     */
    public static function args(string $ffmpeg, string $video, float $time, string $out, ?string $filter = null): array
    {
        return [
            $ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', \sprintf('%.6F', $time), '-i', $video,
            '-map', '0:v:0', '-frames:v', '1',
            ...(null !== $filter ? ['-vf', $filter] : []),
            '-qmin', '1', '-q:v', '1', '-f', 'image2', $out,
        ];
    }

    /**
     * EXIF fields placing the frame on the video's timeline.
     *
     * @param int                  $datetaken local date of the video as a UTC timestamp
     * @param array<string, mixed> $exif      indexed EXIF of the video
     *
     * @return array<string, mixed>
     */
    public static function exif(int $datetaken, array $exif, float $time): array
    {
        $ms = (int) round($time * 1000);
        $local = gmdate('Y:m:d H:i:s', $datetaken + intdiv($ms, 1000));
        $subsec = \sprintf('%03d', $ms % 1000);

        $offset = $datetaken - (int) ($exif['DateTimeEpoch'] ?? $datetaken);
        $tz = \sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));

        $data = [
            'DateTimeOriginal' => $local,
            'CreateDate' => $local,
            'SubSecTimeOriginal' => $subsec,
            'SubSecTimeDigitized' => $subsec,
            'OffsetTimeOriginal' => $tz,
            'OffsetTimeDigitized' => $tz,
        ];

        foreach (['Make', 'Model'] as $key) {
            if (!empty($exif[$key])) {
                $data[$key] = $exif[$key];
            }
        }

        $lat = $exif['GPSLatitude'] ?? null;
        $lon = $exif['GPSLongitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) {
            $data['GPSLatitude'] = abs((float) $lat);
            $data['GPSLongitude'] = abs((float) $lon);
            $data['GPSLatitudeRef'] = $lat >= 0 ? 'N' : 'S';
            $data['GPSLongitudeRef'] = $lon >= 0 ? 'E' : 'W';
        }

        return $data;
    }

    /**
     * File name of a frame, e.g. VID_001_frame_01-02-345.jpg for 1:02.345.
     */
    public static function name(string $video, float $time): string
    {
        $ms = (int) round($time * 1000);

        return \sprintf(
            '%s_frame_%02d-%02d-%03d.jpg',
            pathinfo($video, PATHINFO_FILENAME),
            intdiv($ms, 60000),
            intdiv($ms % 60000, 1000),
            $ms % 1000,
        );
    }

    private static function isHdr(string $video): bool
    {
        $ffprobe = SystemConfig::get('memories.vod.ffprobe');
        if (empty($ffprobe)) {
            return false;
        }

        $out = Util::execSafe([
            $ffprobe, '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=color_transfer', '-of', 'csv=p=0', $video,
        ], self::TIMEOUT) ?? '';

        return \in_array(trim($out), ['smpte2084', 'arib-std-b67'], true);
    }

    /** @param string[] $cmd */
    private static function run(array $cmd, string $out): bool
    {
        @unlink($out);

        try {
            Util::execSafe($cmd, self::TIMEOUT);
        } catch (\Exception) {
            return false;
        }
        clearstatcache(true, $out);

        return is_file($out) && filesize($out) > 0;
    }
}
