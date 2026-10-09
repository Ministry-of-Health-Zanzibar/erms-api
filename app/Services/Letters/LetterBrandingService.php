<?php

namespace App\Services\Letters;

use App\Models\LetterBrandingSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LetterBrandingService
{
    private const DEFAULT_SIGNATURE = 'images/letters/sign_dgs.png';
    private const DEFAULT_STAMP = 'images/letters/stamp.png';
    private const UPLOAD_DIRECTORY = 'uploads/letter-branding';

    /**
     * Return the effective assets used by every backend-generated letter.
     *
     * The database is intentionally optional here. This keeps the PDF renderer
     * able to fall back to the bundled government assets during deployment
     * before the branding migration has been applied.
     */
    public function effectiveAssets(): array
    {
        $setting = $this->currentSetting();
        $signaturePath = $setting?->signature_path ?: self::DEFAULT_SIGNATURE;
        $stampPath = $setting?->stamp_path ?: self::DEFAULT_STAMP;
        // Fingerprint the files as well as their paths, so even replacing an
        // asset in place immediately invalidates the prepared branding.
        $cacheKey = 'letter-branding:v2:' . hash('sha256', json_encode([
            $signaturePath, $this->assetFingerprint($signaturePath),
            $stampPath, $this->assetFingerprint($stampPath),
        ]));
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // A cache outage must not prevent an official letter being prepared.
        }
        $signatureColor = $this->dominantInkColor($signaturePath);

        $assets = [
            'signatureData' => $this->dataUri($signaturePath),
            // Uploaded and bundled stamps may include a scanned paper
            // background. Remove that background before embedding the stamp so
            // it can sit over the DG signature and signatory text cleanly.
            'stampData' => $this->transparentStampDataUri($stampPath, $signatureColor),
            'signaturePath' => $signaturePath,
            'stampPath' => $stampPath,
        ];
        try {
            Cache::put($cacheKey, $assets, now()->addDay());
        } catch (\Throwable) {
            // Return the freshly prepared assets when caching is unavailable.
        }

        return $assets;
    }

    public function summary(): array
    {
        $setting = $this->currentSetting();
        $assets = $this->effectiveAssets();

        return [
            'signature_path' => $assets['signaturePath'],
            'stamp_path' => $assets['stampPath'],
            'signature_url' => $this->publicUrl($assets['signaturePath']),
            'stamp_url' => $this->publicUrl($assets['stampPath']),
            'using_default_signature' => !$setting?->signature_path,
            'using_default_stamp' => !$setting?->stamp_path,
            'updated_at' => $setting?->updated_at,
            'updated_by' => $setting?->updatedBy,
        ];
    }

    public function update(?UploadedFile $signature, ?UploadedFile $stamp, User $user): array
    {
        $paths = [];

        if ($signature) {
            $paths['signature_path'] = $this->store($signature, 'signature');
        }

        if ($stamp) {
            $paths['stamp_path'] = $this->store($stamp, 'stamp');
        }

        DB::transaction(function () use ($paths, $user): void {
            $setting = $this->currentSetting() ?: new LetterBrandingSetting();
            $setting->fill($paths);
            $setting->updated_by = $user->getKey();
            $setting->save();
        });

        return $this->summary();
    }

    public function reset(User $user): array
    {
        $setting = $this->currentSetting() ?: new LetterBrandingSetting();
        $setting->forceFill([
            'signature_path' => null,
            'stamp_path' => null,
            'updated_by' => $user->getKey(),
        ])->save();

        return $this->summary();
    }

    private function currentSetting(): ?LetterBrandingSetting
    {
        try {
            return LetterBrandingSetting::with('updatedBy:id,first_name,middle_name,last_name,email')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(UploadedFile $file, string $type): string
    {
        $directory = public_path(self::UPLOAD_DIRECTORY);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $extension = strtolower($file->extension() ?: 'png');
        $name = $type . '-' . Str::uuid() . '.' . $extension;
        $file->move($directory, $name);

        return self::UPLOAD_DIRECTORY . '/' . $name;
    }

    private function publicUrl(string $path): string
    {
        return asset(ltrim($path, '/'));
    }

    private function dataUri(string $path): string
    {
        $absolutePath = public_path(ltrim($path, '/'));

        if (!is_file($absolutePath)) {
            return '';
        }

        $mime = mime_content_type($absolutePath) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($absolutePath));
    }

    private function assetFingerprint(string $path): string
    {
        $absolutePath = public_path(ltrim($path, '/'));

        return is_file($absolutePath) ? (hash_file('sha256', $absolutePath) ?: 'unreadable') : 'missing';
    }

    private function dominantInkColor(string $path): array
    {
        $absolutePath = public_path(ltrim($path, '/'));
        $contents = is_file($absolutePath) ? file_get_contents($absolutePath) : false;

        if (!$contents || !function_exists('imagecreatefromstring')) {
            return [0, 15, 222];
        }

        $source = @imagecreatefromstring($contents);

        if (!$source) {
            return [0, 15, 222];
        }

        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $counts = [];
        for ($y = 0; $y < imagesy($source); $y++) {
            for ($x = 0; $x < imagesx($source); $x++) {
                $pixel = imagecolorat($source, $x, $y);
                $alpha = ($pixel & 0x7f000000) >> 24;
                $red = ($pixel >> 16) & 0xff;
                $green = ($pixel >> 8) & 0xff;
                $blue = $pixel & 0xff;

                // Ignore transparent pixels and the near-white canvas around
                // the signature. The most frequent remaining color is the
                // signature's actual ink color.
                if ($alpha >= 96 || (($red + $green + $blue) / 3) >= 180) {
                    continue;
                }

                $rgb = ($red << 16) | ($green << 8) | $blue;
                $counts[$rgb] = ($counts[$rgb] ?? 0) + 1;
            }
        }

        imagedestroy($source);

        if (!$counts) {
            return [0, 15, 222];
        }

        arsort($counts);
        $rgb = (int) array_key_first($counts);

        return [($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff];
    }

    private function transparentStampDataUri(string $path, array $inkColor): string
    {
        $absolutePath = public_path(ltrim($path, '/'));
        $contents = is_file($absolutePath) ? file_get_contents($absolutePath) : false;

        if (!$contents || !function_exists('imagecreatefromstring')) {
            return $this->dataUri($path);
        }

        $source = @imagecreatefromstring($contents);

        if (!$source) {
            return $this->dataUri($path);
        }

        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $output = imagecreatetruecolor($width, $height);

        imagealphablending($output, false);
        imagesavealpha($output, true);
        $transparent = imagecolorallocatealpha($output, 255, 255, 255, 127);
        imagefill($output, 0, 0, $transparent);
        // Only 128 alpha values are possible. Reuse them instead of allocating
        // the same ink color again for every pixel in the scanned stamp.
        $colors = [];
        for ($alpha = 0; $alpha <= 127; $alpha++) {
            $colors[$alpha] = imagecolorallocatealpha($output, $inkColor[0], $inkColor[1], $inkColor[2], $alpha);
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($source, $x, $y);
                $red = ($pixel >> 16) & 0xff;
                $green = ($pixel >> 8) & 0xff;
                $blue = $pixel & 0xff;
                $sourceAlpha = ($pixel & 0x7f000000) >> 24;
                $average = ($red + $green + $blue) / 3;
                $spread = max($red, $green, $blue) - min($red, $green, $blue);

                // The scan background is near-white and low-saturation. Keep
                // the dark/blue ink, but make paper and paper noise invisible.
                $alpha = $sourceAlpha;
                if ($sourceAlpha < 127 && $average > 205 && $spread < 55) {
                    $alpha = 127;
                } elseif ($sourceAlpha < 127) {
                    // Preserve anti-aliased ink edges while replacing every
                    // visible stamp color with the DG signature blue.
                    $inkStrength = max(0, min(1, (220 - $average) / 180));
                    $alpha = max($sourceAlpha, 127 - (int) round($inkStrength * 127));
                }

                imagesetpixel($output, $x, $y, $colors[$alpha]);
            }
        }

        imagealphablending($output, false);
        ob_start();
        imagepng($output);
        $png = ob_get_clean();
        imagedestroy($source);
        imagedestroy($output);

        return is_string($png) && $png !== ''
            ? 'data:image/png;base64,' . base64_encode($png)
            : $this->dataUri($path);
    }
}
