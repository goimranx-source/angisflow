<?php

declare(strict_types=1);

/**
 * Task 34 Verification Script — Growth Marketing System
 * 
 * Tests the complete growth marketing system including:
 * - Marketing campaigns with A/B testing
 * - Loyalty programs with points and rewards
 * - Review incentive campaigns and automation
 * 
 * Run: php artisan tinker --execute="require 'D:\Povaly Group\Applications\prism-new\prism-erp\verification\verify_task34_growth.php'"
 */

use App\Domain\Growth\MarketingService;
use App\Domain\Growth\LoyaltyService;
use App\Domain\Growth\ReviewIncentiveService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\MarketingCampaign;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyMembership;
use App\Models\ReviewIncentiveCampaign;

echo "\n╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  Task 34: Growth Marketing System — Verification Script                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

// ── Setup tenant context ─────────────────────────────────────────────────────────

$account = Account::withoutGlobalScopes()->find(1);
$business = Business::withoutGlobalScopes()->whereNull('deleted_at')->where('account_id', 1)->first();

if (!$account || !$business) {
    die("✗ FAILED: Demo account or business not found\n");
}

$tenantContext = app(TenantContext::class);
$tenantContext->setAccount($account);
$tenantContext->setBusiness($business);

echo "✓ Tenant context set: Account {$account->id}, Business {$business->name}\n\n";

// ── Helper function ──────────────────────────────────────────────────────────────

$try = function (string $label, callable $fn) {
    try {
        $out = $fn();
        $display = '';
        if (is_object($out) && method_exists($out, 'toArray')) {
            $display = ' -> ' . get_class($out) . ' #' . ($out->id ?? 'new');
        } elseif ($out !== null && !is_bool($out)) {
            $display = " -> {$out}";
        }
        echo "  ✓ {$label}{$display}\n";
        return $out;
    } catch (Throwable $e) {
        echo "  ✗ REFUSED {$label} -> " . $e->getMessage() . "\n";
        return null;
    }
};

// ── Initialize services ──────────────────────────────────────────────────────────

$marketingService = app(MarketingService::class);
$loyaltyService = app(LoyaltyService::class);
$reviewService = app(ReviewIncentiveService::class);

echo "Services initialized\n\n";

// ══════════════════════════════════════════════════════════════════════════════════
// 1. MARKETING CAMPAIGNS
// ══════════════════════════════════════════════════════════════════════════════════

echo "━━━ 1. MARKETING CAMPAIGNS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$campaign = $try('Create email campaign with A/B testing', function () use ($marketingService) {
    return $marketingService->createCampaign([
        'name' => 'Summer Sale 2026',
        'description' => 'Email campaign promoting summer sale with discount codes',
        'type' => 'email',
        'configuration' => [
            'subject' => 'Get 25% Off Summer Collection',
            'from_email' => 'marketing@angisflow.test',
            'from_name' => 'Angisflow Marketing',
        ],
        'targeting_rules' => [
            'customer_segments' => ['active', 'high_value'],
            'min_order_count' => 2,
        ],
        'is_ab_test' => true,
        'ab_variants' => [
            'A' => ['subject' => 'Get 25% Off Summer Collection', 'discount_code' => 'SUMMER25'],
            'B' => ['subject' => 'Limited Time: Summer Sale 25% Off', 'discount_code' => 'SUMMER25B'],
        ],
        'traffic_split' => 0.5,
        'winning_metric' => 'conversions',
        'budget_amount' => 1000,
        'budget_currency' => 'USD',
        'budget_type' => 'total',
        'status' => 'draft',
        'created_by' => 1,
    ]);
});

