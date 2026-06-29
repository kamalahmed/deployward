<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

interface BackupManagerInterface
{
    public function backup(string $targetDir, string $slug, string $sha): Result;

    public function restoreLatest(string $slug, string $targetDir): Result;

    public function prune(string $slug, int $keep): void;
}
