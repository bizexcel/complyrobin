<?php

use App\Core\Files\Models\Media;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A person who can hold files, standing in for the records that will (credentials, rotations).
 */
class PersonWithFiles extends Person implements HasMedia
{
    use InteractsWithMedia;

    public function getMorphClass(): string
    {
        return Person::class;
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    [$this->acme, $this->globex] = Organisation::factory()->count(2)->create();
});

function attachFile(Organisation $organisation): Media
{
    return Tenancy::run($organisation, function (): Media {
        $person = PersonWithFiles::query()->create(['name' => 'Siti Rahman']);

        /** @var Media */
        return $person->addMedia(UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf'))->toMediaCollection('evidence');
    });
}

it('stores an organisation\'s files under its own folder, named by uuid', function (): void {
    $media = attachFile($this->acme);

    expect($media->organisation_id)->toBe($this->acme->id)
        ->and($media->getPathRelativeToRoot())->toBe("org/{$this->acme->id}/{$media->uuid}/evidence.pdf");
    Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
});

it('shows each organisation only its own files', function (): void {
    attachFile($this->acme);

    expect(Tenancy::run($this->globex, fn (): int => DB::table('media')->count()))->toBe(0)
        ->and(Tenancy::run($this->acme, fn (): int => DB::table('media')->count()))->toBe(1);
});
