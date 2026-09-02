<?php

namespace Fixture;

final class Sample
{
    public function run(): void
    {
        helper();
        missing_provider();
    }
}

function helper(): void {}
