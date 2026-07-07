<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\KlassciData;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KlassciData::class)]
final class KlassciDataTest extends TestCase
{
    public function test_get_normalizes_native_array_keys_to_strings(): void
    {
        $cast = new KlassciData();
        $model = new class extends Model {};

        self::assertSame(
            ['0' => 'zero', 'name' => 'Issouf'],
            $cast->get($model, 'klassci_data', [0 => 'zero', 'name' => 'Issouf'], [])
        );
    }

    public function test_get_decodes_json_object_as_string_keyed_array(): void
    {
        $cast = new KlassciData();
        $model = new class extends Model {};

        self::assertSame(
            ['id' => 12, 'role' => 'enseignant'],
            $cast->get($model, 'klassci_data', '{"id":12,"role":"enseignant"}', [])
        );
    }

    public function test_get_returns_empty_array_for_invalid_payload(): void
    {
        $cast = new KlassciData();
        $model = new class extends Model {};

        self::assertSame([], $cast->get($model, 'klassci_data', 'not-json', []));
        self::assertSame([], $cast->get($model, 'klassci_data', null, []));
    }
}
