<?php

namespace App\Core\Audit;

use App\Core\Audit\Models\Activity;
use App\Core\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * The current organisation's audit trail in words people read: when (in the organisation's time zone), who, what
 * happened to which record, and what changed. Only what was logged appears, so hidden and encrypted values never do.
 */
final class AuditTrail
{
    /** @var list<string> */
    public const array CSV_HEADER = ['When', 'Who', 'Event', 'Record type', 'Record', 'Changes'];

    /**
     * @return LazyCollection<int, array{string, string, string, string, string, string}>
     */
    public static function rows(CarbonInterface $from, CarbonInterface $to, string $timezone): LazyCollection
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->whereBetween('created_at', [$from, $to])
            // Ids are UUIDv7, so id order is the order things happened.
            ->lazyById(500)
            ->map(fn (Activity $activity): array => [
                $activity->created_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i:s') ?? '',
                self::who($activity),
                (string) ($activity->event ?? $activity->description),
                self::recordType($activity),
                self::record($activity),
                self::changes($activity),
            ]);
    }

    public static function who(Activity $activity): string
    {
        $causer = $activity->causer;

        return match (true) {
            $causer instanceof User => "{$causer->name} <{$causer->email}>",
            $activity->causer_id !== null => 'A deleted user',
            default => 'ComplyRobin',
        };
    }

    public static function recordType(Activity $activity): string
    {
        return $activity->subject_type === null ? '' : Str::headline(class_basename($activity->subject_type));
    }

    /**
     * The record's reference or name, never its id. Deleted records fall back to the name they had when logged.
     */
    public static function record(Activity $activity): string
    {
        $subject = $activity->subject;
        $logged = [...(array) data_get($activity->attribute_changes, 'old', []), ...(array) data_get($activity->attribute_changes, 'attributes', [])];

        foreach (['reference', 'name', 'email', 'file_name'] as $attribute) {
            $value = $subject instanceof Model ? $subject->getAttribute($attribute) : ($logged[$attribute] ?? null);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * "field: old → new" for each change; "field: value" for a new record. Ids are never shown: a link to another
     * record (owner_id…) reads "owner: changed", and the record's own id is left out.
     */
    public static function changes(Activity $activity): string
    {
        $changes = $activity->attribute_changes instanceof Collection ? $activity->attribute_changes : collect();
        $new = (array) $changes->get('attributes', []);
        $old = (array) $changes->get('old', []);

        return collect(array_keys([...$old, ...$new]))
            ->reject(fn (string $attribute): bool => $attribute === 'id')
            ->map(function (string $attribute) use ($old, $new): string {
                $isUpdate = array_key_exists($attribute, $old) && array_key_exists($attribute, $new);
                $value = array_key_exists($attribute, $new) ? $new[$attribute] : $old[$attribute];

                if (str_ends_with($attribute, '_id')) {
                    $link = Str::beforeLast($attribute, '_id');

                    return match (true) {
                        $isUpdate => "{$link}: changed",
                        $value === null => "{$link}: (empty)",
                        default => "{$link}: set",
                    };
                }

                return $isUpdate
                    ? "{$attribute}: ".self::value($old[$attribute]).' → '.self::value($new[$attribute])
                    : "{$attribute}: ".self::value($value);
            })
            ->implode('; ');
    }

    private static function value(mixed $value): string
    {
        $text = match (true) {
            $value === null => '(empty)',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return Str::limit($text, 200);
    }
}
