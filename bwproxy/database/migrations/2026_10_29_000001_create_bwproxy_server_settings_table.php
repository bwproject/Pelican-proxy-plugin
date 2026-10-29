<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bwproxy_server_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('server_uuid', 64)->unique();
            $table->unsignedBigInteger('node_id')->index();
            $table->string('mode', 16)->default('off');
            $table->string('socks5_host')->nullable();
            $table->unsignedSmallInteger('socks5_port')->nullable();
            $table->string('socks5_username')->nullable();
            $table->text('socks5_password')->nullable();
            $table->string('awg_interface', 32)->default('awg0');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bwproxy_server_settings');
    }
};
