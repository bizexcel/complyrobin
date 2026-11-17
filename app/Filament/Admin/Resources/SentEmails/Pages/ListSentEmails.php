<?php

namespace App\Filament\Admin\Resources\SentEmails\Pages;

use App\Filament\Admin\Resources\SentEmails\SentEmailResource;
use Filament\Resources\Pages\ListRecords;

class ListSentEmails extends ListRecords
{
    #[\Override]
    protected static string $resource = SentEmailResource::class;

    #[\Override]
    protected static ?string $title = 'Email log';

    public function getSubheading(): string
    {
        return 'Every email ComplyRobin has handed to the mail provider. Search by address to answer "did I get it?". Bodies are never kept, because they can hold sign-in codes and invitation links.';
    }
}
