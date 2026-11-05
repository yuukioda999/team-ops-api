<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 20)->comment('login / email_change');
            $table->text('email')->comment('暗号化。ログインまたは変更後のアドレス');
            $table->char('email_index', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique()->comment('トークンのSHA-256。トークン自体は保存しない');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_tokens');
    }
};
