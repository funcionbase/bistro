<?php

namespace App\Console\Commands;

use FontLib\Font;
use Illuminate\Console\Command;

class InstallBrandFonts extends Command
{
    protected $signature = 'fonts:install-brand';

    protected $description = 'Install the FlexyFont brand font for dompdf (generates .ufm metrics + registers in installed-fonts.json).';

    public function handle(): int
    {
        $src = storage_path('fonts/FlexyFont.otf');
        $dir = storage_path('fonts');

        if (! is_file($src)) {
            $this->error("Source font missing: {$src}");

            return self::FAILURE;
        }

        $variants = ['normal', 'bold', 'italic', 'bold_italic'];

        foreach ($variants as $variant) {
            $dst = $dir.DIRECTORY_SEPARATOR."flexyfont_{$variant}.otf";
            if (! is_file($dst)) {
                copy($src, $dst);
            }
            $font = Font::load($dst);
            $font->parse();
            $font->saveAdobeFontMetrics($dir.DIRECTORY_SEPARATOR."flexyfont_{$variant}.ufm");
            $font->close();
            $this->line("  generated flexyfont_{$variant}.ufm");
        }

        $installedFile = $dir.DIRECTORY_SEPARATOR.'installed-fonts.json';
        $installed = is_file($installedFile)
            ? json_decode(file_get_contents($installedFile), true)
            : [];

        $installed['flexyfont'] = [
            'normal' => $dir.DIRECTORY_SEPARATOR.'flexyfont_normal',
            'bold' => $dir.DIRECTORY_SEPARATOR.'flexyfont_bold',
            'italic' => $dir.DIRECTORY_SEPARATOR.'flexyfont_italic',
            'bold_italic' => $dir.DIRECTORY_SEPARATOR.'flexyfont_bold_italic',
        ];

        file_put_contents(
            $installedFile,
            json_encode($installed, JSON_PRETTY_PRINT)
        );

        $this->info('FlexyFont registered for dompdf.');

        return self::SUCCESS;
    }
}
