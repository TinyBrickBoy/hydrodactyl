<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sftp_password')->nullable()->after('password');
            $table->timestamp('sftp_password_expires_at')->nullable()->after('sftp_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sftp_password', 'sftp_password_expires_at']);
        });
    }
};
