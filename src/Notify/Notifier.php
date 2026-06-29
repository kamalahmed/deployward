<?php

namespace Deployward\Notify;

final class Notifier implements NotifierInterface
{
    /** @var string */
    private $to;

    public function __construct(string $to)
    {
        $this->to = $to;
    }

    public function notify(string $subject, string $body): void
    {
        if ($this->to === '') {
            return;
        }
        wp_mail($this->to, $subject, $body);
    }
}
