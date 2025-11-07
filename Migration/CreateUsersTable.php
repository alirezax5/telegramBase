<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;


if (!Capsule::schema()->hasTable('users')) {
    Capsule::schema()->create('users', function (Blueprint $table) {
        $table->bigIncrements('id'); // auto increment id
        $table->bigInteger('chatid')->index();
        $table->string('command', 255)->nullable();
        $table->string('data', 255)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->nullable();
        $table->enum('role', ['owner', 'admin', 'vip', 'user'])
            ->default('user')
            ->charset('latin1')
            ->collation('latin1_swedish_ci');
        $table->enum('type', ['normal', 'vip', 'gold'])->default('normal');
        $table->float('price')->default(0);
        $table->enum('status', ['active', 'ban', 'stoped', ''])->default('active');
        $table->string('lang', 6)->default('en');
        $table->timestamp('expire')->nullable();
        $table->timestamp('create_at')->useCurrent();
    });
}
