<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Arch');

/**
 * Every concrete Eloquent model class under app/, discovered from the PSR-4 path.
 *
 * @return list<class-string<Model>>
 */
function appModels(): array
{
    $models = [];

    foreach (File::allFiles(app_path()) as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class)
            && is_subclass_of($class, Model::class)
            && ! new ReflectionClass($class)->isAbstract()) {
            $models[] = $class;
        }
    }

    return $models;
}
