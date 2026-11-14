<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_managed_peer', function (Blueprint $table) {
            $table->decimal('u', 20, 0)->default(0);
            $table->decimal('d', 20, 0)->default(0);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Peer 历史计量需要保留，禁止自动回滚删除。');
    }
};
