import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    BulkActions,
    BulkActionButton,
    SelectCheckbox,
    QuickCreateModal,
    QuickActionButton,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { confirm } from '@/lib/confirm';

type Employee = {
    id: string;
    employee_id: string;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    phone?: string;
    department?: {
        id: string;
        name: string;
    };
    role: string;
    employment_type: 'full_time' | 'part_time' | 'contract' | 'intern';
    status: 'active' | 'on_leave' | 'terminated';
    hire_date: string;
    date_of_birth?: string;
    address?: {
        line1: string;
        line2?: string;
        city: string;
        state: string;
        postal_code: string;
        country: string;
    };
    emergency_contact?: {
        name: string;
        relationship: string;
        phone: string;
    };
    salary?: number;
    photo_url?: string;
    reports_to?: {
        id: string;
        name: string;
    };
    created_at: string;
};

type EmployeesResponse = {
    data: Employee[];
    summary: {
        total_employees: number;
        active_employees: number;
        on_leave_count: number;
        new_this_month: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Employees() {
    useDocumentTitle('Employees');

    // State
    const [search, setSearch] = useState('');
    const [departmentFilter, setDepartmentFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('active');
    const [employmentTypeFilter, setEmploymentTypeFilter] = useState('');
    const [selectedEmployees, setSelectedEmployees] = useState<string[]>([]);
    const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);
    const [drawerTab, setDrawerTab] = useState('overview');
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('full_name');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('asc');

    // Form state
    const [form, setForm] = useState({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        role: '',
        department_id: '',
        employment_type: 'full_time' as 'full_time' | 'part_time' | 'contract' | 'intern',
        hire_date: new Date().toISOString().split('T')[0],
    });

    // Fetch employees
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['employees', { search, departmentFilter, statusFilter, employmentTypeFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<EmployeesResponse>('/employees', {
                params: {
                    search,
                    department: departmentFilter || undefined,
                    status: statusFilter || undefined,
                    employment_type: employmentTypeFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const employees = data?.data ?? [];
    const summary = data?.summary;

    // Selection handlers
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedEmployees(employees.map((e) => e.id));
        } else {
            setSelectedEmployees([]);
        }
    };

    const handleSelectEmployee = (id: string, checked: boolean) => {
        if (checked) {
            setSelectedEmployees([...selectedEmployees, id]);
        } else {
            setSelectedEmployees(selectedEmployees.filter((eid) => eid !== id));
        }
    };

    const isAllSelected = employees.length > 0 && selectedEmployees.length === employees.length;
    const isSomeSelected = selectedEmployees.length > 0 && selectedEmployees.length < employees.length;

    // Sort handler
    const handleSort = (key: string) => {
        if (sortBy === key) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(key);
            setSortDirection('asc');
        }
    };

    // Clear filters
    const handleClearFilters = () => {
        setSearch('');
        setDepartmentFilter('');
        setStatusFilter('active');
        setEmploymentTypeFilter('');
    };

    const hasFilters = search || departmentFilter || statusFilter !== 'active' || employmentTypeFilter;

    // Bulk actions
    const handleBulkExport = () => {
        console.log('Exporting employees:', selectedEmployees);
        // TODO: Implement export
    };

    const handleBulkDeactivate = async () => {
        if (await confirm(`Terminate ${selectedEmployees.length} employees?`)) {
            console.log('Terminating employees:', selectedEmployees);
            // TODO: Implement terminate
            setSelectedEmployees([]);
        }
    };

    // Create employee
    const handleCreateEmployee = () => {
        console.log('Creating employee:', form);
        // TODO: Implement create
        setShowCreateModal(false);
    };

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Calculate tenure
    const calculateTenure = (hireDate: string) => {
        const hire = new Date(hireDate);
        const now = new Date();
        const years = now.getFullYear() - hire.getFullYear();
        const months = now.getMonth() - hire.getMonth();
        
        if (years === 0) {
            return months === 1 ? '1 month' : `${months} months`;
        } else if (months < 0) {
            return years === 1 ? '1 year' : `${years} years`;
        } else {
            return `${years}y ${months}m`;
        }
    };

    // Status variants
    const statusVariants: Record<string, 'success' | 'warning' | 'neutral'> = {
        active: 'success',
        on_leave: 'warning',
        terminated: 'neutral',
    };

    const statusLabels = {
        active: 'Active',
        on_leave: 'On Leave',
        terminated: 'Terminated',
    };

    const employmentTypeLabels = {
        full_time: 'Full-time',
        part_time: 'Part-time',
        contract: 'Contract',
        intern: 'Intern',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Employees"
                    description="Manage your team and workforce"
                    icon="users-three"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Export directory')}
                            >
                                <Icon name="download-simple" size={16} />
                                <span>Export</span>
                            </button>
                            <QuickActionButton
                                icon="plus"
                                label="Add Employee"
                                onClick={() => setShowCreateModal(true)}
                            />
                        </>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Employees"
                        value={summary.total_employees.toLocaleString()}
                        icon="users-three"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_employees.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="On Leave"
                        value={summary.on_leave_count.toLocaleString()}
                        icon="calendar-x"
                        variant="warning"
                    />
                    <KPICard
                        label="New This Month"
                        value={summary.new_this_month.toLocaleString()}
                        icon="user-plus"
                        variant="info"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search by name, email, or employee ID..."
                    filters={
                        <>
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'on_leave', label: 'On Leave' },
                                    { value: 'terminated', label: 'Terminated' },
                                ]}
                                placeholder="All statuses"
                            />
                            <FilterSelect
                                label="Type"
                                value={employmentTypeFilter}
                                onChange={setEmploymentTypeFilter}
                                options={[
                                    { value: 'full_time', label: 'Full-time' },
                                    { value: 'part_time', label: 'Part-time' },
                                    { value: 'contract', label: 'Contract' },
                                    { value: 'intern', label: 'Intern' },
                                ]}
                                placeholder="All types"
                            />
                            {hasFilters && (
                                <button
                                    type="button"
                                    onClick={handleClearFilters}
                                    className="flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]"
                                >
                                    <Icon name="x" size={14} />
                                    <span>Clear</span>
                                </button>
                            )}
                        </>
                    }
                />
            </div>

            {/* Content */}
            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">
                            Failed to load employees.
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            className="btn btn-secondary mt-4"
                        >
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={employees}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'select',
                                    label: '',
                                    width: 'w-12',
                                    render: (employee) => (
                                        <SelectCheckbox
                                            checked={selectedEmployees.includes(employee.id)}
                                            onChange={(checked) =>
                                                handleSelectEmployee(employee.id, checked)
                                            }
                                        />
                                    ),
                                },
                                {
                                    key: 'photo',
                                    label: '',
                                    width: 'w-16',
                                    render: (employee) => (
                                        <div
                                            className="flex size-10 items-center justify-center overflow-hidden bg-[var(--color-brand-subtle)]"
                                            style={{ borderRadius: '50%' }}
                                        >
                                            {employee.photo_url ? (
                                                <img
                                                    src={employee.photo_url}
                                                    alt={employee.full_name}
                                                    className="size-full object-cover"
                                                />
                                            ) : (
                                                <span className="text-sm font-semibold text-[var(--color-brand)]">
                                                    {employee.first_name[0]}{employee.last_name[0]}
                                                </span>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'full_name',
                                    label: 'Employee',
                                    sortable: true,
                                    render: (employee) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {employee.full_name}
                                            </p>
                                            <p className="mt-0.5 flex items-center gap-2 text-xs text-[var(--color-text-muted)]">
                                                <span>ID: {employee.employee_id}</span>
                                                {employee.department && (
                                                    <>
                                                        <span>·</span>
                                                        <span>{employee.department.name}</span>
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'role',
                                    label: 'Role',
                                    accessor: (e) => e.role,
                                },
                                {
                                    key: 'email',
                                    label: 'Contact',
                                    render: (employee) => (
                                        <div>
                                            <p className="text-sm text-[var(--color-text-main)]">
                                                {employee.email}
                                            </p>
                                            {employee.phone && (
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    {employee.phone}
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'employment_type',
                                    label: 'Type',
                                    accessor: (e) => employmentTypeLabels[e.employment_type],
                                },
                                {
                                    key: 'hire_date',
                                    label: 'Tenure',
                                    sortable: true,
                                    render: (e) => (
                                        <div>
                                            <p className="text-sm text-[var(--color-text-main)]">
                                                {calculateTenure(e.hire_date)}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                Since {formatDate(e.hire_date)}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (e) => (
                                        <StatusBadge
                                            label={statusLabels[e.status]}
                                            variant={statusVariants[e.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(employee) => setSelectedEmployee(employee)}
                            clickable
                            getRowKey={(employee) => employee.id}
                            emptyState={
                                <EmptyState
                                    icon="users-three"
                                    title={hasFilters ? 'No employees match' : 'No employees yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Add your first employee to start building your team.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button
                                                className="btn btn-primary"
                                                onClick={() => setShowCreateModal(true)}
                                            >
                                                Add Employee
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                )}

                {/* Select all checkbox */}
                {!isLoading && employees.length > 0 && (
                    <div className="mt-3 px-6">
                        <SelectCheckbox
                            checked={isAllSelected}
                            indeterminate={isSomeSelected}
                            onChange={handleSelectAll}
                            label={
                                isAllSelected
                                    ? 'Deselect all'
                                    : isSomeSelected
                                      ? `${selectedEmployees.length} selected`
                                      : 'Select all'
                            }
                        />
                    </div>
                )}
            </div>

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedEmployees.length}
                onClearSelection={() => setSelectedEmployees([])}
            >
                <BulkActionButton
                    icon="download-simple"
                    label="Export"
                    onClick={handleBulkExport}
                />
                <BulkActionButton
                    icon="x-circle"
                    label="Terminate"
                    onClick={handleBulkDeactivate}
                    variant="danger"
                />
            </BulkActions>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedEmployee}
                onClose={() => setSelectedEmployee(null)}
                title={selectedEmployee?.full_name ?? ''}
                subtitle={selectedEmployee ? `${selectedEmployee.role} · ${selectedEmployee.employee_id}` : ''}
                tabs={[
                    {
                        key: 'overview',
                        label: 'Overview',
                        content: selectedEmployee && (
                            <div className="space-y-6">
                                <DrawerSection title="Personal Information">
                                    <DrawerField
                                        label="Full Name"
                                        value={selectedEmployee.full_name}
                                        icon="user"
                                    />
                                    <DrawerField
                                        label="Employee ID"
                                        value={selectedEmployee.employee_id}
                                        icon="hash"
                                    />
                                    <DrawerField
                                        label="Email"
                                        value={selectedEmployee.email}
                                        icon="envelope"
                                    />
                                    {selectedEmployee.phone && (
                                        <DrawerField
                                            label="Phone"
                                            value={selectedEmployee.phone}
                                            icon="phone"
                                        />
                                    )}
                                    {selectedEmployee.date_of_birth && (
                                        <DrawerField
                                            label="Date of Birth"
                                            value={formatDate(selectedEmployee.date_of_birth)}
                                            icon="cake"
                                        />
                                    )}
                                </DrawerSection>

                                <DrawerSection title="Employment">
                                    <DrawerField
                                        label="Role"
                                        value={selectedEmployee.role}
                                        icon="briefcase"
                                    />
                                    {selectedEmployee.department && (
                                        <DrawerField
                                            label="Department"
                                            value={selectedEmployee.department.name}
                                            icon="buildings"
                                        />
                                    )}
                                    <DrawerField
                                        label="Employment Type"
                                        value={employmentTypeLabels[selectedEmployee.employment_type]}
                                        icon="identification-badge"
                                    />
                                    <DrawerField
                                        label="Hire Date"
                                        value={formatDate(selectedEmployee.hire_date)}
                                        icon="calendar-check"
                                    />
                                    <DrawerField
                                        label="Tenure"
                                        value={calculateTenure(selectedEmployee.hire_date)}
                                        icon="clock"
                                    />
                                    {selectedEmployee.reports_to && (
                                        <DrawerField
                                            label="Reports To"
                                            value={selectedEmployee.reports_to.name}
                                            icon="user-focus"
                                        />
                                    )}
                                </DrawerSection>

                                {selectedEmployee.address && (
                                    <DrawerSection title="Address">
                                        <DrawerField
                                            label="Location"
                                            value={
                                                <div className="text-sm">
                                                    <div>{selectedEmployee.address.line1}</div>
                                                    {selectedEmployee.address.line2 && (
                                                        <div>{selectedEmployee.address.line2}</div>
                                                    )}
                                                    <div>
                                                        {selectedEmployee.address.city}, {selectedEmployee.address.state} {selectedEmployee.address.postal_code}
                                                    </div>
                                                    <div>{selectedEmployee.address.country}</div>
                                                </div>
                                            }
                                            icon="map-pin"
                                        />
                                    </DrawerSection>
                                )}

                                {selectedEmployee.emergency_contact && (
                                    <DrawerSection title="Emergency Contact">
                                        <DrawerField
                                            label="Name"
                                            value={selectedEmployee.emergency_contact.name}
                                            icon="user"
                                        />
                                        <DrawerField
                                            label="Relationship"
                                            value={selectedEmployee.emergency_contact.relationship}
                                            icon="users"
                                        />
                                        <DrawerField
                                            label="Phone"
                                            value={selectedEmployee.emergency_contact.phone}
                                            icon="phone"
                                        />
                                    </DrawerSection>
                                )}

                                <DrawerSection title="Status">
                                    <DrawerField
                                        label="Employment Status"
                                        value={
                                            <StatusBadge
                                                label={statusLabels[selectedEmployee.status]}
                                                variant={statusVariants[selectedEmployee.status]}
                                                dot
                                            />
                                        }
                                        icon="check-circle"
                                    />
                                </DrawerSection>
                            </div>
                        ),
                    },
                    {
                        key: 'documents',
                        label: 'Documents',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Employee documents coming soon</p>,
                    },
                    {
                        key: 'activity',
                        label: 'Activity',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Activity timeline coming soon</p>,
                    },
                ]}
                activeTab={drawerTab}
                onTabChange={setDrawerTab}
                actions={
                    <>
                        <button className="btn btn-secondary">
                            <Icon name="pencil-simple" size={16} />
                            <span>Edit</span>
                        </button>
                    </>
                }
            >
                <div />
            </DetailDrawer>

            {/* Create Employee Modal */}
            <QuickCreateModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                title="Add Employee"
                onSubmit={handleCreateEmployee}
                submitLabel="Create Employee"
                size="lg"
            >
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                First Name <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="text"
                                value={form.first_name}
                                onChange={(e) => setForm({ ...form, first_name: e.target.value })}
                                placeholder="John"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Last Name <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="text"
                                value={form.last_name}
                                onChange={(e) => setForm({ ...form, last_name: e.target.value })}
                                placeholder="Doe"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Email <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="email"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                            placeholder="john.doe@company.com"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Phone
                        </label>
                        <input
                            type="tel"
                            value={form.phone}
                            onChange={(e) => setForm({ ...form, phone: e.target.value })}
                            placeholder="+1 (555) 123-4567"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Role <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            value={form.role}
                            onChange={(e) => setForm({ ...form, role: e.target.value })}
                            placeholder="e.g., Software Engineer"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Employment Type <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <select
                                value={form.employment_type}
                                onChange={(e) => setForm({ ...form, employment_type: e.target.value as any })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            >
                                <option value="full_time">Full-time</option>
                                <option value="part_time">Part-time</option>
                                <option value="contract">Contract</option>
                                <option value="intern">Intern</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Hire Date <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="date"
                                value={form.hire_date}
                                onChange={(e) => setForm({ ...form, hire_date: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
