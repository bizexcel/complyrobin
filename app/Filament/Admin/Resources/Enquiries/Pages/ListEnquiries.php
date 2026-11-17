<?php

namespace App\Filament\Admin\Resources\Enquiries\Pages;

use App\Filament\Admin\Resources\Enquiries\EnquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListEnquiries extends ListRecords
{
    #[\Override]
    protected static string $resource = EnquiryResource::class;

    public function getSubheading(): string
    {
        return 'Messages from the contact form on www.complyrobin.com. Reply by email, then mark the enquiry handled.';
    }
}
