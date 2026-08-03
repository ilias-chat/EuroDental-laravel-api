<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeEmailUniqueInClientsTable extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // First, make sure email is NOT nullable
            $table->string('email')->nullable(false)->change();
            
            // Then add a unique index
            $table->unique('email');
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Remove unique constraint if rollback
            $table->dropUnique(['email']);
        });
    }
}
