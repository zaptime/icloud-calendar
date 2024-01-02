<?php

declare(strict_types=1);

namespace Zaptime\ICloudCalendar;

readonly class Calendar
{

    public function __construct(
        public string $url,
        public string $name,
    ) {}

}