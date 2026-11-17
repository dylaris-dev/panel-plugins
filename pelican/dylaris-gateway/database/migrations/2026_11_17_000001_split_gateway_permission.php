<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.1.0 had one subuser permission, gateway.manage. 0.2.0 splits it into view, mode and address; a subuser who held
 * manage gets all three, so an update takes nothing away from anybody.
 */
return new class extends Migration
{
    private const OLD = 'gateway.manage';

    private const NEW = ['gateway.view', 'gateway.mode', 'gateway.address'];

    public function up(): void
    {
        $this->rewrite(fn (array $p) => in_array(self::OLD, $p, true)
            ? [...array_diff($p, [self::OLD]), ...array_diff(self::NEW, $p)]
            : null);
    }

    public function down(): void
    {
        $this->rewrite(fn (array $p) => array_intersect(self::NEW, $p) !== []
            ? [...array_diff($p, self::NEW), self::OLD]
            : null);
    }

    /** @param callable(list<string>): ?list<string> $change null = leave the row alone */
    private function rewrite(callable $change): void
    {
        DB::table('subusers')->where('permissions', 'like', '%gateway.%')->orderBy('id')->each(function (object $row) use ($change) {
            $permissions = json_decode((string) $row->permissions, true);
            $new = is_array($permissions) ? $change($permissions) : null;
            if ($new !== null) {
                DB::table('subusers')->where('id', $row->id)->update(['permissions' => json_encode(array_values($new))]);
            }
        });
    }
};
