<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral Programs.
 *
 * Customers refer friends and earn rewards. Track referral codes, conversions,
 * and payouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Referral Programs
        Schema::create('referral_programs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Referrer rewards
            $table->string('referrer_reward_type', 20)->default('discount'); // discount, credit, points, cash
            $table->unsignedBigInteger('referrer_reward_amount')->default(0);
            $table->string('referrer_reward_unit', 20)->default('percent'); // percent, fixed, points
            
            // Referee rewards (the new customer)
            $table->string('referee_reward_type', 20)->default('discount');
            $table->unsignedBigInteger('referee_reward_amount')->default(0);
            $table->string('referee_reward_unit', 20)->default('percent');
            
            $table->unsignedInteger('min_purchase_amount')->nullable(); // Referee must spend this much
            $table->unsignedInteger('max_referrals_per_customer')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
        });

        // Referral Codes
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('program_id')->constrained('referral_programs')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained();

            $table->string('code', 40)->unique();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('conversion_count')->default(0); // How many actually bought
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['program_id', 'customer_id']);
            $table->index('code');
        });

        // Referrals (individual referral tracking)
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('referral_programs');
            $table->foreignId('referral_code_id')->constrained();
            $table->foreignId('referrer_id')->constrained('customers'); // Who made the referral
            $table->foreignId('referee_id')->nullable()->constrained('customers'); // Who was referred

            $table->string('referee_email')->nullable();
            $table->string('referee_phone')->nullable();
            $table->string('status', 20)->default('pending'); // pending, signed_up, converted, paid
            
            $table->foreignId('conversion_order_id')->nullable()->constrained('orders');
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('referral_code_id');
            $table->index('referrer_id');
        });

        // Referral Rewards (payouts)
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained(); // Who received the reward

            $table->string('reward_type', 20); // discount, credit, points, cash
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending'); // pending, issued, redeemed, expired
            
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['referral_id', 'customer_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('referral_programs');
    }
};
