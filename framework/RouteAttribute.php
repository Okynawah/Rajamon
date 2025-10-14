<?php

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Route
{
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public bool $cache = false,
    ) {}
}
