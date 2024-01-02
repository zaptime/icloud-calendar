<?php

declare(strict_types=1);

namespace Zaptime\ICloudCalendar;

readonly class Attendee
{

    public function __construct(
        public string $name,
        public string $email
    ) {}

}