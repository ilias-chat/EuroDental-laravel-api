<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    DB::statement('ALTER TABLE products ALTER COLUMN slug SET NOT NULL');
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
