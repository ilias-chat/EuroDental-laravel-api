<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('page_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('page')->default('home');       // e.g. 'home', 'about', etc.
            $table->string('section');                     // e.g. 'hero', 'carousel', etc.
            $table->string('type');                        // e.g. 'text', 'image', 'carousel'
            $table->json('content');                       // Flexible content
            $table->integer('position')->default(0);       // Ordering
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_blocks');
    }
};
