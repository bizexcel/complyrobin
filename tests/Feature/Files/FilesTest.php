<?php

use App\Core\Files\PrivateDisk;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\File;

it('keeps private files on the default disk, which Laravel Cloud sets to its private bucket', function (): void {
    config(['media-library.disk_name' => null, 'filesystems.default' => 'cloud-private']);

    new AppServiceProvider(app())->register();

    expect(PrivateDisk::name())->toBe('cloud-private')
        ->and(config('media-library.disk_name'))->toBe('cloud-private');
});

it('keeps media on a separate private disk when MEDIA_DISK names one', function (): void {
    config(['media-library.disk_name' => 'evidence', 'filesystems.default' => 'cloud-private']);

    expect(PrivateDisk::name())->toBe('evidence');
});

it('never sets a per-file visibility, which Cloud Object Storage rejects', function (): void {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/visibility|storePublicly|putFilePublicly/i', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
