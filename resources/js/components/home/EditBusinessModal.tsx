import { useState, useMemo } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Form/Input';
import { Select } from '@/components/ui/Form/Select';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';

type Category = {
    id: number;
    key: string;
    name: string;
    icon: string | null;
    summary: string | null;
    modules: number | null;
    children: Category[];
};

type Country = {
    code: string;
    name: string;
    currency: string;
    timezones: string[];
};

type CountryPayload = { data: Country[]; currencies: string[] };

export function EditBusinessModal({
    business,
    onClose,
    onUpdated,
}: {
    business: {
        id: string;
        name: string;
        short_code: string | null;
        currency: string;
        timezone?: string | null;
        country?: string | null;
        address?: string | null;
        phone?: string | null;
        email?: string | null;
        logo_url?: string | null;
        business_category_id?: number | null;
        categories?: Array<{
            id: number;
            name: string;
            key: string;
            icon: string | null;
        }>;
    };
    onClose: () => void;
    onUpdated: () => void;
}) {
    const [name, setName] = useState(business.name);
    const [shortCode, setShortCode] = useState(business.short_code ?? '');
    const [currency, setCurrency] = useState(business.currency);
    const [timezone, setTimezone] = useState(business.timezone ?? '');
    const [country, setCountry] = useState(business.country ?? '');
    const [address, setAddress] = useState(business.address ?? '');
    const [phone, setPhone] = useState(business.phone ?? '');
    const [email, setEmail] = useState(business.email ?? '');
    const [selectedCategoryIds, setSelectedCategoryIds] = useState<number[]>(
        business.categories?.map(cat => typeof cat.id === 'number' ? cat.id : parseInt(cat.id as unknown as string, 10)) ?? []
    );
    const [logoFiles, setLogoFiles] = useState<File[]>([]);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [showCategorySelector, setShowCategorySelector] = useState(false);

    const { refresh: refreshSession } = useSession();
    const queryClient = useQueryClient();

    // Fetch business categories
    const { data: categoriesData } = useQuery({
        queryKey: ['business-categories'],
        queryFn: ({ signal }) =>
            api.get<{ data: Category[] }>('/business-categories', { signal }),
        staleTime: 30 * 60 * 1000,
    });

    // Fetch countries and currencies
    const { data: countriesData } = useQuery({
        queryKey: ['countries'],
        queryFn: ({ signal }) => api.get<CountryPayload>('/workspaces/countries', { signal }),
        staleTime: 24 * 60 * 60 * 1000,
    });

    const categories = useMemo(() => categoriesData?.data ?? [], [categoriesData]);
    const countries = useMemo(() => countriesData?.data ?? [], [countriesData]);
    const currencies = useMemo(() => countriesData?.currencies ?? [], [countriesData]);
    
    // Find selected categories with their details
    const selectedCategories = useMemo(() => {
        const result: Array<{ category: Category; parent: Category | null }> = [];
        
        for (const catId of selectedCategoryIds) {
            // Check if it's a parent category
            const parent = categories.find(cat => cat.id === catId);
            if (parent) {
                result.push({ category: parent, parent: null });
                continue;
            }
            
            // Check if it's a child category
            for (const parent of categories) {
                const child = parent.children?.find(cat => cat.id === catId);
                if (child) {
                    result.push({ category: child, parent });
                    break;
                }
            }
        }
        
        return result;
    }, [selectedCategoryIds, categories]);

    const selectedCountry = useMemo(() => {
        return countries.find(c => c.code === country) ?? null;
    }, [country, countries]);

    const availableTimezones = useMemo(() => {
        return selectedCountry?.timezones ?? [];
    }, [selectedCountry]);

    const update = async () => {
        const trimmedName = name.trim();
        const trimmedCode = shortCode.trim().toUpperCase();

        if (trimmedName.length === 0 || busy) {
            return;
        }

        setBusy(true);
        setErrors({});

        try {
            // Create FormData for file upload
            const formData = new FormData();
            formData.append('name', trimmedName);
            formData.append('short_code', trimmedCode || '');
            formData.append('base_currency', currency);
            
            // Send category IDs as array - only if there are categories selected
            if (selectedCategoryIds.length > 0) {
                selectedCategoryIds.forEach((id, index) => {
                    formData.append(`category_ids[${index}]`, id.toString());
                });
            }
            
            if (country) formData.append('country', country);
            if (timezone) formData.append('timezone', timezone);
            if (address) formData.append('address', address);
            if (phone) formData.append('phone', phone);
            if (email) formData.append('email', email);
            
            // For Laravel file uploads, we sometimes need to use method override
            formData.append('_method', 'POST');

            if (logoFiles.length > 0) {
                formData.append('logo', logoFiles[0]!);
            } else if (removeLogo && business.logo_url) {
                formData.append('remove_logo', '1');
            }

            const result = await api.post<{ message: string }>(
                `/businesses/${business.id}`,
                formData,
            );

            /*
             * The books currency can change here, and it is the same column
             * Settings › Currency writes — so whichever of the two somebody
             * reaches for, the other is already true.
             *
             * But the client has to be told. Every money figure is keyed and
             * cached on the money scope in the boot payload (see
             * hooks/useMoney), so without a refresh the column changes and
             * every screen carries on drawing the currency just left until a
             * reload. Refreshing the session republishes the scope, which
             * changes the keys and the URLs, and the stale answers become
             * unreachable rather than merely out of date.
             */
            await refreshSession();
            await queryClient.invalidateQueries();

            toast.success(result.message);
            onClose();
            onUpdated();
        } catch (problem) {
            const error = problem as { message?: string; errors?: Record<string, string[]> };

            if (error.errors) {
                const formattedErrors: Record<string, string> = {};
                Object.entries(error.errors).forEach(([key, messages]) => {
                    if (messages[0]) formattedErrors[key] = messages[0];
                });
                setErrors(formattedErrors);
                toast.error('Validation failed. Please check the form.');
            } else {
                const errorMessage = error.message ?? 'That could not be updated.';
                toast.error(errorMessage);
                setErrors({ form: errorMessage });
            }
        } finally {
            setBusy(false);
        }
    };

    const addCategory = (cat: Category) => {
        if (!selectedCategoryIds.includes(cat.id)) {
            setSelectedCategoryIds([...selectedCategoryIds, cat.id]);
        }
        setShowCategorySelector(false);
    };

    const removeCategory = (catId: number) => {
        setSelectedCategoryIds(selectedCategoryIds.filter(id => id !== catId));
    };

    return (
        <Modal
            open={true}
            onClose={onClose}
            title="Edit business"
            description="Update business details, category, location, and more."
            size="xl"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button busy={busy} disabled={name.trim().length === 0} onClick={() => void update()}>
                        Save changes
                    </Button>
                </>
            }
        >
            <div className="space-y-5">
                {/* Business Logo - Circle only, click or drag to upload */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-[var(--color-text-main)]">Business Logo</h3>
                    
                    <div className="flex items-center gap-4">
                        {/* Logo Upload Circle - Click or Drag & Drop */}
                        <label 
                            htmlFor="logo-upload"
                            className="group relative flex h-24 w-24 cursor-pointer items-center justify-center overflow-hidden border-2 border-dashed border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] transition-all hover:border-[var(--color-brand)] hover:bg-[var(--color-brand-subtle)]"
                            style={{ borderRadius: '50%' }}
                            onDragOver={(e) => {
                                e.preventDefault();
                                e.currentTarget.style.borderColor = 'var(--color-brand)';
                            }}
                            onDragLeave={(e) => {
                                e.currentTarget.style.borderColor = 'var(--color-border-light)';
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                e.currentTarget.style.borderColor = 'var(--color-border-light)';
                                const files = Array.from(e.dataTransfer.files);
                                if (files.length > 0 && files[0]!.type.startsWith('image/')) {
                                    setLogoFiles([files[0]!]);
                                    setRemoveLogo(false);
                                }
                            }}
                        >
                            <input
                                id="logo-upload"
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => {
                                    const files = e.target.files;
                                    if (files && files.length > 0) {
                                        setLogoFiles([files[0]!]);
                                        setRemoveLogo(false);
                                    }
                                }}
                            />
                            
                            {logoFiles.length > 0 ? (
                                <img 
                                    src={URL.createObjectURL(logoFiles[0]!)} 
                                    alt="New logo preview" 
                                    className="h-full w-full object-cover"
                                />
                            ) : business.logo_url && !removeLogo ? (
                                <img 
                                    src={business.logo_url} 
                                    alt="Current logo" 
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <div className="flex flex-col items-center justify-center gap-1">
                                    <Icon name="image" size={24} weight="duotone" className="text-[var(--color-brand-text)]" />
                                    <span className="text-[10px] font-medium text-[var(--color-text-muted)]">Click</span>
                                </div>
                            )}
                            
                            {/* Hover overlay */}
                            <div className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity group-hover:opacity-100">
                                <Icon name="upload" size={20} className="text-white" />
                            </div>
                        </label>

                        {/* Info and Remove Button */}
                        <div className="flex-1 space-y-2">
                            <p className="text-xs text-[var(--color-text-muted)]">
                                Click the circle or drag & drop to upload logo
                                <br />
                                <span className="text-[var(--color-text-subtle)]">PNG, JPG, SVG • Max 2MB</span>
                            </p>
                            
                            {errors.logo && (
                                <p className="text-xs font-medium text-red-600" role="alert">
                                    {errors.logo}
                                </p>
                            )}
                            
                            {business.logo_url && !removeLogo && logoFiles.length === 0 && (
                                <Button 
                                    type="button" 
                                    size="sm" 
                                    variant="ghost"
                                    onClick={() => setRemoveLogo(true)}
                                >
                                    <Icon name="trash" size={14} />
                                    Remove logo
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Basic Information */}
                <div className="space-y-4">
                    <h3 className="text-sm font-semibold text-[var(--color-text-main)]">Basic Information</h3>
                    
                    <Input
                        label="Business name"
                        error={errors.name}
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Acme Retail"
                        maxLength={120}
                        required
                    />

                    <Input
                        label="Short code"
                        error={errors.short_code}
                        helperText="2-6 characters, used in SKUs and references (optional)"
                        type="text"
                        value={shortCode}
                        onChange={(e) => setShortCode(e.target.value.toUpperCase())}
                        placeholder="ACM"
                        maxLength={6}
                    />
                </div>

                {/* Category Selection */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-[var(--color-text-main)]">Business Categories</h3>
                    <p className="text-xs text-[var(--color-text-muted)]">
                        Select one or more categories that describe your business. Each category enables different modules.
                    </p>
                    
                    {/* Selected Categories */}
                    {selectedCategories.length > 0 && (
                        <div className="space-y-2">
                            {selectedCategories.map(({ category, parent }) => (
                                <div 
                                    key={category.id}
                                    className="flex items-start gap-3 border border-[var(--color-border-light)] bg-[var(--color-card-bg)] p-3"
                                    style={{ borderRadius: 'var(--shell-radius)' }}
                                >
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-[var(--color-brand-subtle)] text-[var(--color-brand-text)]"
                                        style={{ borderRadius: 'var(--shell-radius)' }}>
                                        <Icon 
                                            name={category.icon ?? 'buildings'} 
                                            size={20} 
                                            weight="duotone" 
                                        />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                            {category.name}
                                        </p>
                                        {parent && (
                                            <p className="text-xs text-[var(--color-text-muted)]">
                                                Under {parent.name}
                                            </p>
                                        )}
                                        {category.summary && (
                                            <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                                {category.summary}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                removeCategory(category.id);
                                                setShowCategorySelector(true);
                                            }}
                                            title="Change category"
                                        >
                                            <Icon name="swap" size={14} />
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => removeCategory(category.id)}
                                            title="Remove category"
                                        >
                                            <Icon name="x" size={14} />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    
                    {/* Add Category Button */}
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setShowCategorySelector(true)}
                    >
                        <Icon name="plus" size={16} />
                        {selectedCategories.length > 0 ? 'Add another category' : 'Add category'}
                    </Button>

                    {errors.category_ids && (
                        <p className="text-xs font-medium text-red-600" role="alert">
                            {errors.category_ids}
                        </p>
                    )}
                </div>

                {/* Location & Currency */}
                <div className="space-y-4">
                    <h3 className="text-sm font-semibold text-[var(--color-text-main)]">Location & Currency</h3>
                    
                    <Select
                        label="Country"
                        value={country}
                        onChange={(e) => {
                            const newCountry = e.target.value;
                            setCountry(newCountry);
                            
                            // Auto-populate currency and timezone when country is selected
                            const countryData = countries.find(c => c.code === newCountry);
                            if (countryData) {
                                setCurrency(countryData.currency);
                                setTimezone(countryData.timezones[0] ?? '');
                            }
                        }}
                        error={errors.country}
                        fullWidth
                    >
                        <option value="">Select country (optional)...</option>
                        {countries.map((c) => (
                            <option key={c.code} value={c.code}>
                                {c.name}
                            </option>
                        ))}
                    </Select>

                    <Select
                        label="Base currency"
                        value={currency}
                        onChange={(e) => setCurrency(e.target.value)}
                        error={errors.base_currency}
                        helperText="Every total in these books is counted in this currency"
                        fullWidth
                        required
                    >
                        {currencies.map((code) => (
                            <option key={code} value={code}>
                                {code}
                            </option>
                        ))}
                    </Select>

                    {availableTimezones.length > 0 && (
                        <Select
                            label="Timezone"
                            value={timezone}
                            onChange={(e) => setTimezone(e.target.value)}
                            error={errors.timezone}
                            helperText="Decides where one trading day ends and the next begins"
                            fullWidth
                        >
                            {availableTimezones.map((zone) => (
                                <option key={zone} value={zone}>
                                    {zone.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </Select>
                    )}
                </div>

                {/* Contact Information */}
                <div className="space-y-4">
                    <h3 className="text-sm font-semibold text-[var(--color-text-main)]">Contact Information</h3>
                    
                    <Input
                        label="Address"
                        error={errors.address}
                        type="text"
                        value={address}
                        onChange={(e) => setAddress(e.target.value)}
                        placeholder="123 Main Street, City, Country"
                    />

                    <div className="grid grid-cols-2 gap-4">
                        <Input
                            label="Phone"
                            error={errors.phone}
                            type="tel"
                            value={phone}
                            onChange={(e) => setPhone(e.target.value)}
                            placeholder="+1 (555) 123-4567"
                        />

                        <Input
                            label="Email"
                            error={errors.email}
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder="contact@business.com"
                        />
                    </div>
                </div>
            </div>

            {/* Category Selector Modal */}
            {showCategorySelector && (
                <CategorySelectorModal
                    categories={categories}
                    selectedCategoryIds={selectedCategoryIds}
                    onSelect={addCategory}
                    onClose={() => setShowCategorySelector(false)}
                />
            )}
        </Modal>
    );
}

function CategorySelectorModal({
    categories,
    selectedCategoryIds,
    onSelect,
    onClose,
}: {
    categories: Category[];
    selectedCategoryIds: number[];
    onSelect: (category: Category) => void;
    onClose: () => void;
}) {
    const [selectedParentKey, setSelectedParentKey] = useState<string | null>(null);
    
    const selectedParent = categories.find(cat => cat.key === selectedParentKey) ?? null;
    const hasChildren = (selectedParent?.children?.length ?? 0) > 0;

    return (
        <Modal
            open={true}
            onClose={onClose}
            title={selectedParent ? `Select ${selectedParent.name} type` : 'Select business category'}
            description={selectedParent ? 'Choose a specific subcategory or go back' : 'Choose categories that describe your business'}
            size="lg"
        >
            <div className="space-y-4">
                {/* Back button when viewing subcategories */}
                {selectedParent && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setSelectedParentKey(null)}
                    >
                        <Icon name="caret-left" size={14} />
                        Back to categories
                    </Button>
                )}

                {/* Parent categories grid */}
                {!selectedParent && (
                    <div className="choice-grid">
                        {categories.map((category) => {
                            const isSelected = selectedCategoryIds.includes(category.id);
                            const hasSubcategories = (category.children?.length ?? 0) > 0;
                            
                            return (
                                <button
                                    key={category.key}
                                    type="button"
                                    onClick={() => {
                                        if (hasSubcategories) {
                                            setSelectedParentKey(category.key);
                                        } else {
                                            onSelect(category);
                                        }
                                    }}
                                    className={cn(
                                        'choice',
                                        isSelected && 'is-selected'
                                    )}
                                >
                                    <span className="choice-icon">
                                        <Icon name={category.icon ?? 'buildings'} size={18} weight="duotone" />
                                    </span>
                                    <span className="choice-name">{category.name}</span>
                                    {category.summary && (
                                        <span className="choice-note">{category.summary}</span>
                                    )}
                                    {hasSubcategories && (
                                        <span className="choice-note text-[var(--color-brand-text)]">
                                            {category.children.length} types available →
                                        </span>
                                    )}
                                    {isSelected && !hasSubcategories && (
                                        <span className="choice-note text-green-600">✓ Selected</span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                )}

                {/* Subcategories list */}
                {selectedParent && hasChildren && (
                    <div className="choice-grid is-list">
                        {selectedParent.children.map((subcategory) => {
                            const isSelected = selectedCategoryIds.includes(subcategory.id);
                            
                            return (
                                <button
                                    key={subcategory.key}
                                    type="button"
                                    onClick={() => onSelect(subcategory)}
                                    className={cn(
                                        'choice is-row',
                                        isSelected && 'is-selected'
                                    )}
                                >
                                    <span className="choice-radio" />
                                    <span className="choice-name flex-1">{subcategory.name}</span>
                                    {isSelected && (
                                        <span className="text-xs font-medium text-green-600">✓</span>
                                    )}
                                    {subcategory.summary && (
                                        <span className="choice-note">{subcategory.summary}</span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>
        </Modal>
    );
}
