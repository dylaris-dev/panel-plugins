<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.1.0 had one subuser permission, ext.dylaris-gateway.manage. 0.2.0 splits it into view, mode and address; a subuser who held
 * manage gets all three, so an update takes nothing away from anybody.
 */
return new class extends Migration
{
    private const OLD = 'ext.dylaris-gateway.manage';

    private const NEW = ['ext.dylaris-gateway.view', 'ext.dylaris-gateway.mode', 'ext.dylaris-gateway.address'];

    public function up(): void
    {
        $this->rewrite(fn (array $p) => in_array(self::OLD, $p, true)
            ? [...array_diff($p, [self::OLD]), ...array_diff(self::NEW, $p)]
            : null);
    }

    /**
     * The old permission was everything at once, so only a subuser who could switch the mode AND choose the address gets
     * it back; anyone else loses the new keys rather than gaining rights they never had.
     */
    public function down(): void
    {
        $this->rewrite(function (array $p) {
            if (array_intersect(self::NEW, $p) === []) {
                return null;
            }
            $rest = array_values(array_diff($p, self::NEW));

            return in_array(self::NEW[1], $p, true) && in_array(self::NEW[2], $p, true) ? [...$rest, self::OLD] : $rest;
        });
    }

    /** @param callable(list<string>): ?list<string> $change null = leave the row alone */
    private function rewrite(callable $change): void
    {
        DB::table('subusers')->where('permissions', 'like', '%dylaris-gateway.%')->orderBy('id')->each(function (object $row) use ($change) {
            $permissions = json_decode((string) $row->permissions, true);
            $new = is_array($permissions) ? $change($permissions) : null;
            if ($new !== null) {
                DB::table('subusers')->where('id', $row->id)->update(['permissions' => json_encode(array_values($new))]);
            }
        });
    }
};
