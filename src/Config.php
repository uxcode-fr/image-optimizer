<?php

namespace ImageOptimizer;

final class Config
{
    public readonly string $source;
    public readonly string $destination;
    public readonly array  $quality;
    public readonly array  $formats;
    public readonly array  $densities;
    public readonly array  $folders;

    private const ALLOWED_FORMATS   = ['avif', 'webp', 'jpg'];
    private const DEFAULT_QUALITY   = ['avif' => 60, 'webp' => 82, 'jpg' => 85];

    private function __construct(array $data)
    {
        $this->source      = $data['source']      ?? 'resources/images';
        $this->destination = $data['destination'] ?? 'public/img';
        $this->quality     = array_merge(self::DEFAULT_QUALITY, $data['quality'] ?? []);
        $this->densities = array_values((array) ($data['densities'] ?? [1, 2]));

        $folders = $data['folders'] ?? [];
        $this->folders = array_map(
            static fn($widths) => $widths === null ? null : array_values((array) $widths),
            $folders
        );

        $formats = $data['formats'] ?? self::ALLOWED_FORMATS;
        $invalid = array_diff($formats, self::ALLOWED_FORMATS);
        if ($invalid !== []) {
            throw new \InvalidArgumentException('Invalid format(s): "' . implode('", "', $invalid) . '". Allowed: avif, webp, jpg.');
        }
        $this->formats = $formats;
    }

    public static function load(string $projectRoot): self
    {
        // Priority 1: config/image-optimizer.php (Laravel, Symfony, vanilla PHP)
        $phpConfig = $projectRoot . '/config/image-optimizer.php';
        if (is_file($phpConfig)) {
            $data = require $phpConfig;
            return new self(is_array($data) ? $data : []);
        }

        // Priority 2: composer.json extra section (fallback)
        $composerConfig = $projectRoot . '/composer.json';
        if (is_file($composerConfig)) {
            $data = json_decode(file_get_contents($composerConfig), true, 512, JSON_THROW_ON_ERROR);
            return new self($data['extra']['image-optimizer'] ?? []);
        }

        return new self([]);
    }
}
