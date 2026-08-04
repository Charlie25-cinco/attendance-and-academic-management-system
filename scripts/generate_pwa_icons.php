<?php

$root = dirname(__DIR__);
$sourcePath = $root . '/assets/images/bshs-logo.jpg';
$outputDir = $root . '/assets/images';

if (!extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required to generate PWA icons.\n");
    exit(1);
}

if (!is_file($sourcePath)) {
    fwrite(STDERR, "Source logo not found: {$sourcePath}\n");
    exit(1);
}

$source = imagecreatefromjpeg($sourcePath);
if (!$source) {
    fwrite(STDERR, "Unable to read source logo: {$sourcePath}\n");
    exit(1);
}

function iconColor($image, string $hex): int
{
    $hex = ltrim($hex, '#');
    return imagecolorallocate(
        $image,
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    );
}

function drawGradient($image, int $size): void
{
    for ($y = 0; $y < $size; $y++) {
        $t = $y / max(1, $size - 1);
        $r = (int)(15 + (31 - 15) * $t);
        $g = (int)(54 + (111 - 54) * $t);
        $b = (int)(95 + (130 - 95) * $t);
        imageline($image, 0, $y, $size, $y, imagecolorallocate($image, $r, $g, $b));
    }
}

function drawCircle($image, int $cx, int $cy, int $diameter, int $color): void
{
    imagefilledellipse($image, $cx, $cy, $diameter, $diameter, $color);
}

function createCircularLogo($source, int $diameter)
{
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $crop = min($srcW, $srcH);
    $srcX = (int)(($srcW - $crop) / 2);
    $srcY = (int)(($srcH - $crop) / 2);

    $logo = imagecreatetruecolor($diameter, $diameter);
    imagealphablending($logo, false);
    imagesavealpha($logo, true);
    $transparent = imagecolorallocatealpha($logo, 0, 0, 0, 127);
    imagefill($logo, 0, 0, $transparent);
    imagecopyresampled($logo, $source, 0, 0, $srcX, $srcY, $diameter, $diameter, $crop, $crop);

    $mask = imagecreatetruecolor($diameter, $diameter);
    $black = imagecolorallocate($mask, 0, 0, 0);
    $white = imagecolorallocate($mask, 255, 255, 255);
    imagefill($mask, 0, 0, $black);
    imagefilledellipse($mask, (int)($diameter / 2), (int)($diameter / 2), $diameter, $diameter, $white);

    for ($x = 0; $x < $diameter; $x++) {
        for ($y = 0; $y < $diameter; $y++) {
            $maskColor = imagecolorat($mask, $x, $y) & 0xFF;
            if ($maskColor < 128) {
                imagesetpixel($logo, $x, $y, $transparent);
            }
        }
    }

    imagedestroy($mask);
    imagealphablending($logo, true);
    return $logo;
}

function renderIcon($source, int $size, string $path, bool $maskable = false): void
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, true);
    imagesavealpha($image, true);
    drawGradient($image, $size);

    $center = (int)($size / 2);
    $sealDiameter = (int)round($size * ($maskable ? 0.58 : 0.68));
    $haloDiameter = (int)round($sealDiameter * 1.15);
    $goldDiameter = (int)round($sealDiameter * 1.06);

    $shadow = imagecolorallocatealpha($image, 4, 15, 33, 72);
    drawCircle($image, $center, $center + (int)round($size * 0.035), $haloDiameter, $shadow);
    drawCircle($image, $center, $center, $haloDiameter, iconColor($image, '#e5f2ff'));
    drawCircle($image, $center, $center, $goldDiameter, iconColor($image, '#facc15'));

    $logo = createCircularLogo($source, $sealDiameter);
    imagecopy(
        $image,
        $logo,
        $center - (int)($sealDiameter / 2),
        $center - (int)($sealDiameter / 2),
        0,
        0,
        $sealDiameter,
        $sealDiameter
    );
    imagedestroy($logo);

    imagepng($image, $path, 9);
    imagedestroy($image);
}

renderIcon($source, 192, $outputDir . '/icon-192.png');
renderIcon($source, 512, $outputDir . '/icon-512.png');
renderIcon($source, 512, $outputDir . '/icon-maskable-512.png', true);
renderIcon($source, 180, $outputDir . '/apple-touch-icon.png');
renderIcon($source, 32, $outputDir . '/favicon.png');
renderIcon($source, 16, $outputDir . '/favicon-16.png');

imagedestroy($source);
echo "PWA and browser favicons generated successfully.\n";
