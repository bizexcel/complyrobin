<?php

namespace App\Core\Enquiries\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message from the contact form on www.complyrobin.com. Added by SubmitEnquiry; read and handled only by staff
 * (MarkEnquiryHandled, ReopenEnquiry), on the platform connection.
 *
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string $message
 * @property Carbon|null $handled_at
 * @property string|null $handled_by_id
 * @property string|null $note
 * @property Carbon $created_at
 */
#[Fillable(['name', 'email', 'company', 'message'])]
class Enquiry extends Model
{
    use HasUuids, UsesTenancyConnection;

    /**
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }
}
