<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intelligence: AI-powered analytics, dashboards, reports, alerts, and forecasting.
 *
 * The intelligence pillar provides business insights through data analysis,
 * conversational AI, custom dashboards, anomaly detection, and predictive analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Custom Dashboards
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_shared')->default(false); // Share with team
            $table->json('layout')->nullable(); // Widget positions and sizes

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_shared']);
        });

        // Dashboard Widgets/KPIs
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40); // kpi, chart, table, goal, etc.
            $table->string('title');
            $table->string('metric'); // revenue, orders, customers, etc.
            $table->string('data_source', 40); // orders, products, customers, etc.
            $table->json('filters')->nullable(); // Date range, status, etc.
            $table->json('config')->nullable(); // Chart type, colors, etc.
            
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedTinyInteger('width')->default(4); // Grid cols (1-12)
            $table->unsignedTinyInteger('height')->default(2); // Grid rows

            $table->timestamps();

            $table->index(['dashboard_id', 'position']);
        });

        // AI Reports
        Schema::create('ai_reports', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->string('title');
            $table->string('report_type', 40); // sales, inventory, financial, custom
            $table->text('query')->nullable(); // Natural language query
            
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            
            $table->json('insights')->nullable(); // AI-generated insights
            $table->json('recommendations')->nullable(); // AI suggestions
            $table->json('data')->nullable(); // Report data
            
            $table->string('status', 20)->default('generating'); // generating, completed, failed
            $table->text('error')->nullable();
            
            $table->boolean('is_scheduled')->default(false);
            $table->string('schedule_frequency', 20)->nullable(); // daily, weekly, monthly
            $table->timestamp('last_generated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'report_type']);
            $table->index('created_by');
        });

        // Alerts and Anomalies
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('metric'); // revenue, stock_level, order_volume, etc.
            $table->string('condition', 20); // above, below, equals, changes_by
            $table->decimal('threshold', 20, 2);
            $table->string('comparison', 20)->default('value'); // value, percentage, trend
            
            $table->string('frequency', 20)->default('realtime'); // realtime, hourly, daily
            $table->string('severity', 20)->default('medium'); // low, medium, high, critical
            
            $table->json('notification_channels')->nullable(); // email, slack, sms
            $table->json('recipients')->nullable(); // User IDs or emails
            
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->index('metric');
        });

        // Alert Triggers (history)
        Schema::create('alert_triggers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();

            $table->decimal('actual_value', 20, 2);
            $table->decimal('threshold_value', 20, 2);
            $table->string('severity', 20);
            $table->text('message');
            $table->json('metadata')->nullable();
            
            $table->string('status', 20)->default('new'); // new, acknowledged, resolved
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->timestamp('acknowledged_at')->nullable();
            
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->index(['alert_rule_id', 'triggered_at']);
            $table->index('status');
        });

        // Forecasting Models
        Schema::create('forecast_models', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('type', 40); // revenue, demand, inventory, cashflow
            $table->string('target_metric'); // What we're forecasting
            $table->string('horizon', 20); // 1_month, 3_months, 6_months, 1_year
            $table->string('granularity', 20)->default('daily'); // daily, weekly, monthly
            
            $table->json('input_features')->nullable(); // Which data to use
            $table->json('model_config')->nullable(); // Algorithm parameters
            
            $table->string('status', 20)->default('active'); // active, training, paused
            $table->decimal('accuracy_score', 5, 2)->nullable(); // 0-100%
            $table->timestamp('last_trained_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'type']);
        });

        // Forecast Results
        Schema::create('forecast_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_model_id')->constrained()->cascadeOnDelete();

            $table->date('forecast_date');
            $table->decimal('predicted_value', 20, 2);
            $table->decimal('lower_bound', 20, 2)->nullable(); // 95% confidence interval
            $table->decimal('upper_bound', 20, 2)->nullable();
            $table->decimal('actual_value', 20, 2)->nullable(); // When date passes
            $table->decimal('accuracy', 5, 2)->nullable(); // How close was prediction

            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['forecast_model_id', 'forecast_date']);
        });

        // Ask Angisflow (Conversational AI queries)
        Schema::create('ai_queries', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();

            $table->text('question'); // Natural language query
            $table->text('answer')->nullable(); // AI response
            $table->json('data')->nullable(); // Supporting data/charts
            $table->string('query_type', 40)->nullable(); // metric, comparison, trend, etc.
            
            $table->string('status', 20)->default('processing'); // processing, completed, failed
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('processing_time_ms')->nullable();
            
            $table->boolean('was_helpful')->nullable(); // User feedback
            $table->text('feedback')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_queries');
        Schema::dropIfExists('forecast_results');
        Schema::dropIfExists('forecast_models');
        Schema::dropIfExists('alert_triggers');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('ai_reports');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
