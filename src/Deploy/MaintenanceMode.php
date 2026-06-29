<?php

namespace Deployward\Deploy;

final class MaintenanceMode implements MaintenanceModeInterface
{
    /** @var string */
    private $abspath;

    public function __construct(string $abspath)
    {
        $this->abspath = rtrim($abspath, '/') . '/';
    }

    public function enable(): void
    {
        file_put_contents($this->abspath . '.maintenance', '<?php $upgrading = ' . time() . ';');
    }

    public function disable(): void
    {
        $file = $this->abspath . '.maintenance';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
