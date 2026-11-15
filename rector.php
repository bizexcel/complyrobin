<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    // Laravel's conventions and generators don't use strict_types; keep files consistent with them.
    ->withSkip([SafeDeclareStrictTypesRector::class]);
