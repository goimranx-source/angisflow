import { useState } from 'react';
import { useNavigate } from 'react-router';

import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Icon } from '@/components/ui/Icon';
import { useApiForm } from '@/hooks/useApiForm';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';

type OnboardingStep = 'account' | 'workspace' | 'business' | 'plan' | 'payment';

type PlanData = {
    id: string;
    code: string;
    name: string;
    price_minor: number;
    currency: string;
    interval: string;
    trial_days: number;
    limits: Record<string, number>;
    features: string[];
};

/**
 * Multi-step onboarding wizard.
 *
 * Guides new users through:
 * 1. Creating their account
 * 2. Setting up their first workspace
 * 3. Adding team members (optional)
 * 4. Creating their first business
 * 5. Choosing a plan
 * 6. Entering payment details ($0 for trial)
 *
 * Users can skip optional steps and complete them later from the dashboard.
 */
export default function Onboarding() {
    const { apply } = useSession();
    const navigate = useNavigate();
    const [step, setStep] = useState<OnboardingStep>('account');
    const [plans, setPlans] = useState<PlanData[]>([]);
    const [selectedPlan, setSelectedPlan] = useState<string | null>(null);
    const [workspaceData, setWorkspaceData] = useState<{ id?: string; name: string } | null>(null);

    useDocumentTitle('Get Started');

    // Account creation form
    const accountForm = useApiForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? '',
    });

    // Workspace creation form
    const workspaceForm = useApiForm({
        name: '',
        team_members: [{ email: '', role: 'manager' }],
    });

    // Business creation form
    const businessForm = useApiForm({
        name: '',
        short_code: '',
        currency: 'USD',
        country: '',
    });

    // Payment form
    const paymentForm = useApiForm({
        plan_id: '',
        skip_trial: false,
    });

    const submitAccount = async (event: React.FormEvent) => {
        event.preventDefault();

        await accountForm.post<{ redirect?: string; boot: BootPayload; plans: PlanData[] }>(
            '/auth/register',
            {
                onSuccess: (result) => {
                    setPlans(result.plans || []);
                    apply(result.boot);
                    setStep('workspace');
                },
            }
        );
    };

    const submitWorkspace = async (event: React.FormEvent) => {
        event.preventDefault();

        try {
            const result = await api.post<{ workspace: { id: string; name: string } }>(
                '/onboarding/workspace',
                workspaceForm.data
            );
            setWorkspaceData(result.workspace);
            toast.success('Workspace created successfully!');
            setStep('business');
        } catch (error) {
            toast.error((error as { message?: string })?.message ?? 'Failed to create workspace');
        }
    };

    const submitBusiness = async (event: React.FormEvent) => {
        event.preventDefault();

        try {
            await api.post('/onboarding/business', {
                ...businessForm.data,
                workspace_id: workspaceData?.id,
            });
            toast.success('Business created successfully!');
            setStep('plan');
        } catch (error) {
            toast.error((error as { message?: string })?.message ?? 'Failed to create business');
        }
    };

    const submitPayment = async (event: React.FormEvent) => {
        event.preventDefault();

        try {
            const result = await api.post<{ redirect: string; boot: BootPayload }>(
                '/onboarding/subscribe',
                {
                    ...paymentForm.data,
                    plan_id: selectedPlan,
                }
            );
            apply(result.boot);
            toast.success('Subscription activated! Welcome to Prism.');
            navigate(result.redirect || '/home');
        } catch (error) {
            toast.error((error as { message?: string })?.message ?? 'Failed to process subscription');
        }
    };

    const skipToEnd = () => {
        navigate('/home');
        toast.info('You can complete your setup from the dashboard');
    };

    const progress = {
        account: 20,
        workspace: 40,
        business: 60,
        plan: 80,
        payment: 100,
    }[step];

    return (
        <GuestLayout
            title={
                {
                    account: 'Create your account',
                    workspace: 'Set up your workspace',
                    business: 'Add your first business',
                    plan: 'Choose your plan',
                    payment: 'Start your trial',
                }[step]
            }
            description={
                {
                    account: 'Get started with Prism in minutes',
                    workspace: 'A workspace organizes your businesses and teams',
                    business: 'A business is one set of books — its own orders, stock, and figures',
                    plan: 'All plans include a 15-day free trial',
                    payment: "No payment required now. We'll remind you before your trial ends.",
                }[step]
            }
        >
            {/* Progress Bar */}
            <div className="mb-8">
                <div className="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                    <div
                        className="h-full bg-[var(--color-brand)] transition-all duration-300 ease-out"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                <p className="mt-2 text-center text-xs text-gray-600">
                    Step {Object.keys({ account: 1, workspace: 2, business: 3, plan: 4, payment: 5 }).indexOf(step) + 1} of 5
                </p>
            </div>

            {/* Step Content */}
            {step === 'account' && (
                <form onSubmit={submitAccount} className="space-y-4" noValidate>
                    <Field
                        label="Your name"
                        error={accountForm.errors.name}
                        type="text"
                        value={accountForm.data.name}
                        onChange={(event) => accountForm.set('name', event.target.value)}
                        autoComplete="name"
                        autoFocus
                        required
                    />

                    <Field
                        label="Email"
                        error={accountForm.errors.email}
                        type="email"
                        value={accountForm.data.email}
                        onChange={(event) => accountForm.set('email', event.target.value)}
                        autoComplete="username"
                        required
                    />

                    <Field
                        label="Password"
                        error={accountForm.errors.password}
                        hint="At least 10 characters"
                        type="password"
                        value={accountForm.data.password}
                        onChange={(event) => accountForm.set('password', event.target.value)}
                        autoComplete="new-password"
                        required
                    />

                    <Field
                        label="Confirm password"
                        error={accountForm.errors.password_confirmation}
                        type="password"
                        value={accountForm.data.password_confirmation}
                        onChange={(event) => accountForm.set('password_confirmation', event.target.value)}
                        autoComplete="new-password"
                        required
                    />

                    <Button type="submit" busy={accountForm.processing} className="w-full">
                        Continue
                    </Button>
                </form>
            )}

            {step === 'workspace' && (
                <form onSubmit={submitWorkspace} className="space-y-4" noValidate>
                    <Field
                        label="Workspace name"
                        error={workspaceForm.errors.name}
                        hint="e.g., 'Acme Corp' or 'My Businesses'"
                        type="text"
                        value={workspaceForm.data.name}
                        onChange={(event) => workspaceForm.set('name', event.target.value)}
                        autoFocus
                        required
                    />

                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div className="flex items-start gap-3">
                            <Icon name="info" size={20} className="mt-0.5 flex-none text-[var(--color-brand)]" />
                            <div className="text-sm text-gray-600">
                                <p className="font-medium text-gray-900">You can add team members later</p>
                                <p className="mt-1">
                                    Complete your workspace setup first, then invite team members from your dashboard.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setStep('account')}
                            className="flex-1"
                        >
                            Back
                        </Button>
                        <Button type="submit" busy={workspaceForm.processing} className="flex-1">
                            Continue
                        </Button>
                    </div>
                </form>
            )}

            {step === 'business' && (
                <form onSubmit={submitBusiness} className="space-y-4" noValidate>
                    <Field
                        label="Business name"
                        error={businessForm.errors.name}
                        hint="This will appear on your reports and invoices"
                        type="text"
                        value={businessForm.data.name}
                        onChange={(event) => businessForm.set('name', event.target.value)}
                        autoFocus
                        required
                    />

                    <Field
                        label="Short code"
                        error={businessForm.errors.short_code}
                        hint="2-6 characters, used in SKUs and references"
                        type="text"
                        value={businessForm.data.short_code}
                        onChange={(event) => businessForm.set('short_code', event.target.value.toUpperCase())}
                        maxLength={6}
                        required
                    />

                    <Field
                        label="Base currency"
                        error={businessForm.errors.currency}
                        type="select"
                        value={businessForm.data.currency}
                        onChange={(event) => businessForm.set('currency', event.target.value)}
                        required
                    >
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="GBP">GBP - British Pound</option>
                        <option value="BDT">BDT - Bangladeshi Taka</option>
                        <option value="INR">INR - Indian Rupee</option>
                    </Field>

                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setStep('workspace')}
                            className="flex-1"
                        >
                            Back
                        </Button>
                        <Button type="submit" busy={businessForm.processing} className="flex-1">
                            Continue
                        </Button>
                    </div>

                    <button
                        type="button"
                        onClick={skipToEnd}
                        className="w-full text-center text-sm text-gray-600 hover:text-gray-900"
                    >
                        Skip for now, I'll add it later
                    </button>
                </form>
            )}

            {step === 'plan' && (
                <div className="space-y-6">
                    <div className="grid gap-4">
                        {plans.map((plan) => (
                            <button
                                key={plan.id}
                                type="button"
                                onClick={() => {
                                    setSelectedPlan(plan.id);
                                    setStep('payment');
                                }}
                                className={`rounded-lg border-2 p-6 text-left transition-all ${
                                    selectedPlan === plan.id
                                        ? 'border-[var(--color-brand)] bg-[var(--color-brand-subtle)]'
                                        : 'border-gray-200 hover:border-gray-300'
                                }`}
                            >
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h3 className="text-lg font-semibold text-gray-900">{plan.name}</h3>
                                        <p className="mt-2 text-2xl font-bold text-gray-900">
                                            ${(plan.price_minor / 100).toFixed(0)}
                                            <span className="text-base font-normal text-gray-600">/month</span>
                                        </p>
                                        <ul className="mt-4 space-y-2">
                                            {plan.features.slice(0, 4).map((feature) => (
                                                <li key={feature} className="flex items-center gap-2 text-sm text-gray-600">
                                                    <Icon name="check" size={14} weight="bold" className="text-[var(--color-brand)]" />
                                                    {feature.replace(/_/g, ' ')}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                    <div className={`rounded-full p-1 ${selectedPlan === plan.id ? 'bg-[var(--color-brand)]' : 'bg-gray-200'}`}>
                                        <Icon
                                            name="check"
                                            size={16}
                                            weight="bold"
                                            className={selectedPlan === plan.id ? 'text-white' : 'text-transparent'}
                                        />
                                    </div>
                                </div>
                            </button>
                        ))}
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setStep('business')}
                        className="w-full"
                    >
                        Back
                    </Button>
                </div>
            )}

            {step === 'payment' && (
                <form onSubmit={submitPayment} className="space-y-6" noValidate>
                    <div className="rounded-lg border-2 border-[var(--color-brand)] bg-[var(--color-brand-subtle)] p-6">
                        <div className="flex items-start gap-3">
                            <Icon name="gift" size={24} className="mt-1 flex-none text-[var(--color-brand)]" />
                            <div>
                                <h3 className="font-semibold text-gray-900">Your 15-day free trial</h3>
                                <p className="mt-2 text-sm text-gray-700">
                                    No payment required now. You'll have full access to all features during your trial.
                                    We'll email you a reminder before it ends.
                                </p>
                                <div className="mt-4 rounded-lg bg-white p-4">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-gray-600">Due today</span>
                                        <span className="text-2xl font-bold text-gray-900">$0.00</span>
                                    </div>
                                    <div className="mt-2 flex items-center justify-between text-xs text-gray-500">
                                        <span>After trial ends</span>
                                        <span>
                                            ${selectedPlan && plans.find(p => p.id === selectedPlan) 
                                                ? (plans.find(p => p.id === selectedPlan)!.price_minor / 100).toFixed(2) 
                                                : '0.00'}/month
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setStep('plan')}
                            className="flex-1"
                        >
                            Back
                        </Button>
                        <Button type="submit" busy={paymentForm.processing} className="flex-1">
                            Start my free trial
                        </Button>
                    </div>
                </form>
            )}
        </GuestLayout>
    );
}
