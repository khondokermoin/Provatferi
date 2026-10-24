<?php

namespace App\Services\Mail\Data;

/** One sender or recipient as the provider reported it. */
final readonly class MailAddress
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {}

    /** The name when there is one, else the address. */
    public function label(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : $this->address;
    }

    /** "Name <address>" (or the bare address), for quoting a message in a reply or forward. */
    public function full(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? "{$name} <{$this->address}>" : $this->address;
    }
}
