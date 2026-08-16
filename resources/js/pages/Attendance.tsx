import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
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

type AttendanceRecord = {
    id: string;
    employee: {
        id: string;
        name: string;
        employee_id: string;
        photo_url?: string;
    };
    date: string;
    check_in?: string;
    check_out?: string;
    status: 'present' | 'absent' | 'late' | 'half_day' | 'on_leave';
    hours_worked?: number;
    overtime_hours?: number;
    notes?: string;
    created_at: string;
};

type LeaveRequest = {
    id: string;
    employee: {
        id: string;
        name: string;
        employee_id: string;
    };
    leave_type: 'annual' | 'sick' | 'personal' | 'unpaid' | 'maternity' | 'paternity';
    start_date: string;
    end_date: string;
    days_count: number;
    status: 'pending' | 'approved' | 'rejected';
    reason?: string;
    approver?: {
        id: string;
        name: string;
    };
    approved_at?: string;
    created_at: string;
};

type AttendanceResponse = {
    data: AttendanceRecord[];
    summary: {
        present_today: number;
        absent_today: number;
        on_leave_today: number;
        avg_hours: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

type LeaveResponse = {
    data: LeaveRequest[];
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Attendance() {
    useDocumentTitle('Attendance & Leave');

    // State
    const [view, setView] = useState<'attendance' | 'leave'>('attendance');
    const [search, setSearch] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [leaveStatusFilter, setLeaveStatusFilter] = useState('');
    const [selectedRecord, setSelectedRecord] = useState<AttendanceRecord | null>(null);
    const [selectedLeave, setSelectedLeave] = useState<LeaveRequest | null>(null);
    const [showLeaveModal, setShowLeaveModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Form state
    const [leaveForm, setLeaveForm] = useState({
        employee_id: '',
        leave_type: 'annual' as 'annual' | 'sick' | 'personal' | 'unpaid' | 'maternity' | 'paternity',
        start_date: '',
        end_date: '',
        reason: '',
    });

    // Fetch attendance
    const { data: attendanceData, isLoading: loadingAttendance, isError: errorAttendance, refetch: refetchAttendance } = useQuery({
        queryKey: ['attendance', { search, dateFrom, dateTo, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<AttendanceResponse>('/attendance', {
                params: {
                    search,
                    date_from: dateFrom || undefined,
                    date_to: dateTo || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'attendance',
    });

    // Fetch leave requests
    const { data: leaveData, isLoading: loadingLeave, isError: errorLeave, refetch: refetchLeave } = useQuery({
        queryKey: ['leave-requests', { search, leaveStatusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<LeaveResponse>('/leave-requests', {
                params: {
                    search,
                    status: leaveStatusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'leave',
    });

    const attendanceRecords = attendanceData?.data ?? [];
    const leaveRequests = leaveData?.data ?? [];
    const summary = attendanceData?.summary;

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
        setDateFrom('');
        setDateTo('');
        setStatusFilter('');
        setLeaveStatusFilter('');
    };

    const hasFilters = search || dateFrom || dateTo || statusFilter || leaveStatusFilter;

    // Submit leave request
    const handleSubmitLeave = () => {
        console.log('Submitting leave request:', leaveForm);
        // TODO: Implement submit
        setShowLeaveModal(false);
    };

    // Approve/Reject leave
    const handleApproveLeave = (id: string) => {
        console.log('Approving leave:', id);
        // TODO: Implement approve
    };

    const handleRejectLeave = (id: string) => {
        console.log('Rejecting leave:', id);
        // TODO: Implement reject
    };

    // Format time
    const formatTime = (time: string) => {
        return new Date(`2000-01-01T${time}`).toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Status variants
    const attendanceStatusVariants: Record<string, 'success' | 'danger' | 'warning' | 'info' | 'neutral'> = {
        present: 'success',
        absent: 'danger',
        late: 'warning',
        half_day: 'info',
        on_leave: 'neutral',
    };

    const attendanceStatusLabels = {
        present: 'Present',
        absent: 'Absent',
        late: 'Late',
        half_day: 'Half Day',
        on_leave: 'On Leave',
    };

    const leaveStatusVariants: Record<string, 'warning' | 'success' | 'danger'> = {
        pending: 'warning',
        approved: 'success',
        rejected: 'danger',
    };

    const leaveStatusLabels = {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
    };

    const leaveTypeLabels = {
        annual: 'Annual Leave',
        sick: 'Sick Leave',
        personal: 'Personal Leave',
        unpaid: 'Unpaid Leave',
        maternity: 'Maternity Leave',
        paternity: 'Paternity Leave',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Attendance & Leave"
                    description="Track employee attendance and manage leave requests"
                    icon="clock-user"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Export')}
                            >
                                <Icon name="download-simple" size={16} />
                                <span>Export</span>
                            </button>
                            <QuickActionButton
                                icon="plus"
                                label="Request Leave"
                                onClick={() => setShowLeaveModal(true)}
                            />
                        </>
                    }
                />
            </div>

            {/* KPI Cards - Only for attendance view */}
            {view === 'attendance' && summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Present Today"
                        value={summary.present_today.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Absent Today"
                        value={summary.absent_today.toLocaleString()}
                        icon="x-circle"
                        variant="danger"
                    />
                    <KPICard
                        label="On Leave"
                        value={summary.on_leave_today.toLocaleString()}
                        icon="calendar-x"
                        variant="warning"
                    />
                    <KPICard
                        label="Avg Hours"
                        value={`${summary.avg_hours.toFixed(1)}h`}
                        icon="clock"
                        variant="info"
                    />
                </div>
            )}

            {/* View Tabs */}
            <div className="mt-4 border-b border-[var(--color-border-light)] px-6">
                <div className="flex gap-1">
                    <button
                        type="button"
                        onClick={() => setView('attendance')}
                        className={`px-4 py-2 text-sm font-medium transition-colors ${
                            view === 'attendance'
                                ? 'border-b-2 border-[var(--color-brand)] text-[var(--color-brand)]'
                                : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]'
                        }`}
                    >
                        <Icon name="clock" size={16} className="inline mr-1.5" />
                        Attendance
                    </button>
                    <button
                        type="button"
                        onClick={() => setView('leave')}
                        className={`px-4 py-2 text-sm font-medium transition-colors ${
                            view === 'leave'
                                ? 'border-b-2 border-[var(--color-brand)] text-[var(--color-brand)]'
                                : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]'
                        }`}
                    >
                        <Icon name="calendar-x" size={16} className="inline mr-1.5" />
                        Leave Requests
                    </button>
                </div>
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder={view === 'attendance' ? 'Search by employee name or ID...' : 'Search leave requests...'}
                    filters={
                        <>
                            {view === 'attendance' ? (
                                <>
                                    <FilterSelect
                                        label="Status"
                                        value={statusFilter}
                                        onChange={setStatusFilter}
                                        options={[
                                            { value: 'present', label: 'Present' },
                                            { value: 'absent', label: 'Absent' },
                                            { value: 'late', label: 'Late' },
                                            { value: 'half_day', label: 'Half Day' },
                                            { value: 'on_leave', label: 'On Leave' },
                                        ]}
                                        placeholder="All statuses"
                                    />
                                    <div className="flex items-center gap-2">
                                        <label className="text-xs font-medium text-[var(--color-text-muted)] whitespace-nowrap">
                                            From:
                                        </label>
                                        <input
                                            type="date"
                                            value={dateFrom}
                                            onChange={(e) => setDateFrom(e.target.value)}
                                            className="border border-[var(--color-border-light)] bg-white py-1.5 px-3 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                            style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                        />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <label className="text-xs font-medium text-[var(--color-text-muted)] whitespace-nowrap">
                                            To:
                                        </label>
                                        <input
                                            type="date"
                                            value={dateTo}
                                            onChange={(e) => setDateTo(e.target.value)}
                                            className="border border-[var(--color-border-light)] bg-white py-1.5 px-3 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                            style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                        />
                                    </div>
                                </>
                            ) : (
                                <FilterSelect
                                    label="Status"
                                    value={leaveStatusFilter}
                                    onChange={setLeaveStatusFilter}
                                    options={[
                                        { value: 'pending', label: 'Pending' },
                                        { value: 'approved', label: 'Approved' },
                                        { value: 'rejected', label: 'Rejected' },
                                    ]}
                                    placeholder="All statuses"
                                />
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
                {view === 'attendance' ? (
                    errorAttendance ? (
                        <div className="card mt-6 p-6 text-center">
                            <p className="text-sm text-[var(--color-text-body)]">
                                Failed to load attendance records.
                            </p>
                            <button
                                type="button"
                                onClick={() => void refetchAttendance()}
                                className="btn btn-secondary mt-4"
                            >
                                Try again
                            </button>
                        </div>
                    ) : (
                        <div className="card mt-6 overflow-hidden">
                            <Table
                                data={attendanceRecords}
                                loading={loadingAttendance}
                                skeletonRows={10}
                                columns={[
                                    {
                                        key: 'employee',
                                        label: 'Employee',
                                        render: (record) => (
                                            <div className="flex items-center gap-3">
                                                <div
                                                    className="flex size-10 flex-none items-center justify-center overflow-hidden bg-[var(--color-brand-subtle)]"
                                                    style={{ borderRadius: '50%' }}
                                                >
                                                    {record.employee.photo_url ? (
                                                        <img
                                                            src={record.employee.photo_url}
                                                            alt={record.employee.name}
                                                            className="size-full object-cover"
                                                        />
                                                    ) : (
                                                        <span className="text-sm font-semibold text-[var(--color-brand)]">
                                                            {record.employee.name.split(' ').map(n => n[0]).join('')}
                                                        </span>
                                                    )}
                                                </div>
                                                <div>
                                                    <p className="font-medium text-[var(--color-text-main)]">
                                                        {record.employee.name}
                                                    </p>
                                                    <p className="text-xs text-[var(--color-text-muted)]">
                                                        ID: {record.employee.employee_id}
                                                    </p>
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'date',
                                        label: 'Date',
                                        sortable: true,
                                        accessor: (r) => formatDate(r.date),
                                    },
                                    {
                                        key: 'check_in',
                                        label: 'Check In',
                                        render: (r) => r.check_in ? formatTime(r.check_in) : '—',
                                    },
                                    {
                                        key: 'check_out',
                                        label: 'Check Out',
                                        render: (r) => r.check_out ? formatTime(r.check_out) : '—',
                                    },
                                    {
                                        key: 'hours_worked',
                                        label: 'Hours',
                                        align: 'center',
                                        render: (r) => r.hours_worked ? `${r.hours_worked.toFixed(1)}h` : '—',
                                    },
                                    {
                                        key: 'overtime_hours',
                                        label: 'Overtime',
                                        align: 'center',
                                        render: (r) => r.overtime_hours && r.overtime_hours > 0 ? (
                                            <span className="text-[var(--color-warning)]">{r.overtime_hours.toFixed(1)}h</span>
                                        ) : '—',
                                    },
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        render: (r) => (
                                            <StatusBadge
                                                label={attendanceStatusLabels[r.status]}
                                                variant={attendanceStatusVariants[r.status]}
                                                dot
                                            />
                                        ),
                                    },
                                ]}
                                sortBy={sortBy}
                                sortDirection={sortDirection}
                                onSort={handleSort}
                                onRowClick={(record) => setSelectedRecord(record)}
                                clickable
                                getRowKey={(record) => record.id}
                                emptyState={
                                    <EmptyState
                                        icon="clock-user"
                                        title={hasFilters ? 'No records match' : 'No attendance records yet'}
                                        body={
                                            hasFilters
                                                ? 'Try adjusting your filters to see more results.'
                                                : 'Attendance records will appear here once employees check in.'
                                        }
                                        action={
                                            hasFilters ? (
                                                <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                    Clear filters
                                                </button>
                                            ) : null
                                        }
                                    />
                                }
                            />
                        </div>
                    )
                ) : (
                    errorLeave ? (
                        <div className="card mt-6 p-6 text-center">
                            <p className="text-sm text-[var(--color-text-body)]">
                                Failed to load leave requests.
                            </p>
                            <button
                                type="button"
                                onClick={() => void refetchLeave()}
                                className="btn btn-secondary mt-4"
                            >
                                Try again
                            </button>
                        </div>
                    ) : (
                        <div className="card mt-6 overflow-hidden">
                            <Table
                                data={leaveRequests}
                                loading={loadingLeave}
                                skeletonRows={10}
                                columns={[
                                    {
                                        key: 'employee',
                                        label: 'Employee',
                                        render: (leave) => (
                                            <div>
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {leave.employee.name}
                                                </p>
                                                <p className="text-xs text-[var(--color-text-muted)]">
                                                    ID: {leave.employee.employee_id}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'leave_type',
                                        label: 'Type',
                                        accessor: (l) => leaveTypeLabels[l.leave_type],
                                    },
                                    {
                                        key: 'start_date',
                                        label: 'From',
                                        sortable: true,
                                        accessor: (l) => formatDate(l.start_date),
                                    },
                                    {
                                        key: 'end_date',
                                        label: 'To',
                                        accessor: (l) => formatDate(l.end_date),
                                    },
                                    {
                                        key: 'days_count',
                                        label: 'Days',
                                        align: 'center',
                                        render: (l) => (
                                            <span className="font-medium">
                                                {l.days_count} {l.days_count === 1 ? 'day' : 'days'}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        render: (l) => (
                                            <StatusBadge
                                                label={leaveStatusLabels[l.status]}
                                                variant={leaveStatusVariants[l.status]}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'actions',
                                        label: '',
                                        width: 'w-32',
                                        render: (leave) =>
                                            leave.status === 'pending' && (
                                                <div className="flex gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            handleApproveLeave(leave.id);
                                                        }}
                                                        className="text-xs text-[var(--color-success)] hover:underline"
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            handleRejectLeave(leave.id);
                                                        }}
                                                        className="text-xs text-[var(--color-danger)] hover:underline"
                                                    >
                                                        Reject
                                                    </button>
                                                </div>
                                            ),
                                    },
                                ]}
                                sortBy={sortBy}
                                sortDirection={sortDirection}
                                onSort={handleSort}
                                onRowClick={(leave) => setSelectedLeave(leave)}
                                clickable
                                getRowKey={(leave) => leave.id}
                                emptyState={
                                    <EmptyState
                                        icon="calendar-x"
                                        title={hasFilters ? 'No leave requests match' : 'No leave requests yet'}
                                        body={
                                            hasFilters
                                                ? 'Try adjusting your filters to see more results.'
                                                : 'Leave requests will appear here when employees request time off.'
                                        }
                                        action={
                                            hasFilters ? (
                                                <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                    Clear filters
                                                </button>
                                            ) : (
                                                <button
                                                    className="btn btn-primary"
                                                    onClick={() => setShowLeaveModal(true)}
                                                >
                                                    Request Leave
                                                </button>
                                            )
                                        }
                                    />
                                }
                            />
                        </div>
                    )
                )}
            </div>

            {/* Attendance Detail Drawer */}
            <DetailDrawer
                open={!!selectedRecord}
                onClose={() => setSelectedRecord(null)}
                title={selectedRecord?.employee.name ?? ''}
                subtitle={selectedRecord ? formatDate(selectedRecord.date) : ''}
                size="md"
            >
                {selectedRecord && (
                    <div className="space-y-6">
                        <DrawerSection title="Attendance Details">
                            <DrawerField
                                label="Employee"
                                value={selectedRecord.employee.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Employee ID"
                                value={selectedRecord.employee.employee_id}
                                icon="hash"
                            />
                            <DrawerField
                                label="Date"
                                value={formatDate(selectedRecord.date)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={attendanceStatusLabels[selectedRecord.status]}
                                        variant={attendanceStatusVariants[selectedRecord.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        {selectedRecord.check_in && (
                            <DrawerSection title="Time Records">
                                <DrawerField
                                    label="Check In"
                                    value={formatTime(selectedRecord.check_in)}
                                    icon="sign-in"
                                />
                                {selectedRecord.check_out && (
                                    <DrawerField
                                        label="Check Out"
                                        value={formatTime(selectedRecord.check_out)}
                                        icon="sign-out"
                                    />
                                )}
                                {selectedRecord.hours_worked && (
                                    <DrawerField
                                        label="Hours Worked"
                                        value={`${selectedRecord.hours_worked.toFixed(1)} hours`}
                                        icon="clock"
                                    />
                                )}
                                {selectedRecord.overtime_hours && selectedRecord.overtime_hours > 0 && (
                                    <DrawerField
                                        label="Overtime"
                                        value={`${selectedRecord.overtime_hours.toFixed(1)} hours`}
                                        icon="clock-countdown"
                                    />
                                )}
                            </DrawerSection>
                        )}

                        {selectedRecord.notes && (
                            <DrawerSection title="Notes">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedRecord.notes}
                                </p>
                            </DrawerSection>
                        )}
                    </div>
                )}
            </DetailDrawer>

            {/* Leave Detail Drawer */}
            <DetailDrawer
                open={!!selectedLeave}
                onClose={() => setSelectedLeave(null)}
                title={selectedLeave?.employee.name ?? ''}
                subtitle={selectedLeave ? leaveTypeLabels[selectedLeave.leave_type] : ''}
                size="md"
                actions={
                    selectedLeave?.status === 'pending' && (
                        <>
                            <button
                                className="btn btn-success"
                                onClick={() => handleApproveLeave(selectedLeave.id)}
                            >
                                <Icon name="check" size={16} />
                                <span>Approve</span>
                            </button>
                            <button
                                className="btn btn-danger"
                                onClick={() => handleRejectLeave(selectedLeave.id)}
                            >
                                <Icon name="x" size={16} />
                                <span>Reject</span>
                            </button>
                        </>
                    )
                }
            >
                {selectedLeave && (
                    <div className="space-y-6">
                        <DrawerSection title="Leave Request">
                            <DrawerField
                                label="Employee"
                                value={selectedLeave.employee.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Leave Type"
                                value={leaveTypeLabels[selectedLeave.leave_type]}
                                icon="calendar-x"
                            />
                            <DrawerField
                                label="Start Date"
                                value={formatDate(selectedLeave.start_date)}
                                icon="calendar-check"
                            />
                            <DrawerField
                                label="End Date"
                                value={formatDate(selectedLeave.end_date)}
                                icon="calendar-check"
                            />
                            <DrawerField
                                label="Duration"
                                value={`${selectedLeave.days_count} ${selectedLeave.days_count === 1 ? 'day' : 'days'}`}
                                icon="clock"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={leaveStatusLabels[selectedLeave.status]}
                                        variant={leaveStatusVariants[selectedLeave.status]}
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        {selectedLeave.reason && (
                            <DrawerSection title="Reason">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedLeave.reason}
                                </p>
                            </DrawerSection>
                        )}

                        {selectedLeave.approver && (
                            <DrawerSection title="Approval">
                                <DrawerField
                                    label="Approved By"
                                    value={selectedLeave.approver.name}
                                    icon="user-check"
                                />
                                {selectedLeave.approved_at && (
                                    <DrawerField
                                        label="Approved On"
                                        value={formatDate(selectedLeave.approved_at)}
                                        icon="calendar-check"
                                    />
                                )}
                            </DrawerSection>
                        )}
                    </div>
                )}
            </DetailDrawer>

            {/* Request Leave Modal */}
            <QuickCreateModal
                open={showLeaveModal}
                onClose={() => setShowLeaveModal(false)}
                title="Request Leave"
                onSubmit={handleSubmitLeave}
                submitLabel="Submit Request"
                size="md"
            >
                <div className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Leave Type <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <select
                            value={leaveForm.leave_type}
                            onChange={(e) => setLeaveForm({ ...leaveForm, leave_type: e.target.value as any })}
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        >
                            <option value="annual">Annual Leave</option>
                            <option value="sick">Sick Leave</option>
                            <option value="personal">Personal Leave</option>
                            <option value="unpaid">Unpaid Leave</option>
                            <option value="maternity">Maternity Leave</option>
                            <option value="paternity">Paternity Leave</option>
                        </select>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Start Date <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="date"
                                value={leaveForm.start_date}
                                onChange={(e) => setLeaveForm({ ...leaveForm, start_date: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                End Date <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="date"
                                value={leaveForm.end_date}
                                onChange={(e) => setLeaveForm({ ...leaveForm, end_date: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Reason
                        </label>
                        <textarea
                            value={leaveForm.reason}
                            onChange={(e) => setLeaveForm({ ...leaveForm, reason: e.target.value })}
                            rows={3}
                            placeholder="Provide a reason for your leave request..."
                            className="w-full resize-none border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
