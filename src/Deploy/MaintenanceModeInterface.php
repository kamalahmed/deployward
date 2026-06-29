<?php

namespace Deployward\Deploy;

interface MaintenanceModeInterface
{
    public function enable(): void;

    public function disable(): void;
}
