<?php

namespace Deployward\Security;

interface SignatureVerifierInterface
{
    public function verify(string $payload, ?string $signatureHeader, string $secret): bool;
}
