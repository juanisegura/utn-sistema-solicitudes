<?php

declare(strict_types=1);

// Tests unitarios: php tests/unit/run.php [filtro]. Runner y aserciones en tests/lib/runner.php.
require __DIR__ . '/../lib/runner.php';

exit(correrSuite(__DIR__, 'Tests unitarios', $argv));
