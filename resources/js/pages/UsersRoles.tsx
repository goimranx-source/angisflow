import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    ViewToggleButton,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type User = {
    id: string;
    name: string;
    email: string;
    role: {
        id: string;
        name: string;
        permissions_count: number;
    };
    status: 'active' | 'inactive' | 'invited';
    last_login_at?: string;
    created_at: string;
};

type Role = {
    id: string;
    name: string;
    description: string;
    permissions: string[];
    users_count: number;
    is_system: boolean;
    created_at: string;
};

type UsersResponse = {
    data: User[];
    summary: {
        total_users: number;
        active_users: number;
        invited_users: number;
        inactive_users: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

type RolesResponse = {
    data: Role[];
    summary: {
        total_roles: number;
        system_roles: number;
        custom_roles: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function UsersRoles() {
    useDocumentTitle('Users & Roles');

    // State
    const [view, setView] = useState<'users' | 'roles'>('users');
    const [search, setSearch] = useState('');
    const [roleFilter, setRoleFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedUser, setSelectedUser] = useState<User | null>(null);
    const [selectedRole, setSelectedRole] = useState<Role | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch users
    const { data: usersData, isLoading: loadingUsers, isError: errorUsers, refetch: refetchUsers } = useQuery({
        queryKey: ['users', { search, roleFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<UsersResponse>('/users', {
                params: {
                    search,
                    role: roleFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'users',
    });

    // Fetch roles
    const { data: rolesData, isLoading: loadingRoles, isError: errorRoles, refetch: refetchRoles } = useQuery({
        queryKey: ['roles', { search, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<RolesResponse>('/roles', {
                params: {
                    search,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'roles',
    });

    const users = usersData?.data ?? [];
    const usersSummary = usersData?.summary;
    const roles = rolesData?.data ?? [];
    const rolesSummary = rolesData?.summary;
    const isLoading = view === 'users' ? loadingUsers : loadingRoles;
    const isError = view === 'users' ? errorUsers : errorRoles;

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
        setRoleFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || roleFilter || statusFilter;

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Format time ago
    const formatTimeAgo = (date: string) => {
        const d = new Date(date);
        const now = new Date();
        const diffInDays = Math.floor((now.getTime() - d.getTime()) / (1000 * 60 * 60 * 24));

        if (diffInDays === 0) return 'Today';
        if (diffInDays === 1) return 'Yesterday';
        if (diffInDays < 7) return `${diffInDays} days ago`;
        if (diffInDays < 30) return `${Math.floor(diffInDays / 7)} weeks ago`;
        return formatDate(date);
    };

    // Status variants
    const statusVariants: Record<string, 'success' | 'warning' | 'neutral'> = {
        active: 'success',
        invited: 'warning',
        inactive: 'neutral',
    };

    const statusLabels = {
        active: 'Active',
        invited: 'Invited',
        inactive: 'Inactive',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Users & Roles"
                    description="Manage team members and access permissions"
                    icon="shield-check"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log(view === 'users' ? 'Invite user' : 'Create role')}
                        >
                            <Icon name="plus" size={16} />
                            <span>{view === 'users' ? 'Invite user' : 'Create role'}</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {view === 'users' && usersSummary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Users"
                        value={usersSummary.total_users.toLocaleString()}
                        icon="users-three"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={usersSummary.active_users.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Invited"
                        value={usersSummary.invited_users.toLocaleString()}
                        icon="envelope"
                        variant="warning"
                    />
                    <KPICard
                        label="Inactive"
                        value={usersSummary.inactive_users.toLocaleString()}
                        icon="user-minus"
                        variant="neutral"
                    />
                </div>
            )}

            {view === 'roles' && rolesSummary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-3">
                    <KPICard
                        label="Total Roles"
                        value={rolesSummary.total_roles.toLocaleString()}
                        icon="shield-check"
                        variant="brand"
                    />
                    <KPICard
                        label="System Roles"
                        value={rolesSummary.system_roles.toLocaleString()}
                        icon="lock"
                        variant="info"
                    />
                    <KPICard
                        label="Custom Roles"
                        value={rolesSummary.custom_roles.toLocaleString()}
                        icon="user-gear"
                        variant="success"
                    />
                </div>
            )}

            {/* View Tabs */}
            <div className="mt-4 px-6">
                <div className="flex gap-2">
                    <ViewToggleButton
                        icon="users-three"
                        label="Users"
                        active={view === 'users'}
                        onClick={() => setView('users')}
                    />
                    <ViewToggleButton
                        icon="shield-check"
                        label="Roles"
                        active={view === 'roles'}
                        onClick={() => setView('roles')}
                    />
                </div>
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder={view === 'users' ? 'Search users...' : 'Search roles...'}
                    filters={
                        <>
                            {view === 'users' && (
                                <>
                                    <FilterSelect
                                        label="Role"
                                        value={roleFilter}
                                        onChange={setRoleFilter}
                                        options={roles.map((r) => ({ value: r.id, label: r.name }))}
                                        placeholder="All roles"
                                    />
                                    <FilterSelect
                                        label="Status"
                                        value={statusFilter}
                                        onChange={setStatusFilter}
                                        options={[
                                            { value: 'active', label: 'Active' },
                                            { value: 'invited', label: 'Invited' },
                                            { value: 'inactive', label: 'Inactive' },
                                        ]}
                                        placeholder="All statuses"
                                    />
                                </>
                            )}
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
                            Failed to load {view === 'users' ? 'users' : 'roles'}.
                        </p>
                        <button
                            type="button"
                            onClick={() => void (view === 'users' ? refetchUsers() : refetchRoles())}
                            className="btn btn-secondary mt-4"
                        >
                            Try again
                        </button>
                    </div>
                ) : view === 'users' ? (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={users}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'User',
                                    render: (user) => (
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-[var(--color-brand-subtle)] text-[var(--color-brand)]">
                                                <Icon name="user" size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {user.name}
                                                </p>
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    {user.email}
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'role',
                                    label: 'Role',
                                    render: (user) => (
                                        <div>
                                            <p className="text-sm font-medium text-[var(--color-text-main)]">
                                                {user.role.name}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {user.role.permissions_count} permissions
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (user) => (
                                        <StatusBadge
                                            label={statusLabels[user.status]}
                                            variant={statusVariants[user.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'last_login_at',
                                    label: 'Last Login',
                                    sortable: true,
                                    accessor: (user) => (user.last_login_at ? formatTimeAgo(user.last_login_at) : 'Never'),
                                },
                                {
                                    key: 'created_at',
                                    label: 'Joined',
                                    sortable: true,
                                    accessor: (user) => formatDate(user.created_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(user) => setSelectedUser(user)}
                            clickable
                            getRowKey={(user) => user.id}
                            emptyState={
                                <EmptyState
                                    icon="users-three"
                                    title={hasFilters ? 'No users match' : 'No users yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Invite your first team member to get started.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary" onClick={() => console.log('Invite')}>
                                                <Icon name="plus" size={16} />
                                                <span>Invite user</span>
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={roles}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Role',
                                    render: (role) => (
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--color-brand-subtle)] text-[var(--color-brand)]">
                                                <Icon name={role.is_system ? 'lock' : 'shield-check'} size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <p className="font-medium text-[var(--color-text-main)]">
                                                        {role.name}
                                                    </p>
                                                    {role.is_system && (
                                                        <span className="rounded bg-[var(--shell-tint)] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">
                                                            System
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="mt-0.5 line-clamp-1 text-xs text-[var(--color-text-muted)]">
                                                    {role.description}
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'permissions',
                                    label: 'Permissions',
                                    align: 'right',
                                    render: (role) => (
                                        <span className="tabular-nums font-medium">
                                            {role.permissions.length}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'users_count',
                                    label: 'Users',
                                    align: 'right',
                                    sortable: true,
                                    render: (role) => (
                                        <span className="tabular-nums">
                                            {role.users_count}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'created_at',
                                    label: 'Created',
                                    sortable: true,
                                    accessor: (role) => formatDate(role.created_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(role) => setSelectedRole(role)}
                            clickable
                            getRowKey={(role) => role.id}
                            emptyState={
                                <EmptyState
                                    icon="shield-check"
                                    title="No roles yet"
                                    body="Create custom roles to organize team permissions."
                                    action={
                                        <button className="btn btn-primary" onClick={() => console.log('Create')}>
                                            <Icon name="plus" size={16} />
                                            <span>Create role</span>
                                        </button>
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            {/* User Detail Drawer */}
            <DetailDrawer
                open={!!selectedUser}
                onClose={() => setSelectedUser(null)}
                title={selectedUser?.name ?? ''}
                subtitle={selectedUser?.email ?? ''}
                size="md"
            >
                {selectedUser && (
                    <div className="space-y-6">
                        <DrawerSection title="User Details">
                            <DrawerField label="Name" value={selectedUser.name} icon="user" />
                            <DrawerField label="Email" value={selectedUser.email} icon="envelope" />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedUser.status]}
                                        variant={statusVariants[selectedUser.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        <DrawerSection title="Role & Permissions">
                            <DrawerField label="Role" value={selectedUser.role.name} icon="shield-check" />
                            <DrawerField
                                label="Permissions"
                                value={`${selectedUser.role.permissions_count} permissions`}
                                icon="key"
                            />
                        </DrawerSection>

                        <DrawerSection title="Activity">
                            <DrawerField
                                label="Last Login"
                                value={selectedUser.last_login_at ? formatTimeAgo(selectedUser.last_login_at) : 'Never'}
                                icon="clock"
                            />
                            <DrawerField
                                label="Joined"
                                value={formatDate(selectedUser.created_at)}
                                icon="calendar"
                            />
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-secondary flex-1">
                                <Icon name="pencil-simple" size={16} />
                                <span>Edit user</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="prohibit" size={16} />
                                <span>Deactivate</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>

            {/* Role Detail Drawer */}
            <DetailDrawer
                open={!!selectedRole}
                onClose={() => setSelectedRole(null)}
                title={selectedRole?.name ?? ''}
                subtitle={selectedRole?.description ?? ''}
                size="lg"
            >
                {selectedRole && (
                    <div className="space-y-6">
                        <DrawerSection title="Role Details">
                            <DrawerField
                                label="Type"
                                value={selectedRole.is_system ? 'System Role' : 'Custom Role'}
                                icon={selectedRole.is_system ? 'lock' : 'user-gear'}
                            />
                            <DrawerField
                                label="Users"
                                value={selectedRole.users_count.toString()}
                                icon="users-three"
                            />
                            <DrawerField
                                label="Created"
                                value={formatDate(selectedRole.created_at)}
                                icon="calendar"
                            />
                        </DrawerSection>

                        <DrawerSection title={`Permissions (${selectedRole.permissions.length})`}>
                            <div className="flex flex-wrap gap-2">
                                {selectedRole.permissions.map((permission) => (
                                    <span
                                        key={permission}
                                        className="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-border-light)] bg-[var(--shell-tint)] px-2.5 py-1.5 text-xs font-medium text-[var(--color-text-main)]"
                                    >
                                        <Icon name="key" size={12} />
                                        {permission}
                                    </span>
                                ))}
                            </div>
                        </DrawerSection>

                        {selectedRole.is_system && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-info-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon name="lock" size={20} className="flex-none text-[var(--color-info)]" />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">System Role</p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This is a system role and cannot be modified or deleted.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {!selectedRole.is_system && (
                            <div className="flex gap-3 pt-4">
                                <button className="btn btn-secondary flex-1">
                                    <Icon name="pencil-simple" size={16} />
                                    <span>Edit role</span>
                                </button>
                                <button className="btn btn-secondary">
                                    <Icon name="trash" size={16} />
                                    <span>Delete</span>
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