if ($campaign) {
    $try('Update campaign configuration', function () use ($marketingService, $campaign) {
        return $marketingService->updateCampaign($campaign->id, [
            'scheduled_at' => now()->addDay(),
            'duration_days' => 7,
        ]);
    });

    $try('Launch campaign', function () use ($marketingService, $campaign) {
        return $marketingService->launchCampaign($campaign->id);
    });

    $try('Process delivery for test customer', function () use ($marketingService, $campaign) {
        $customer = Customer::first();
        if (!$customer) {
            throw new \Exception('No customers found for testing');
        }
        
        return $marketingService->processDelivery($campaign, $customer, [
            'variant' => 'A',
            'personalized_data' => ['customer_name' => $customer->name],
        ]);
    });

    $try('Track campaign attribution', function () use ($marketingService, $campaign) {
        $customer = Customer::first();
        $order = Order::where('customer_id', $customer->id)->first();
        
        if (!$order) {
            throw new \Exception('No order found for attribution test');
        }
        
        return $marketingService->trackAttribution($order, [
            'source' => 'email_campaign',
            'campaign_id' => $campaign->id,
            'touchpoint_data' => [
                'opened_at' => now()->subHours(2),
                'clicked_at' => now()->subHour(),
            ],
        ]);
    });
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════════
// 2. LOYALTY PROGRAMS
// ══════════════════════════════════════════════════════════════════════════════════

echo "━━━ 2. LOYALTY PROGRAMS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$program = $try('Create loyalty program with tiers', function () use ($loyaltyService) {
    return $loyaltyService->createProgram([
        'name' => 'Angisflow Rewards',
        'description' => 'Earn points on every purchase and unlock exclusive benefits',
        'type' => 'tier',
        'status' => 'active',
        'point_name' => 'Reward Point',
        'point_name_plural' => 'Reward Points',
        'earning_rules' => [
            'order_completion' => [
                'enabled' => true,
                'points_per_dollar' => 1,
                'minimum_order_value' => 10,
            ],
            'account_creation' => [
                'enabled' => true,
                'points' => 100,
            ],
            'birthday_bonus' => [
                'enabled' => true,
                'points' => 50,
            ],
        ],
        'redemption_rules' => [
            'minimum_redemption' => 100,
            'point_value' => 0.01, // $0.01 per point
            'allow_partial' => true,
        ],
        'tier_structure' => [
            'bronze' => [
                'threshold' => 0,
                'benefits' => ['5% discount on orders over $50'],
            ],
            'silver' => [
                'threshold' => 1000,
                'benefits' => ['10% discount on orders over $50', 'Free shipping'],
            ],
            'gold' => [
                'threshold' => 5000,
                'benefits' => ['15% discount on all orders', 'Free shipping', 'Early access to sales'],
            ],
        ],
        'referral_program' => [
            'enabled' => true,
            'referrer_bonus' => 200,
            'referee_bonus' => 100,
        ],
        'configuration' => [
            'points_expiry_days' => 365,
            'tier_retention_months' => 12,
        ],
        'started_at' => now(),
    ]);
});

