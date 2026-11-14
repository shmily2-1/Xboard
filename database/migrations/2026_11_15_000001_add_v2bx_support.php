<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->string('backend_profile', 32)->default('legacy');
        });
        Schema::create('v2_protocol_credential', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('secret');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('v2_managed_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('machine_id')->index();
            $table->string('name');
            $table->string('type', 24);
            $table->text('spec');
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('applied_revision')->default(0);
            $table->string('state', 32)->default('pending');
            $table->text('runtime')->nullable();
            $table->decimal('u', 20, 0)->default(0);
            $table->decimal('d', 20, 0)->default(0);
            $table->boolean('deleting')->default(false);
            $table->timestamps();
        });
        Schema::create('v2_managed_peer', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('public_key', 44);
            $table->boolean('retired')->default(false);
            $table->text('preshared_key')->nullable();
            $table->text('addresses');
            $table->unsignedSmallInteger('keepalive')->default(25);
            $table->boolean('enabled')->default(true);
            $table->boolean('deleting')->default(false);
            $table->timestamps();
            $table->unique(['service_id', 'user_id']);
            $table->unique(['service_id', 'public_key']);
        });
        Schema::create('v2_meter_batch', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80);
            $table->string('batch_id', 64);
            $table->string('digest', 64);
            $table->timestamps();
            $table->unique(['source', 'batch_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('扩展凭据、租约和计量数据需要人工备份与迁移，禁止自动回滚删除。');
    }
};
