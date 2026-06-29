<?php

namespace Deployward\Notify;

interface NotifierInterface
{
    public function notify(string $subject, string $body): void;
}