$membership = null;
if ($program) {
    $membership = $try('Enroll customer in loyalty program', function () use ($loyaltyService, $program) {
        $customer = Customer::first();
        if (!$customer) {
            throw new \Exception('No customers found for enrollment');
        }
        
        return $loyaltyService->enrollCustomer($program->id, $customer->id);
    });

    if ($membership) {
        $try('Award points for order completion', function () use ($loyaltyService, $membership) {
            return $loyaltyService->awardPoints(
                $membership->id,
                150,
                'order_completion',
                null,
                'Points earned from order #12345'
            );
        });

        $try('Award bonus points', function () use ($loyaltyService, $membership) {
            return $loyaltyService->awardPoints(
                $membership->id,
                50,
                'bonus',
                null,
                'Birthday bonus points'
            );
        });

        $try('Check points balance', function () use ($membership) {
            $membership->refresh();
            return "Balance: {$membership->points_balance} points";
        });

        $try('Calculate tier upgrade', function () use ($loyaltyService, $membership, $program) {
            $membership->refresh();
            return $loyaltyService->checkTierEligibility($membership, $program);
        });

        $try('Redeem points for discount', function () use ($loyaltyService, $membership) {
            return $loyaltyService->redeemPoints(
                $membership->id,
                100,
                'discount',
                null,
                'Redeemed 100 points for $1 off next order'
            );
        });

        $try('Adjust points (admin correction)', function () use ($loyaltyService, $membership) {
            return $loyaltyService->adjustPoints(
                $membership->id,
                25,
                'Compensation for delayed delivery',
                1 // admin user id
            );
        });

        $try('REFUSE negative balance redemption', function () use ($loyaltyService, $membership) {
            $membership->refresh();
            return $loyaltyService->redeemPoints(
                $membership->id,
                $membership->points_balance + 1000, // More than available
                'discount',
                null,
                'Try to overdraw points'
            );
        });
    }

    $reward = $try('Create loyalty reward', function () use ($loyaltyService, $program) {
        return $loyaltyService->createReward([
            'program_id' => $program->id,
            'name' => 'Free Coffee',
            'description' => 'Redeem for a free coffee of your choice',
            'type' => 'product',
            'points_required' => 250,
            'monetary_value' => 5.00,
            'currency' => 'USD',
            'stock_quantity' => 100,
            'per_customer_limit' => 2,
            'is_active' => true,
            'available_from' => now(),
        ]);
    });

    if ($reward && $membership) {
        // Award enough points to redeem the reward
        $loyaltyService->awardPoints(
            $membership->id,
            300,
            'bonus',
            null,
            'Bonus points for reward testing'
        );

        $try('Redeem loyalty reward', function () use ($loyaltyService, $membership, $reward) {
            return $loyaltyService->redeemReward($membership->id, $reward->id);
        });
    }
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════════
// 3. REVIEW INCENTIVE CAMPAIGNS
// ══════════════════════════════════════════════════════════════════════════════════

echo "━━━ 3. REVIEW INCENTIVE CAMPAIGNS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$reviewCampaign = $try('Create review incentive campaign', function () use ($reviewService) {
    return $reviewService->createCampaign([
        'name' => 'Post-Purchase Review Campaign',
        'description' => 'Automated review requests sent 5 days after order delivery',
        'status' => 'active',
        'trigger_rules' => [
            'trigger_event' => 'order_delivered',
            'delay_days' => 5,
            'order_status' => 'delivered',
            'minimum_order_value' => 25,
        ],
        'delivery_channels' => ['email', 'sms'],
        'message_templates' => [
            'email' => [
                'subject' => 'How was your experience with {product_name}?',
                'body' => 'Hi {customer_name}, we hope you are enjoying your recent purchase...',
            ],
            'sms' => [
                'message' => 'Hi {customer_name}! Share your feedback and earn {incentive_value} points.',
            ],
        ],
        'incentive_configuration' => [
            'type' => 'points',
            'value' => 100,
            'conditions' => [
                'minimum_rating' => 1,
                'require_text' => false,
            ],
        ],
        'targeting_rules' => [
            'eligible_customers' => 'all',
            'exclude_recent_reviewers' => true,
        ],
        'frequency_limits' => [
            'max_per_customer_per_month' => 2,
            'min_days_between_requests' => 14,
        ],
        'started_at' => now(),
    ]);
});

