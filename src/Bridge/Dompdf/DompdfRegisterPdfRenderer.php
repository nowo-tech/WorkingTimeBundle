<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\Dompdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Nowo\Brand\Contract\Brand;
use Nowo\WorkingTimeBundle\Domain\Port\RegisterPdfRendererInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterReport;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * Default inspection-PDF renderer (contract F7 / section 8.2, Beacon Q-B6, REQ-REPORT-001): Dompdf rendering is
 * **closed** — no remote resources, no JavaScript, no PHP, a fresh temporary chroot and font cache per call (the bundled fonts are read from Dompdf's own directory), removed in
 * `finally` (Dompdf keeps state: one instance per call, never shared, worker mode). The brand (name, colours) comes
 * from the brand provider; the template prints only escaped strings of the report.
 */
final readonly class DompdfRegisterPdfRenderer implements RegisterPdfRendererInterface
{
    public function __construct(
        private Environment $twig,
        private string $template = '@NowoWorkingTime/export/register_pdf.html.twig',
    ) {
    }

    /**
     * The PDF bytes.
     */
    public function render(RegisterReport $report, Brand $brand): string
    {
        $filesystem = new Filesystem();
        $dir        = sys_get_temp_dir() . '/nowo-wt-pdf-' . bin2hex(random_bytes(8));
        $filesystem->mkdir($dir);
        try {
            $options = new Options();
            $options->setIsRemoteEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsHtml5ParserEnabled(true);
            $options->setChroot([$dir]);
            $options->setTempDir($dir);
            $options->setFontCache($dir);
            $options->setDefaultFont('DejaVu Sans');
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->twig->render($this->template, ['report' => $report, 'brand' => $brand, 'colors' => $brand->colors()]), 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return (string) $dompdf->output();
        } finally {
            $filesystem->remove($dir);
        }
    }
}
