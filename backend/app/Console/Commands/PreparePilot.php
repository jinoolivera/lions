<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PreparePilot extends Command
{
    protected $signature = 'lions:prepare-pilot {--execute : Clear business data and create pilot accounts}';
    protected $description = 'Show the pilot reset scope or prepare two empty stores after an external database backup';

    public function handle(): int
    {
        $branches = Branch::query()->whereIn('code', ['LIONS_A', 'LIONS_B'])->orderBy('code')->get();
        if ($branches->count() !== 2 || Branch::query()->count() !== 2 || Warehouse::query()->count() !== 2 ||
            $branches->contains(fn (Branch $branch) => $branch->warehouses()->count() !== 1)) {
            $this->error('Se requieren exactamente las dos tiendas LIONS_A y LIONS_B, cada una con un almacén.');
            return self::FAILURE;
        }
        $keep = ['migrations', 'branches', 'warehouses', 'warehouse_locations', 'sunat_settings', 'product_units', 'finance_categories'];
        $tables = collect(DB::select('SHOW TABLES'))->map(fn ($row) => (string) array_values((array) $row)[0]);
        $clear = $tables->reject(fn ($table) => in_array($table, $keep, true))->values();
        $this->line('Se conservan tiendas, almacenes, unidades, categorías financieras y configuración fiscal.');
        foreach ($clear as $table) $this->line("{$table}: ".DB::table($table)->count());
        if (! $this->option('execute')) return self::SUCCESS;

        $password = getenv('LIONS_PILOT_PASSWORD');
        if (! is_string($password) || strlen($password) < 8 || getenv('LIONS_PILOT_BACKUP_VERIFIED') !== 'yes') {
            $this->error('Faltan LIONS_PILOT_PASSWORD o LIONS_PILOT_BACKUP_VERIFIED=yes.');
            return self::FAILURE;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($clear as $table) DB::table($table)->truncate();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $admin = User::query()->create(['name' => 'Administrador Lions', 'email' => 'admin@lions.com', 'password' => $password, 'role' => 'admin']);
        foreach ($branches as $branch) {
            $email = $branch->code === 'LIONS_A' ? 'tienda.a@lions.com' : 'tienda.b@lions.com';
            $user = User::query()->create(['name' => 'Encargado '.$branch->name, 'email' => $email, 'password' => $password, 'role' => 'manager']);
            $user->branches()->attach($branch->id);
        }
        if (User::query()->count() !== 3 || DB::table('branch_user')->count() !== 2) throw new RuntimeException('No se pudieron crear las tres cuentas piloto.');
        $this->info('Piloto listo: una cuenta administradora y una cuenta encargada por tienda.');

        return self::SUCCESS;
    }
}