$reviewRequest = null;
if ($reviewCampaign) {
    $try('Process order for review requests', function () use ($reviewService, $reviewCampaign) {
        $order = Order::with('customer')->whereNotNull('customer_id')->first();
        
        if (!$order) {
            throw new \Exception('No order found for review request testing');
        }
        
        $requests = $reviewService->processOrderForReviews($order);
        return "Created {$requests->count()} review request(s)";
    });

    $reviewRequest = $try('Create manual review request', function () use ($reviewService, $reviewCampaign) {
        $order = Order::with('customer')->whereNotNull('customer_id')->first();
        
        if (!$order || !$order->customer) {
            throw new \Exception('No order with customer found');
        }
        
        return $reviewService->createReviewRequest(
            $reviewCampaign,
            $order,
            $order->customer
        );
    });

    if ($reviewRequest) {
        $try('Send review request', function () use ($reviewService, $reviewRequest) {
            $sent = $reviewService->sendReviewRequest($reviewRequest->id);
            return $sent ? 'Review request sent' : 'Failed to send';
        });

        $try('Track email opened', function () use ($reviewService, $reviewRequest) {
            return $reviewService->trackEngagement($reviewRequest->token, 'opened');
        });

        $try('Track link clicked', function () use ($reviewService, $reviewRequest) {
            return $reviewService->trackEngagement($reviewRequest->token, 'clicked');
        });

        $try('Submit review with incentive', function () use ($reviewService, $reviewRequest) {
            return $reviewService->processReviewSubmission(
                $reviewRequest->token,
                [
                    'rating' => 5,
                    'text' => 'Great product! Fast delivery and excellent quality.',
                    'images' => [],
                    'product_ratings' => [],
                ]
            );
        });

        $try('REFUSE duplicate review submission', function () use ($reviewService, $reviewRequest) {
            return $reviewService->processReviewSubmission(
                $reviewRequest->token,
                [
                    'rating' => 4,
                    'text' => 'Trying to submit again',
                ]
            );
        });

        $try('Update campaign status', function () use ($reviewService, $reviewCampaign) {
            return $reviewService->toggleCampaign($reviewCampaign->id, 'paused');
        });

        $try('Get campaign performance', function () use ($reviewService, $reviewCampaign) {
            $reviewCampaign->refresh();
            return sprintf(
                "Sent: %d, Completed: %d, Rate: %.1f%%",
                $reviewCampaign->total_sent ?? 0,
                $reviewCampaign->total_completed ?? 0,
                $reviewCampaign->conversion_rate ?? 0
            );
        });
    }
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════════
// 4. INTEGRATION & ANALYTICS
// ══════════════════════════════════════════════════════════════════════════════════

echo "━━━ 4. INTEGRATION & ANALYTICS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$try('Count marketing campaigns', function () {
    $count = MarketingCampaign::count();
    return "{$count} campaign(s)";
});

$try('Count loyalty programs', function () {
    $count = LoyaltyProgram::count();
    return "{$count} program(s)";
});

$try('Count loyalty memberships', function () {
    $count = LoyaltyMembership::count();
    return "{$count} member(s)";
});

$try('Count review campaigns', function () {
    $count = ReviewIncentiveCampaign::count();
    return "{$count} review campaign(s)";
});

$try('Calculate total points issued', function () {
    $points = \App\Models\LoyaltyTransaction::where('type', 'earned')->sum('points');
    return "{$points} total points issued";
});

$try('Calculate total points redeemed', function () {
    $points = abs(\App\Models\LoyaltyTransaction::where('type', 'redeemed')->sum('points'));
    return "{$points} total points redeemed";
});

$try('Check review completion rate', function () {
    $total = \App\Models\ReviewRequest::whereIn('status', ['sent', 'completed'])->count();
    $completed = \App\Models\ReviewRequest::where('status', 'completed')->count();
    
    if ($total === 0) {
        return "No requests sent yet";
    }
    
    $rate = round(($completed / $total) * 100, 1);
    return "{$completed}/{$total} completed ({$rate}%)";
});

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════════
// CLEANUP
// ══════════════════════════════════════════════════════════════════════════════════

echo "━━━ CLEANUP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$try('Delete test campaign', function () use ($campaign) {
    if ($campaign) {
        $campaign->delete();
        return 'Campaign deleted';
    }
    return 'No campaign to delete';
});

$try('Delete test loyalty program', function () use ($program) {
    if ($program) {
        $program->delete();
        return 'Program deleted';
    }
    return 'No program to delete';
});

$try('Delete test review campaign', function () use ($reviewCampaign) {
    if ($reviewCampaign) {
        $reviewCampaign->delete();
        return 'Review campaign deleted';
    }
    return 'No review campaign to delete';
});

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  Task 34 Verification Complete                                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
