<?php

declare(strict_types=1);

/**
 * Class Themify
 * Handles theme management including loading images and caching.
 */
class Themify
{
    /** @var string Theme folder path */
    private string $themePath;

    /** @var array Theme list cache */
    private array $themeList = [];

    /** @var array Allowed image extensions */
    private array $imgExts = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];

    /** @var static Theme list cache */
    private static array $staticThemeList = [];

    /** @var array Runtime cache for images */
    private array $cache = [];

    /** @var static Instance cache */
    private static ?Themify $instance = null;

    /** @var array Predefined common scaling factors */
    private const PRESET_SCALES = [0.5, 1.0, 2.0];

    private function __construct(string $themePath = null)
    {
        $this->themePath = $themePath ?? dirname(__FILE__) . '/../assets/theme';

        if (empty(self::$staticThemeList)) {
            $this->loadThemes();
            self::$staticThemeList = $this->themeList;
        } else {
            $this->themeList = self::$staticThemeList;
        }
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function loadThemes(): array
    {
        $cacheFile = __DIR__ . '/theme_cache.php';

        if (is_file($cacheFile)) {
            $this->themeList = include $cacheFile;
            return $this->themeList;
        }

        $dirIterator = new FilesystemIterator($this->themePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::KEY_AS_FILENAME);

        foreach ($dirIterator as $themeDir) {
            if (!$themeDir->isDir()) {
                continue;
            }

            $theme = $themeDir->getBasename();
            $this->themeList[$theme] = [];
            $this->processThemeDirectory($themeDir->getRealPath(), $theme);
        }

        file_put_contents($cacheFile, '<?php return ' . var_export($this->themeList, true) . ';');

        return $this->themeList;
    }

    private function processThemeDirectory(string $themeDirPath, string $theme): void
    {
        $fileIterator = new DirectoryIterator($themeDirPath);

        foreach ($fileIterator as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $ext = $fileInfo->getExtension();
            if (!in_array('.' . $ext, $this->imgExts, true)) {
                continue;
            }

            $char = $fileInfo->getBasename('.' . $ext);
            $imgPath = $fileInfo->getRealPath();
            $cacheKey = md5($imgPath);

            if (!isset($this->cache[$cacheKey])) {
                $imageSize = getimagesize($imgPath);
                $mime = $this->getMimeType($imgPath);
                $base64 = base64_encode(file_get_contents($imgPath));

                $this->cache[$cacheKey] = [
                    'size' => $imageSize,
                    'data' => "data:$mime;base64,$base64",
                    'preset_scales' => array_reduce(
                        self::PRESET_SCALES,
                        static fn($carry, $scale) => $carry + [ (string)$scale => [
                            'w' => $imageSize[0] * $scale,
                            'h' => $imageSize[1] * $scale
                        ]],
                        []
                    )
                ];
            }

            $cacheItem = $this->cache[$cacheKey];
            $this->themeList[$theme][$char] = [
                'width' => $cacheItem['size'][0],
                'height' => $cacheItem['size'][1],
                'data' => $cacheItem['data'],
                'preset_scales' => $cacheItem['preset_scales']
            ];
        }
    }

    private function getMimeType(string $path): string
    {
        $extMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $extMap[$ext] ?? mime_content_type($path);
    }

    public function getCountImage(array $params): string
    {
        $params = array_merge([
            'count' => 0,
            'theme' => 'moebooru',
            'padding' => 7,
            'prefix' => 1,
            'offset' => 0,
            'align' => 'top',
            'scale' => 1,
            'pixelated' => '1',
            'darkmode' => 'auto',
        ], $params);

        $params['count'] = max(0, intval($params['count']));
        $theme = $this->themeList[$params['theme']] ?? $this->themeList['moebooru'];
        $countStr = str_pad((string)$params['count'], (int)$params['padding'], '0', STR_PAD_LEFT);
        $countArray = str_split($countStr);

        if ($params['prefix'] >= 0) {
            array_unshift($countArray, ...str_split((string)$params['prefix']));
        }

        $this->addSpecialChars($countArray, $theme);
        $themeData = $theme;
        $scaledSizes = [];
        $maxHeight = $totalWidth = 0;
        $defs = [];
        $defsMap = [];

        // First pass: Calculate definitions
        foreach ($countArray as $char) {
            $imgData = $themeData[$char];
            $scaledWidth = $imgData['preset_scales'][(string)$params['scale']]['w'] ?? $imgData['width'] * (float)$params['scale'];
            $scaledHeight = $imgData['preset_scales'][(string)$params['scale']]['h'] ?? $imgData['height'] * (float)$params['scale'];

            $scaledSizes[$char] = [$scaledWidth, $scaledHeight];
            $maxHeight = max($maxHeight, $scaledHeight);

            if (!isset($defsMap[$char])) {
                $defsMap[$char] = true;
                $defs[] = sprintf(
                    '<image id="%s" width="%.5f" height="%.5f" xlink:href="%s"/>',
                    $char, $scaledWidth, $scaledHeight, $imgData['data']
                );
            }
        }

        // Second pass: Generate parts
        $x = 0;
        $parts = [];
        foreach ($countArray as $char) {
            [$width, $height] = $scaledSizes[$char];
            $yOffset = $this->calculateYOffset($height, $maxHeight, $params['align']);
            $parts[] = sprintf(
                '<use x="%.5f"%s xlink:href="#%s"/>',
                $x, $yOffset !== 0 ? ' y="' . number_format($yOffset, 5, '.', '') . '"' : '', $char
            );
            $x += $width + (float)$params['offset'];
        }

        return $this->buildSVG($x - $params['offset'], $maxHeight, $defs, $parts, $params);
    }

    private function addSpecialChars(array &$array, array $theme): void
    {
        if (isset($theme['_start'])) {
            array_unshift($array, '_start');
        }
        if (isset($theme['_end'])) {
            $array[] = '_end';
        }
    }

    private function calculateYOffset(int|float $height, int|float $maxHeight, string $align): float
    {
        return match ($align) {
            'center' => ($maxHeight - $height) / 2,
            'bottom' => $maxHeight - $height,
            default => 0,
        };
    }

    private function buildSVG(float $totalWidth, float $maxHeight, array $defs, array $parts, array $params): string
    {
        $styles = [];
        if ($params['pixelated'] === '1') {
            $styles[] = 'image-rendering: pixelated;';
        }
        if ($params['darkmode'] === '1') {
            $styles[] = 'filter: brightness(.6);';
        } elseif ($params['darkmode'] === 'auto') {
            $styles[] = '@media (prefers-color-scheme: dark){svg{filter:brightness(.6)}}';
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?> <!-- Generated by https://github.com/journey-ad/Moe-Counter --> <svg viewBox="0 0 %.5f %.5f" width="%.5f" height="%.5f" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"> <title>Moe Counter</title><style>%s</style> <defs>%s</defs><g>%s</g></svg>',
            $totalWidth, $maxHeight, $totalWidth, $maxHeight, implode('', $styles), implode('', $defs), implode('', $parts)
        );
    }

    public function getThemeList(): array
    {
        return $this->themeList;
    }
}
