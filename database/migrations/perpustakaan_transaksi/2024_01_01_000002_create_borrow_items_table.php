<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql_transaction';

    public function up(): void
    {
        Schema::connection($this->connection)->create('borrow_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('borrow_transaction_id')->index();
            // book_id references books.id in the perpustakaan (mysql_main) database.
            // Cross-database foreign keys are not supported in MySQL, so referential
            // integrity must be enforced at the application level.
            $table->unsignedBigInteger('book_id')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->foreign('borrow_transaction_id')
                ->references('id')
                ->on('borrow_transactions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('borrow_items');
    }
};
