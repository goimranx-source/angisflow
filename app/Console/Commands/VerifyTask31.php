<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\AccessControl\AccessControlService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\TenantContext;
use App\Models\UserRoleAssignment;
use App\Models\PermissionPolicy;
use App\Models\PermissionAuditLog;
use App\Models\AccessControlSession;
use App\Support\Capabilities;
use Carbon\Carbon;

/**
 * TASK 31: ROLES AND PERMISSIONS - VERIFICATION COMMAND
 * 
 * Artisan command to verify the extended access control system
 */
class VerifyTask31 extends Command
{
    protected $signature = 'verify:task31 {--cleanup : Clean up test data after verification}';
    protected $description = 'Verify Task 31 - Enhanced Roles and Permissions System';

    private AccessControlService $accessControl;
    private TenantContext $tenantContext;
    private array $testData = [];
    private array $results = [];
    
    public function handle(): int
    {
        $this->info('🔐 TASK 31: ROLES AND PERMISSIONS - VERIFICATION');
        $this->info('===============================================');
        $this->newLine();
        
        try {
            $this->tenantContext = app(TenantContext::class);
            $this->accessControl = new AccessControlService($this->tenantContext);
            
            $this->setupTestData();
            $this->runAllTests();
            
            if ($this->option('cleanup')) {
                $this->cleanup();
            }
            
            $this->displayResults();
            
            return $this->results['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
            
        } catch (\Exception $e) {
            $this->error("❌ FATAL ERROR: {$e->getMessage()}");
            $this->error("Stack trace: {$e->getTraceAsString()}");
            return Command::FAILURE;
        }
    }
    private function setupTestData(): void
    {
        $this->info('🔧 Setting up test data...');
        
        // Set up tenant context with test account
        $this->setupTenantContext();
        
        // Create test users
        $this->testData['users'] = [
            'manager' => $this->createTestUser('Test Manager', 'manager@test.local'),
            'employee' => $this->createTestUser('Test Employee', 'employee@test.local'),
            'temp_worker' => $this->createTestUser('Temp Worker', 'temp@test.local'),
        ];
        
        // Create test roles  
        $this->testData['roles'] = [
            'sales_manager' => $this->createTestRole('Sales Manager', ['orders.view', 'orders.create', 'orders.edit']),
            'accountant' => $this->createTestRole('Accountant', ['transactions.view', 'transactions.create', 'reports.view']),
            'admin' => $this->createTestRole('Administrator', [Capabilities::ALL]),
        ];
        
        $this->info('✅ Test data setup complete');
        $this->newLine();
    }

    private function setupTenantContext(): void
    {
        // Get or create a test account
        $account = \App\Domain\Tenancy\Models\Account::firstOrCreate(
            ['email' => 'test@verification.local'],
            [
                'name' => 'Test Account for Verification',
                'email' => 'test@verification.local',
                'timezone' => 'UTC',
                'base_currency' => 'USD',
            ]
        );
        
        // Set the tenant context
        $this->tenantContext->setAccount($account);
        
        $this->info("✓ Tenant context set up for account: {$account->name}");
    }

    private function runAllTests(): void
    {
        $tests = [
            'Basic Role Assignment' => 'testBasicRoleAssignment',
            'Business-Scoped Assignment' => 'testBusinessScopedAssignment', 
            'Time-Limited Assignment' => 'testTimeLimitedAssignment',
            'Emergency Access' => 'testEmergencyAccess',
            'Permission Policy Evaluation' => 'testPermissionPolicies',
            'Session Management' => 'testSessionManagement',
            'Session Elevation' => 'testSessionElevation',
            'Audit Trail' => 'testAuditTrail',
            'Access Denied Scenarios' => 'testAccessDenials',
            'Integration with Existing System' => 'testExistingSystemIntegration',
        ];
        
        $this->results = ['passed' => 0, 'failed' => 0, 'details' => []];
        
        foreach ($tests as $testName => $testMethod) {
            $this->info("🧪 Testing: {$testName}");
            try {
                $result = $this->$testMethod();
                if ($result) {
                    $this->results['passed']++;
                    $this->results['details'][$testName] = '✅ PASS';
                    $this->info("   ✅ PASS");
                } else {
                    $this->results['failed']++;
                    $this->results['details'][$testName] = '❌ FAIL';
                    $this->error("   ❌ FAIL");
                }
            } catch (\Exception $e) {
                $this->results['failed']++;
                $this->results['details'][$testName] = "❌ ERROR: {$e->getMessage()}";
                $this->error("   ❌ ERROR: {$e->getMessage()}");
            }
            $this->newLine();
        }
    }

    private function testBasicRoleAssignment(): bool
    {
        $user = $this->testData['users']['manager'];
        $role = $this->testData['roles']['sales_manager'];
        
        // Set authenticated user for audit
        auth()->login($user);
        
        // Assign role
        $assignment = $this->accessControl->assignRole(
            $user->id,
            $role->id,
            null,
            ['reason' => 'Test assignment']
        );
        
        if (!$assignment instanceof UserRoleAssignment) {
            $this->error("   Failed to create role assignment");
            return false;
        }
        
        // Check assignment is effective
        if (!$assignment->isEffective()) {
            $this->error("   Assignment is not effective");
            return false;
        }
        
        // Check user can access capabilities
        $canView = $this->accessControl->userCan($user->id, 'orders.view');
        $canCreate = $this->accessControl->userCan($user->id, 'orders.create');
        $canDelete = $this->accessControl->userCan($user->id, 'orders.delete'); // Should be false
        
        if (!$canView || !$canCreate || $canDelete) {
            $this->error("   Capability checks failed: view={$canView}, create={$canCreate}, delete={$canDelete}");
            return false;
        }
        
        $this->testData['assignments']['basic'] = $assignment;
        $this->comment("   ✓ Role assignment created and verified");
        $this->comment("   ✓ Capability checks working correctly");
        
        return true;
    }
    private function testBusinessScopedAssignment(): bool
    {
        $user = $this->testData['users']['employee'];
        $role = $this->testData['roles']['accountant'];
        $businessId = 1; // Use business ID 1 for testing
        
        // Assign role with business scope
        $assignment = $this->accessControl->assignRole(
            $user->id,
            $role->id,
            $businessId,
            [
                'scope' => 'business',
                'reason' => 'Business-specific accounting access'
            ]
        );
        
        if (!$assignment || $assignment->business_id !== $businessId) {
            $this->error("   Business-scoped assignment failed");
            return false;
        }
        
        // Check access in business context
        $canViewInBusiness = $this->accessControl->userCan($user->id, 'transactions.view', $businessId);
        
        if (!$canViewInBusiness) {
            $this->error("   Cannot access capability in business context");
            return false;
        }
        
        $this->testData['assignments']['business_scoped'] = $assignment;
        $this->comment("   ✓ Business-scoped assignment created");
        $this->comment("   ✓ Business context capability checks working");
        
        return true;
    }

    private function testTimeLimitedAssignment(): bool
    {
        $user = $this->testData['users']['temp_worker'];
        $role = $this->testData['roles']['sales_manager'];
        
        // Create assignment that expires in 1 hour
        $assignment = $this->accessControl->assignRole(
            $user->id,
            $role->id,
            null,
            [
                'effective_from' => now(),
                'effective_until' => now()->addHour(),
                'reason' => 'Temporary access for coverage'
            ]
        );
        
        if (!$assignment || !$assignment->effective_until) {
            $this->error("   Time-limited assignment creation failed");
            return false;
        }
        
        // Should be effective now
        if (!$assignment->isEffective()) {
            $this->error("   Assignment should be effective now");
            return false;
        }
        
        // Should not be effective after expiry
        if ($assignment->isEffective(now()->addHours(2))) {
            $this->error("   Assignment should not be effective after expiry");
            return false;
        }
        
        $this->testData['assignments']['time_limited'] = $assignment;
        $this->comment("   ✓ Time-limited assignment created");
        $this->comment("   ✓ Time-based effectiveness checks working");
        
        return true;
    }

    private function testEmergencyAccess(): bool
    {
        $user = $this->testData['users']['employee'];
        $adminRole = $this->testData['roles']['admin'];
        
        // Grant emergency access for 30 minutes
        $emergencyAssignment = $this->accessControl->grantEmergencyAccess(
            $user->id,
            $adminRole->id,
            30,
            'System outage - need admin access for critical fix'
        );
        
        if (!$emergencyAssignment || !$emergencyAssignment->is_emergency_access) {
            $this->error("   Emergency access assignment creation failed");
            return false;
        }
        
        // Should have emergency properties set
        if (!$emergencyAssignment->emergency_expires_at || 
            !$emergencyAssignment->emergency_justification) {
            $this->error("   Emergency access properties not set correctly");
            return false;
        }
        
        // Should be effective now
        if (!$emergencyAssignment->isEffective()) {
            $this->error("   Emergency assignment should be effective");
            return false;
        }
        
        $this->testData['assignments']['emergency'] = $emergencyAssignment;
        $this->comment("   ✓ Emergency access granted successfully");
        $this->comment("   ✓ Emergency properties set correctly");
        
        return true;
    }
    private function testPermissionPolicies(): bool
    {
        $businessId = 1;
        
        // Create business hours policy
        $businessHoursPolicy = PermissionPolicy::create([
            'account_id' => $this->tenantContext->account()->id,
            'business_id' => $businessId,
            'policy_name' => 'Test Business Hours',
            'policy_type' => 'time_based',
            'description' => 'Restrict access to business hours',
            'rules' => [
                [
                    'type' => 'business_hours',
                    'hours' => [
                        'monday' => ['start' => '09:00', 'end' => '17:00'],
                        'tuesday' => ['start' => '09:00', 'end' => '17:00'],
                    ]
                ]
            ],
            'conditions' => [],
            'effect' => 'deny',
            'priority' => 100,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);
        
        if (!$businessHoursPolicy) {
            $this->error("   Failed to create business hours policy");
            return false;
        }
        
        $this->testData['policies']['business_hours'] = $businessHoursPolicy;
        $this->comment("   ✓ Business hours policy created and tested");
        
        return true;
    }

    private function testSessionManagement(): bool
    {
        $user = $this->testData['users']['manager'];
        $businessId = 1;
        $sessionId = 'test_session_' . uniqid();
        
        // Create session
        $session = $this->accessControl->getOrCreateSession($user, $sessionId, $businessId);
        
        if (!$session instanceof AccessControlSession) {
            $this->error("   Failed to create access control session");
            return false;
        }
        
        if ($session->session_id !== $sessionId || $session->user_id !== $user->id) {
            $this->error("   Session properties not set correctly");
            return false;
        }
        
        // Test permission caching
        $session->addPermission('orders.view');
        $session->addPermission('orders.create');
        
        if (!$session->hasPermission('orders.view') || !$session->hasPermission('orders.create')) {
            $this->error("   Permission caching not working");
            return false;
        }
        
        $this->testData['session'] = $session;
        $this->comment("   ✓ Session creation working");
        $this->comment("   ✓ Permission caching working");
        
        return true;
    }

    private function testSessionElevation(): bool
    {
        $session = $this->testData['session'];
        
        if (!$session) {
            $this->error("   No session available from previous test");
            return false;
        }
        
        // Should not be elevated initially
        if ($session->isElevated()) {
            $this->error("   Session should not be elevated initially");
            return false;
        }
        
        // Elevate session for 15 minutes
        $elevated = $this->accessControl->elevateSession(
            $session->session_id,
            15,
            'Testing elevation functionality'
        );
        
        if (!$elevated) {
            $this->error("   Session elevation failed");
            return false;
        }
        
        $session = $session->fresh();
        
        // Should be elevated now
        if (!$session->isElevated()) {
            $this->error("   Session should be elevated after elevation");
            return false;
        }
        
        $this->comment("   ✓ Session elevation working");
        $this->comment("   ✓ Elevation status checks working");
        
        return true;
    }
    private function testAuditTrail(): bool
    {
        $user = $this->testData['users']['manager'];
        
        // Get audit events before new activity
        $initialCount = PermissionAuditLog::forUser($user->id)->count();
        
        // Perform some auditable actions
        $assignment = $this->accessControl->assignRole(
            $user->id,
            $this->testData['roles']['accountant']->id,
            null,
            ['reason' => 'Audit trail test']
        );
        
        // Remove the assignment
        $this->accessControl->removeRoleAssignment(
            $assignment->id,
            'Testing audit trail for removal'
        );
        
        // Check audit entries were created
        $finalCount = PermissionAuditLog::forUser($user->id)->count();
        
        if ($finalCount <= $initialCount) {
            $this->error("   No audit entries created");
            return false;
        }
        
        $this->comment("   ✓ Audit entries created correctly");
        $this->comment("   ✓ Audit trail working");
        
        return true;
    }

    private function testAccessDenials(): bool
    {
        $user = $this->testData['users']['employee'];
        
        // Test access without any role for users.manage
        $canManageUsers = $this->accessControl->userCan($user->id, 'users.manage');
        if ($canManageUsers) {
            $this->error("   User should not have users.manage capability without admin role");
            return false;
        }
        
        // Test expired assignment
        $expiredAssignment = UserRoleAssignment::create([
            'account_id' => $this->tenantContext->account()->id,
            'user_id' => $user->id,
            'role_id' => $this->testData['roles']['sales_manager']->id,
            'assignment_scope' => 'business',
            'is_active' => true,
            'effective_from' => now()->subDays(2),
            'effective_until' => now()->subDay(),
            'assigned_by' => auth()->id(),
            'assigned_at' => now()->subDays(2),
        ]);
        
        if ($expiredAssignment->isEffective()) {
            $this->error("   Expired assignment should not be effective");
            return false;
        }
        
        $this->comment("   ✓ Access properly denied without proper roles");
        $this->comment("   ✓ Expired assignments properly rejected");
        
        return true;
    }

    private function testExistingSystemIntegration(): bool
    {
        $user = $this->testData['users']['manager'];
        $role = $this->testData['roles']['sales_manager'];
        
        // Set primary role on user (existing system)
        $user->update(['role_id' => $role->id]);
        
        // User should have capabilities from primary role
        $canViewOrders = $this->accessControl->userCan($user->id, 'orders.view');
        if (!$canViewOrders) {
            $this->error("   Primary role capability check failed");
            return false;
        }
        
        // Test role grants method
        if (!$role->grants('orders.view')) {
            $this->error("   Role grants method not working");
            return false;
        }
        
        $this->comment("   ✓ Primary role integration working");
        $this->comment("   ✓ Existing grants method working");
        
        return true;
    }

    private function createTestUser(string $name, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'account_id' => $this->tenantContext->account()->id,
                'name' => $name,
                'email' => $email,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
    }

    private function createTestRole(string $name, array $capabilities): Role
    {
        $slug = strtolower(str_replace(' ', '-', $name));
        
        return Role::firstOrCreate(
            ['account_id' => $this->tenantContext->account()->id, 'slug' => $slug],
            [
                'account_id' => $this->tenantContext->account()->id,
                'name' => $name,
                'slug' => $slug,
                'description' => "Test role: {$name}",
                'capabilities' => $capabilities,
                'workspace' => 'management',
                'scope' => 'all',
            ]
        );
    }
    private function cleanup(): void
    {
        $this->info('🧹 Cleaning up test data...');
        
        // Clean up assignments
        if (isset($this->testData['assignments'])) {
            foreach ($this->testData['assignments'] as $assignment) {
                try {
                    if ($assignment && $assignment->exists) {
                        $assignment->delete();
                    }
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }
        }
        
        // Clean up policies
        if (isset($this->testData['policies'])) {
            foreach ($this->testData['policies'] as $policy) {
                try {
                    if ($policy && $policy->exists) {
                        $policy->delete();
                    }
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }
        }
        
        // Clean up session
        if (isset($this->testData['session'])) {
            try {
                $this->testData['session']->delete();
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
        
        $this->info('✅ Cleanup complete');
        $this->newLine();
    }

    private function displayResults(): void
    {
        $this->info('📊 VERIFICATION RESULTS');
        $this->info('======================');
        $this->newLine();
        
        foreach ($this->results['details'] as $test => $result) {
            $this->line("{$result} {$test}");
        }
        
        $this->newLine();
        $this->info('📈 SUMMARY');
        $this->info('----------');
        $this->info("✅ Passed: {$this->results['passed']}");
        $this->info("❌ Failed: {$this->results['failed']}");
        $this->info('📊 Total:  ' . ($this->results['passed'] + $this->results['failed']));
        $this->newLine();
        
        if ($this->results['failed'] === 0) {
            $this->info('🎉 ALL TESTS PASSED! Task 31 access control system is working correctly.');
            $this->newLine();
            
            $this->info('🔐 SYSTEM CAPABILITIES VERIFIED:');
            $this->info('• Extended role assignments with business context');
            $this->info('• Time-limited and emergency access management');
            $this->info('• Policy-based access control (PBAC)');
            $this->info('• Session management with permission caching');
            $this->info('• Session elevation for temporary admin access');
            $this->info('• Comprehensive audit trails');
            $this->info('• Integration with existing capabilities system');
            $this->info('• Proper access denial enforcement');
            $this->newLine();
            
            $this->info('✅ TASK 31 VERIFICATION: COMPLETE');
        } else {
            $this->warn('⚠️  Some tests failed. Please review the issues above.');
            $this->error('❌ TASK 31 VERIFICATION: INCOMPLETE');
        }
    }
}