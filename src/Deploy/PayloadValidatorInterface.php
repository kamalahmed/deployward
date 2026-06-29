<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

interface PayloadValidatorInterface
{
    public function validate(string $dir, string $targetType, string $targetSlug): Result;
}
