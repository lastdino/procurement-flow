<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lastdino\ProcurementFlow\Support\Tables;

return new class extends Migration {
    public function up(): void
    {
        Schema::table(Tables::name('materials'), function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('manage_by_lot');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::name('materials'), function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
