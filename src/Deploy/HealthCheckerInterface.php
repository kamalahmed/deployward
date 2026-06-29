<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

interface HealthCheckerInterface
{
    public function check(string $url): Result;
}
