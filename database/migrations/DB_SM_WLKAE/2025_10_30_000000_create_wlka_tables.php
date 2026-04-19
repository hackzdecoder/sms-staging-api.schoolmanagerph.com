<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('date');
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('Unread');
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('date');
            $table->string('time_in')->nullable();
            $table->string('kiosk_terminal_in')->nullable();
            $table->string('time_out')->nullable();
            $table->string('kiosk_terminal_out')->nullable();
            $table->string('status')->default('Unread');
            $table->timestamps();
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('rfid');
            $table->string('fullname');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('messages');
    }
};
