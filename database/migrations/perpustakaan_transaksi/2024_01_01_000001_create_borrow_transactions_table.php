<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql_transaction';

    public function up(): void
    {
        Schema::connection($this->connection)->create('borrow_transactions', function (Blueprint $table) {
            $table->id();
            // member_id references members.id in the perpustakaan (mysql_main) database.
            // Cross-database foreign keys are not supported in MySQL, so referential
            // integrity must be enforced at the application level.
            $table->unsignedBigInteger('member_id')->index();
            $table->timestamp('borrowed_at')->useCurrent();
            $table->date('due_date');
            $table->enum('status', ['active', 'returned', 'overdue'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('borrow_transactions');
    }
};
