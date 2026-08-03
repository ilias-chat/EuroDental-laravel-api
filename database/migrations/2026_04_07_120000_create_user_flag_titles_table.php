<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_flag_titles', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('title');
            $table->timestamps();

            $table->unique(['kind', 'title']);
        });

        $now = now();
        DB::table('user_flag_titles')->insert([
            ['kind' => 'green', 'title' => 'Excellence — service client', 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'green', 'title' => 'Performance remarquable', 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'red', 'title' => 'Suivi à renforcer', 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'red', 'title' => 'Incident ou réclamation', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('user_flag_events', function (Blueprint $table) {
            $table->foreignId('user_flag_title_id')
                ->nullable()
                ->after('kind')
                ->constrained('user_flag_titles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_flag_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_flag_title_id');
        });

        Schema::dropIfExists('user_flag_titles');
    }
};
