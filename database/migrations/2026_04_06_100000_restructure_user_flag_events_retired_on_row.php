<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_flag_events', function (Blueprint $table) {
            $table->timestamp('retired_at')->nullable()->after('note');
            $table->foreignId('retired_by')->nullable()->after('retired_at')->constrained('users')->nullOnDelete();
            $table->text('retired_note')->nullable()->after('retired_by');
        });

        if (! Schema::hasColumn('user_flag_events', 'direction')) {
            return;
        }

        $reverses = DB::table('user_flag_events')
            ->where('direction', 'reverse')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($reverses as $rev) {
            $addQuery = DB::table('user_flag_events')
                ->where('user_id', $rev->user_id)
                ->where('kind', $rev->kind)
                ->where('direction', 'add')
                ->whereNull('retired_at')
                ->orderBy('created_at')
                ->orderBy('id');

            $add = $addQuery->first();

            if ($add) {
                DB::table('user_flag_events')->where('id', $add->id)->update([
                    'retired_at' => $rev->created_at,
                    'retired_by' => $rev->created_by,
                    'retired_note' => $rev->note,
                    'updated_at' => now(),
                ]);
            }

            DB::table('user_flag_events')->where('id', $rev->id)->delete();
        }

        Schema::table('user_flag_events', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }

    public function down(): void
    {
        Schema::table('user_flag_events', function (Blueprint $table) {
            $table->string('direction', 16)->default('add')->after('kind');
        });

        Schema::table('user_flag_events', function (Blueprint $table) {
            $table->dropForeign(['retired_by']);
            $table->dropColumn(['retired_at', 'retired_by', 'retired_note']);
        });
    }
};
