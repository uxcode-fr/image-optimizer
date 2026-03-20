<?php

namespace ImageOptimizer\Command;

use Imagick;
use ImageOptimizer\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

#[AsCommand(name: 'build', description: 'Converts PNG/JPG images to AVIF, WebP and JPG in multiple sizes')]
class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('folder', null, InputOption::VALUE_OPTIONAL, 'Process only a specific folder')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing images')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Delete generated images with no matching source');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config       = Config::load(getcwd());
        $sourcePath   = getcwd() . '/' . ltrim($config->source, '/');
        $outputPath   = getcwd() . '/' . ltrim($config->destination, '/');
        $folderConfig = $config->folders;
        $quality      = $config->quality;
        $formats      = $config->formats;
        $densities    = $config->densities;
        $force        = $input->getOption('force');
        $clean        = $input->getOption('clean');
        $onlyFolder   = $input->getOption('folder');

        if (!is_dir($sourcePath)) {
            $output->writeln("❌ <error>Le dossier source n'existe pas : {$sourcePath}</error>");
            return Command::FAILURE;
        }

        if (empty($folderConfig)) {
            $output->writeln('⚠️  <comment>Aucun dossier configuré. Définissez vos dossiers dans config/image-optimizer.php :</comment>');
            $output->writeln('');
            $output->writeln("  <info>'folders'</> => [");
            $output->writeln("      <info>'product'</> => [200, 280],");
            $output->writeln("      <info>'author'</>  => [48, 128],");
            $output->writeln("      <info>'article'</> => null,  <fg=gray>// convert only, no resize</>");
            $output->writeln('  ],');
            $output->writeln('');
            return Command::FAILURE;
        }

        $finder = Finder::create()->files()->name(['*.png', '*.jpg', '*.jpeg'])->in($sourcePath);

        if ($onlyFolder !== null) {
            $finder->path($onlyFolder);
        }

        $generated = 0;
        $skipped   = 0;

        foreach ($finder as $file) {
            $folder       = $file->getRelativePath();
            $basename     = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $originalSize = round(filesize($file->getRealPath()) / 1024, 2);

            $output->writeln('🖼️   Optimizing <fg=blue>' . $file->getRelativePathname() . '</> (<fg=red>' . $originalSize . ' KB</>)...');

            $destDir = rtrim($outputPath . '/' . $folder, '/');
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new \RuntimeException(sprintf('Failed to create directory "%s"', $destDir));
            }

            $widths = $folder !== '' ? ($folderConfig[$folder] ?? null) : null;

            if ($widths !== null) {
                foreach ($widths as $width) {
                    foreach ($densities as $density) {
                        $suffix = $density === 1 ? '' : "@{$density}x";

                        foreach ($formats as $format) {
                            $label = ($folder !== '' ? "{$folder}/" : '') . "{$basename}-{$width}{$suffix}.{$format}";
                            $dest  = "{$destDir}/{$basename}-{$width}{$suffix}.{$format}";

                            if (!$force && file_exists($dest)) {
                                $output->writeln("  <fg=gray>–</>  {$config->destination}/{$label} <fg=gray>(ignoré)</>");
                                $skipped++;
                                continue;
                            }

                            try {
                                $this->convertImage($file->getRealPath(), $dest, $format, $quality, $width * $density);
                                $finalSize = round(filesize($dest) / 1024, 2);
                                $saving    = round((1 - $finalSize / $originalSize) * 100);
                                $output->writeln("  <fg=green>✓</>  {$config->destination}/{$label} (<fg=green>{$finalSize} KB</>, <fg=green><options=bold>-{$saving}%</></> 🚀)");
                                $generated++;
                            } catch (\Exception $e) {
                                $output->writeln("  <error>✗</> Error on {$label}: " . $e->getMessage());
                            }
                        }
                    }
                }
            } else {
                foreach ($formats as $format) {
                    $label = ($folder !== '' ? "{$folder}/" : '') . "{$basename}.{$format}";
                    $dest  = "{$destDir}/{$basename}.{$format}";

                    if (!$force && file_exists($dest)) {
                        $output->writeln("  <fg=gray>–</>  {$config->destination}/{$label} <fg=gray>(ignoré)</>");
                        $skipped++;
                        continue;
                    }

                    try {
                        $this->convertImage($file->getRealPath(), $dest, $format, $quality);
                        $finalSize = round(filesize($dest) / 1024, 2);
                        $saving    = round((1 - $finalSize / $originalSize) * 100);
                        $output->writeln("  <fg=green>✓</>  {$config->destination}/{$label} (<fg=green>{$finalSize} KB</>, <fg=green><options=bold>-{$saving}%</></> 🚀)");
                        $generated++;
                    } catch (\Exception $e) {
                        $output->writeln("  <error>✗</> Error on {$label}: " . $e->getMessage());
                    }
                }
            }

            $output->writeln('');
        }

        $deleted = $clean
            ? $this->cleanObsoleteImages($sourcePath, $outputPath, $folderConfig, $formats, $densities, $config->destination, $onlyFolder, $output)
            : 0;

        $output->writeln("<info>{$generated} image(s) générée(s), {$skipped} ignorée(s), {$deleted} supprimée(s).</info>");

        return Command::SUCCESS;
    }

    private function cleanObsoleteImages(
        string $sourcePath,
        string $outputPath,
        array $folderConfig,
        array $formats,
        array $densities,
        string $destinationLabel,
        ?string $onlyFolder,
        OutputInterface $output
    ): int {
        if (!is_dir($outputPath)) {
            return 0;
        }

        $expected     = [];
        $sourceFinder = Finder::create()->files()->name(['*.png', '*.jpg', '*.jpeg'])->in($sourcePath);

        if ($onlyFolder !== null) {
            $sourceFinder->path($onlyFolder);
        }

        foreach ($sourceFinder as $file) {
            $folder   = $file->getRelativePath();
            $basename = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $widths   = $folder !== '' ? ($folderConfig[$folder] ?? null) : null;

            if ($widths !== null) {
                foreach ($widths as $width) {
                    foreach ($densities as $density) {
                        $suffix = $density === 1 ? '' : "@{$density}x";
                        foreach ($formats as $format) {
                            $expected[($folder !== '' ? $folder . '/' : '') . "{$basename}-{$width}{$suffix}.{$format}"] = true;
                        }
                    }
                }
            } else {
                foreach ($formats as $format) {
                    $expected[($folder !== '' ? $folder . '/' : '') . "{$basename}.{$format}"] = true;
                }
            }
        }

        $deleted       = 0;
        $foldersToScan = $onlyFolder !== null ? [$onlyFolder] : array_keys($folderConfig);

        foreach ($foldersToScan as $folder) {
            $folderPath = $outputPath . '/' . $folder;
            if (!is_dir($folderPath)) {
                continue;
            }

            $outputFinder = Finder::create()->files()->in($folderPath);

            foreach ($outputFinder as $file) {
                $relative = $folder . '/' . $file->getRelativePathname();
                if (!isset($expected[$relative])) {
                    unlink($file->getRealPath());
                    $output->writeln("  <fg=red>✗</>  {$destinationLabel}/{$relative} <fg=red>(supprimé)</>");
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function convertImage(string $source, string $dest, string $format, array $quality, ?int $width = null): void
    {
        $image = new Imagick($source);
        $image->stripImage();

        if ($width !== null) {
            $image->resizeImage($width, 0, Imagick::FILTER_LANCZOS, 1);
        }

        $imagickFormat = $format === 'jpg' ? 'jpeg' : $format;
        $image->setImageFormat($imagickFormat);
        $image->setImageCompressionQuality($quality[$format]);

        $image->writeImage($dest);
        $image->destroy();
    }
}
