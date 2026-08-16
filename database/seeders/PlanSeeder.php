<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Seed the subscription plans.
     *
     * Creates a comprehensive set of plans with different features and limits.
     * First-time users get 15 days trial on any plan with $0 initial payment.
     */
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Perfect for solo entrepreneurs and small teams getting started',
                'price_minor' => 1900, // $19.00
                'currency' => 'USD',
                'interval' => Plan::INTERVAL_MONTHLY,
                'trial_days' => 15,
                'limits' => [
                    'workspaces' => 1,
                    'businesses_per_workspace' => 2,
                    'users' => 3,
                    'storage_gb' => 5,
                    'monthly_transactions' => 1000,
                ],
                'features' => [
                    'basic_reporting',
                    'email_support',
                    'mobile_app',
                ],
                'is_public' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'professional',
                'name' => 'Professional',
                'description' => 'For growing businesses that need more power and flexibility',
                'price_minor' => 4900, // $49.00
                'currency' => 'USD',
                'interval' => Plan::INTERVAL_MONTHLY,
                'trial_days' => 15,
                'limits' => [
                    'workspaces' => 3,
                    'businesses_per_workspace' => 10,
                    'users' => 15,
                    'storage_gb' => 50,
                    'monthly_transactions' => 10000,
                ],
                'features' => [
                    'basic_reporting',
                    'advanced_reporting',
                    'email_support',
                    'priority_support',
                    'mobile_app',
                    'api_access',
                    'custom_roles',
                    'audit_logs',
                ],
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'business',
                'name' => 'Business',
                'description' => 'For established businesses with complex needs',
                'price_minor' => 9900, // $99.00
                'currency' => 'USD',
                'interval' => Plan::INTERVAL_MONTHLY,
                'trial_days' => 15,
                'limits' => [
                    'workspaces' => 10,
                    'businesses_per_workspace' => Plan::UNLIMITED,
                    'users' => 50,
                    'storage_gb' => 200,
                    'monthly_transactions' => Plan::UNLIMITED,
                ],
                'features' => [
                    'basic_reporting',
                    'advanced_reporting',
                    'custom_reports',
                    'email_support',
                    'priority_support',
                    'phone_support',
                    'mobile_app',
                    'api_access',
                    'custom_roles',
                    'audit_logs',
                    'white_label',
                    'advanced_integrations',
                ],
                'is_public' => true,
                'sort_order' => 3,
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Unlimited everything for large organizations',
                'price_minor' => 29900, // $299.00
                'currency' => 'USD',
                'interval' => Plan::INTERVAL_MONTHLY,
                'trial_days' => 15,
                'limits' => [
                    'workspaces' => Plan::UNLIMITED,
                    'businesses_per_workspace' => Plan::UNLIMITED,
                    'users' => Plan::UNLIMITED,
                    'storage_gb' => Plan::UNLIMITED,
                    'monthly_transactions' => Plan::UNLIMITED,
                ],
                'features' => [
                    'basic_reporting',
                    'advanced_reporting',
                    'custom_reports',
                    'email_support',
                    'priority_support',
                    'phone_support',
                    'dedicated_account_manager',
                    'mobile_app',
                    'api_access',
                    'custom_roles',
                    'audit_logs',
                    'white_label',
                    'advanced_integrations',
                    'sso',
                    'custom_sla',
                    'onboarding_support',
                ],
                'is_public' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['code' => $planData['code']],
                $planData
            );
        }

        $this->command->info('Plans seeded successfully!');
    }
}
