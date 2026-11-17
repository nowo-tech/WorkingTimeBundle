<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandColors;
use Nowo\Brand\Contract\BrandProviderInterface;

/**
 * Brand used when the host has none (contract section 8.1): a neutral name and palette, no logo.
 */
final readonly class NeutralBrandProvider implements BrandProviderInterface
{
    public function __construct(private string $name = 'Working time')
    {
    }

    /**
     * The neutral brand.
     */
    public function getBrand(): Brand
    {
        $light = new BrandColors('#334155', '#1e293b', '#b91c1c', '#b45309', '#ffffff', '#0f172a', '#f8fafc');
        $dark  = new BrandColors('#cbd5e1', '#e2e8f0', '#f87171', '#fbbf24', '#0f172a', '#f8fafc', '#1e293b');

        return new Brand($this->name, $light, $dark, [], null, null, null, true);
    }
}
