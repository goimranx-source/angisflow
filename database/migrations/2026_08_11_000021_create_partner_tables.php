<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 26: Partners, KYC, and Profit Distribution
 *
 * A partner is an owner of the business — someone who shares the profit and
 * the risk. This is not about "business partners" in the supplier sense; it is
 * about equity holders in a partnership or closely-held company.
 *
 * ## Why this design
 *
 * ### Three accounts, not one
 *
 * Each partner gets three ledger accounts:
 * - **Liability account** — what the business owes them for expenses they paid
 * - **Capital account** (equity) — permanent investment, rarely touched
 * - **Current account** (equity) — running balance of profit allocated and drawn
 *
 * **Why separate capital from current:**
 * Every routine drawing reduces one account. If there is only one, then after a
 * few years of normal withdrawals the books claim nobody ever invested anything.
 * The capital account records the permanent investment; the current account is
 * where profit moves in and drawings move out.
 *
 * **Why the liability account:**
 * A partner often pays for business expenses out of pocket — fuel, supplies,
 * minor purchases. The business owes them for it, separately from their equity.
 * Mixing owed reimbursements with ownership would make every petty expense look
 * like an investment.
 *
 * ### Share periods, not a single percentage
 *
 * `partner_share_periods` records what a partner's share *became*, and from
 * which day. Rows are never edited once profit has been distributed using them.
 *
 * **Why:** Partners come and go, and terms renegotiate. A profit run for
 * January–June may span a period where one partner was on 40%, then left, and
 * another joined at 30%. A single editable figure would silently rewrite the
 * past; dated periods make the history a matter of record.
 *
 * ### KYC before transacting
 *
 * A partner record is dormant until their identity is verified against a
 * document. They can be *created* before verification (to get the record on
 * file), but they cannot hold capital, draw, or receive profit until verified.
 *
 * **Why:** A partner is a claim on the business's money. "Jane Smith, 30%" in a
 * database row is easy to type and hard to undo. Requiring a verified ID before
 * they can transact means nobody receives or withdraws equity based on a name
 * somebody entered on a busy afternoon.
 *
 * ### Profit distribution as posted entries, not magic
 *
 * When profit is distributed, it posts real double-entry transactions:
 * DR Retained Earnings / CR Partner X Current Account. Reversing a distribution
 * creates compensating entries rather than deleting rows.
 *
 * **Why:** Profit allocation is an accounting event, not a parallel ledger. If
 * it happens outside the books, then partner balances and equity totals will
 * never agree, and the records are two competing versions of the truth.
 *
 * ### Documents on private disk
 *
 * Partnership deeds, ID scans, and agreements are stored on Laravel's private
 * disk and served only through a controller that checks tenancy. They are never
 * on the public disk.
 *
 * **Why:** These documents name people, sums, bank details, and national ID
 * numbers. A public URL that can be guessed or enumerated is a data breach
 * waiting to happen.
 *
 * ## What is not here
 *
 * - **Partner joining and leaving:** Admitting a new partner may involve a
 *   premium (goodwill payment), and retiring one involves valuing their share.
 *   The logic exists in the old codebase but is complex enough that it belongs
 *   in a separate service, kept out of the core PartnerService to keep that one
 *   readable. Deferred to later refinement.
 *
 * - **Capital revaluation:** When the business is revalued (assets worth more
 *   than book value), the gain is distributed among partners. Not included yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Partners ────────────────────────────────────────────────────
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            // Each partner has three ledger accounts (created on demand):
            $table->foreignId('liability_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('capital_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('current_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            
            // Optional link to a user account (if this partner is also a system user)
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            
            // Basic info
            $table->string('code', 20)->comment('Unique partner code like P001');
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Current profit share (most recent effective percentage)
            // Null means "no agreed ratio" — profit then splits by capital
            $table->decimal('profit_share_percent', 5, 3)->nullable()
                ->comment('Current profit share %. History in partner_share_periods.');
            
            // Personal details (not required initially)
            $table->string('photo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 2)->nullable(); // ISO 3166-1 alpha-2
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('blood_group', 8)->nullable();
            $table->date('joined_on')->nullable()->comment('Date they became a partner');
            
            // Emergency contact
            $table->string('emergency_name')->nullable();
            $table->string('emergency_phone', 50)->nullable();
            
            // Nominee (beneficiary)
            $table->string('nominee_name')->nullable();
            $table->string('nominee_relation', 50)->nullable();
            $table->string('nominee_phone', 50)->nullable();
            $table->string('nominee_id_number', 60)->nullable();
            
            // Banking
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 100)->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('mobile_wallet', 100)->nullable();
            
            // ── KYC (Know Your Customer) ───────────────────────────────
            // Identity verification against a government-issued document.
            // Partner is dormant until verified.
            
            $table->string('id_type', 40)->nullable()
                ->comment('Document type: passport, national_id, etc.');
            $table->string('id_country', 2)->nullable()
                ->comment('Issuing country (ISO 3166-1)');
            $table->string('id_number', 60)->nullable()
                ->comment('Document number');
            $table->string('id_name')->nullable()
                ->comment('Name as it appears on document');
            $table->date('id_date_of_birth')->nullable();
            
            // Stored on private disk, served through controller only
            $table->string('id_front_path')->nullable()
                ->comment('Path to front of ID document');
            $table->string('id_back_path')->nullable()
                ->comment('Path to back of ID document (if applicable)');
            
            // OCR-extracted data from the document (JSON)
            $table->json('id_extracted')->nullable()
                ->comment('Machine-extracted fields from document');
            
            // Verification status
            $table->timestamp('id_verified_at')->nullable()
                ->comment('When identity was verified. Null = dormant.');
            $table->foreignId('id_verified_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete()
                ->comment('User who approved the verification');
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'id_verified_at']); // For transactable scope
        });
        
        // ── Partner Share Periods ───────────────────────────────────────
        // Historical record of what each partner's share was, and from when.
        // Never edited once a profit distribution has used them.
        Schema::create('partner_share_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('share_percent', 5, 3)
                ->comment('What their share became on effective_from');
            $table->date('effective_from')
                ->comment('Terms apply from this date forward');
            $table->text('note')->nullable()
                ->comment('Why the share changed');
            
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            
            $table->index(['business_id', 'partner_id', 'effective_from']);
            $table->index(['business_id', 'effective_from']); // For profit calc
        });
        
        // ── Partner Documents ───────────────────────────────────────────
        // Partnership deeds, agreements, amendments — anything that records
        // the terms or the relationship.
        Schema::create('partner_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            
            $table->string('kind', 20)
                ->comment('deed, agreement, amendment, other');
            $table->string('title')->nullable();
            
            // Stored on private disk
            $table->string('path')->comment('Storage path (private disk)');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size')->nullable()->comment('File size in bytes');
            
            $table->date('signed_on')->nullable()
                ->comment('Date the document was signed');
            
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'partner_id', 'kind']);
        });
        
        // ── Profit Distributions ────────────────────────────────────────
        // One run of "allocate the profit earned between these dates among
        // the partners according to their shares at the time".
        Schema::create('profit_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            
            $table->string('reference', 30)->comment('Distribution reference like DIST-202407');
            
            $table->date('period_from');
            $table->date('period_to');
            
            // Net profit for the period (from P&L)
            $table->bigInteger('net_profit_minor')->comment('Net profit in minor units');
            $table->string('currency', 3);
            
            // Amount actually distributed (may differ if some partners inactive)
            $table->bigInteger('distributed_minor')->comment('Total distributed in minor units');
            
            $table->text('note')->nullable();
            
            // Segmentation (for future multi-currency or multi-entity)
            $table->json('segments')->nullable();
            
            // Reversal
            $table->timestamp('reversed_at')->nullable()
                ->comment('When this distribution was reversed');
            $table->text('reversal_reason')->nullable();
            
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            
            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'period_from', 'period_to']);
            $table->index(['business_id', 'reversed_at']); // For live/reversed queries
        });
        
        // ── Profit Distribution Lines ───────────────────────────────────
        // What each partner received in one distribution run.
        Schema::create('profit_distribution_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profit_distribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            
            // Partner's share of the profit
            $table->bigInteger('amount_minor')->comment('Amount in minor units');
            
            // The working: how this amount was calculated
            $table->decimal('share_percent', 5, 3)
                ->comment('Share % used for this partner');
            $table->bigInteger('profit_used_minor')
                ->comment('Portion of profit this partner shared (for segmented calc)');
            
            // The transaction that posted it (DR Retained Earnings / CR Current Account)
            $table->foreignId('transaction_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete()
                ->comment('Ledger entry that posted this allocation');
            
            $table->json('segments')->nullable();
            
            $table->ulid('public_id')->unique();
            $table->timestamps();
            
            $table->index(['profit_distribution_id', 'partner_id']);
            $table->index(['business_id', 'partner_id']); // For partner history
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_distribution_lines');
        Schema::dropIfExists('profit_distributions');
        Schema::dropIfExists('partner_documents');
        Schema::dropIfExists('partner_share_periods');
        Schema::dropIfExists('partners');
    }
};
