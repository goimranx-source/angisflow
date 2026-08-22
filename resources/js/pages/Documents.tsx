import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
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

type Document = {
    id: string;
    name: string;
    type: 'pdf' | 'doc' | 'xlsx' | 'image' | 'other';
    size: number; // in bytes
    folder?: string;
    uploaded_by: {
        id: string;
        name: string;
    };
    tags: string[];
    is_shared: boolean;
    download_count: number;
    uploaded_at: string;
};

type DocumentsResponse = {
    data: Document[];
    summary: {
        total_documents: number;
        total_size: number; // in bytes
        folders_count: number;
        shared_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Documents() {
    useDocumentTitle('Documents');

    // State
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [folderFilter, setFolderFilter] = useState('');
    const [selectedDocument, setSelectedDocument] = useState<Document | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('uploaded_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch documents
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['documents', { search, typeFilter, folderFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<DocumentsResponse>('/documents', {
                params: {
                    search,
                    type: typeFilter || undefined,
                    folder: folderFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const documents = data?.data ?? [];
    const summary = data?.summary;

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
        setTypeFilter('');
        setFolderFilter('');
    };

    const hasFilters = search || typeFilter || folderFilter;

    // Format file size
    const formatFileSize = (bytes: number) => {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
    };

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // File type icons and colors
    const fileTypeMeta: Record<Document['type'], { icon: string; color: string }> = {
        pdf: { icon: 'file-pdf', color: 'text-[var(--color-danger)]' },
        doc: { icon: 'file-doc', color: 'text-[var(--color-info)]' },
        xlsx: { icon: 'file-xls', color: 'text-[var(--color-success)]' },
        image: { icon: 'file-image', color: 'text-[var(--color-warning)]' },
        other: { icon: 'file', color: 'text-[var(--color-text-muted)]' },
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Documents"
                    description="Store and manage all your business documents"
                    icon="notebook"
                    actions={
                        <div className="flex gap-3">
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('New folder')}
                            >
                                <Icon name="folder-plus" size={16} />
                                <span>New folder</span>
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => console.log('Upload')}
                            >
                                <Icon name="upload" size={16} />
                                <span>Upload</span>
                            </button>
                        </div>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Documents"
                        value={summary.total_documents.toLocaleString()}
                        icon="notebook"
                        variant="brand"
                    />
                    <KPICard
                        label="Storage Used"
                        value={formatFileSize(summary.total_size)}
                        icon="hard-drive"
                        variant="info"
                    />
                    <KPICard
                        label="Folders"
                        value={summary.folders_count.toLocaleString()}
                        icon="folder"
                        variant="warning"
                    />
                    <KPICard
                        label="Shared"
                        value={summary.shared_count.toLocaleString()}
                        icon="share-network"
                        variant="success"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search documents..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'pdf', label: 'PDF' },
                                    { value: 'doc', label: 'Document' },
                                    { value: 'xlsx', label: 'Spreadsheet' },
                                    { value: 'image', label: 'Image' },
                                    { value: 'other', label: 'Other' },
                                ]}
                                placeholder="All types"
                            />
                            <FilterSelect
                                label="Folder"
                                value={folderFilter}
                                onChange={setFolderFilter}
                                options={[
                                    { value: 'invoices', label: 'Invoices' },
                                    { value: 'contracts', label: 'Contracts' },
                                    { value: 'receipts', label: 'Receipts' },
                                    { value: 'reports', label: 'Reports' },
                                ]}
                                placeholder="All folders"
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load documents.</p>
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
                            data={documents}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Name',
                                    render: (doc) => (
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--shell-tint)] ${fileTypeMeta[doc.type].color}`}
                                            >
                                                <Icon name={fileTypeMeta[doc.type].icon} size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <p className="font-medium text-[var(--color-text-main)]">
                                                        {doc.name}
                                                    </p>
                                                    {doc.is_shared && (
                                                        <Icon
                                                            name="share-network"
                                                            size={14}
                                                            className="text-[var(--color-brand)]"
                                                        />
                                                    )}
                                                </div>
                                                {doc.folder && (
                                                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                        {doc.folder}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'size',
                                    label: 'Size',
                                    align: 'right',
                                    sortable: true,
                                    render: (doc) => (
                                        <span className="tabular-nums text-sm">{formatFileSize(doc.size)}</span>
                                    ),
                                },
                                {
                                    key: 'uploaded_by',
                                    label: 'Uploaded By',
                                    accessor: (doc) => doc.uploaded_by.name,
                                },
                                {
                                    key: 'download_count',
                                    label: 'Downloads',
                                    align: 'right',
                                    sortable: true,
                                    accessor: (doc) => doc.download_count.toString(),
                                },
                                {
                                    key: 'uploaded_at',
                                    label: 'Uploaded',
                                    sortable: true,
                                    accessor: (doc) => formatDate(doc.uploaded_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(doc) => setSelectedDocument(doc)}
                            clickable
                            getRowKey={(doc) => doc.id}
                            emptyState={
                                <EmptyState
                                    icon="notebook"
                                    title={hasFilters ? 'No documents match' : 'No documents yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Upload your first document to get started.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="upload" size={16} />
                                                <span>Upload document</span>
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedDocument}
                onClose={() => setSelectedDocument(null)}
                title={selectedDocument?.name ?? ''}
                subtitle={selectedDocument ? formatFileSize(selectedDocument.size) : ''}
                size="md"
            >
                {selectedDocument && (
                    <div className="space-y-6">
                        <DrawerSection title="File Details">
                            <DrawerField
                                label="Name"
                                value={selectedDocument.name}
                                icon="file"
                            />
                            <DrawerField
                                label="Size"
                                value={formatFileSize(selectedDocument.size)}
                                icon="hard-drive"
                            />
                            {selectedDocument.folder && (
                                <DrawerField
                                    label="Folder"
                                    value={selectedDocument.folder}
                                    icon="folder"
                                />
                            )}
                            <DrawerField
                                label="Shared"
                                value={
                                    selectedDocument.is_shared ? (
                                        <StatusBadge label="Yes" variant="success" dot />
                                    ) : (
                                        <StatusBadge label="No" variant="neutral" />
                                    )
                                }
                                icon="share-network"
                            />
                        </DrawerSection>

                        {selectedDocument.tags.length > 0 && (
                            <DrawerSection title="Tags">
                                <div className="flex flex-wrap gap-2">
                                    {selectedDocument.tags.map((tag) => (
                                        <span
                                            key={tag}
                                            className="rounded-md border border-[var(--color-border-light)] bg-[var(--shell-tint)] px-2.5 py-1 text-xs text-[var(--color-text-main)]"
                                        >
                                            {tag}
                                        </span>
                                    ))}
                                </div>
                            </DrawerSection>
                        )}

                        <DrawerSection title="Activity">
                            <DrawerField
                                label="Uploaded By"
                                value={selectedDocument.uploaded_by.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Uploaded"
                                value={formatDate(selectedDocument.uploaded_at)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Downloads"
                                value={selectedDocument.download_count.toString()}
                                icon="download-simple"
                            />
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="download-simple" size={16} />
                                <span>Download</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="share-network" size={16} />
                                <span>Share</span>
                            </button>
                            <button className="btn btn-secondary text-[var(--color-danger)]">
                                <Icon name="trash" size={16} />
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
