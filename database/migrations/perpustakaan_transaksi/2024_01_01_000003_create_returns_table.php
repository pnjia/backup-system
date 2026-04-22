<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql_transaction';

    public function up(): void
    {
        Schema::connection($this->connection)->create('returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('borrow_transaction_id')->unique()->index();
            $table->timestamp('returned_at')->useCurrent();
            $table->decimal('fine_amount', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('borrow_transaction_id')
                ->references('id')
                ->on('borrow_transactions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('returns');
    }
};
