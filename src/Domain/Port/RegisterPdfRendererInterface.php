<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\Brand\Contract\Brand;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterReport;

/**
 * Renders the inspection PDF of one worker's register (contract section 8.2, REQ-REPORT-001). Default:
 * DompdfRegisterPdfRenderer (closed rendering: no remote resources, no JavaScript, no PHP).
 */
interface RegisterPdfRendererInterface
{
    /**
     * The PDF bytes.
     */
    public function render(RegisterReport $report, Brand $brand): string;
}
