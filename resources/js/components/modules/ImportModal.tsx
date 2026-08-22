import { useState, useRef } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type SupportedFormat = {
    type: string;
    label: string;
    extension: string;
    icon: string;
    description: string;
    accept: string;
};

const SUPPORTED_FORMATS: SupportedFormat[] = [
    {
        type: 'csv',
        label: 'CSV File',
        extension: '.csv',
        icon: 'file-csv',
        description: 'Comma-separated values (Excel, Google Sheets)',
        accept: '.csv,text/csv',
    },
    {
        type: 'excel',
        label: 'Excel File',
        extension: '.xlsx',
        icon: 'file-xls',
        description: 'Microsoft Excel workbook',
        accept: '.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel',
    },
    {
        type: 'json',
        label: 'JSON File',
        extension: '.json',
        icon: 'file-text',
        description: 'JSON format from API exports',
        accept: '.json,application/json',
    },
];

type ImportModalProps = {
    isOpen: boolean;
    onClose: () => void;
    onImport: (file: File, format: string) => void | Promise<void>;
    title?: string;
    description?: string;
    entityType?: string;
};

export function ImportModal({
    isOpen,
    onClose,
    onImport,
    title = 'Import Data',
    description = 'Upload a file to import records',
    entityType = 'records',
}: ImportModalProps) {
    const [selectedFormat, setSelectedFormat] = useState<string | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [isUploading, setIsUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setSelectedFile(file);
            detectFormat(file);
        }
    };

    const detectFormat = (file: File) => {
        const extension = file.name.split('.').pop()?.toLowerCase();
        const format = SUPPORTED_FORMATS.find((f) => f.extension.includes(extension || ''));
        if (format) {
            setSelectedFormat(format.type);
        }
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        
        const file = e.dataTransfer.files[0];
        if (file) {
            setSelectedFile(file);
            detectFormat(file);
        }
    };

    const handleDragOver = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    const handleImport = async () => {
        if (!selectedFile || !selectedFormat) return;

        setIsUploading(true);
        try {
            await onImport(selectedFile, selectedFormat);
            handleClose();
        } catch (error) {
            console.error('Import failed:', error);
        } finally {
            setIsUploading(false);
        }
    };

    const handleClose = () => {
        setSelectedFile(null);
        setSelectedFormat(null);
        setIsDragging(false);
        setIsUploading(false);
        onClose();
    };

    const acceptedTypes = SUPPORTED_FORMATS.map((f) => f.accept).join(',');

    return (
        <Modal
            isOpen={isOpen}
            onClose={handleClose}
            title={title}
            size="lg"
        >
            <div className="space-y-6">
                {/* Description */}
                <p className="text-sm text-[var(--color-text-body)]">{description}</p>

                {/* Format Selection */}
                <div>
                    <label className="mb-3 block text-sm font-medium text-[var(--color-text-main)]">
                        Select File Format
                    </label>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {SUPPORTED_FORMATS.map((format) => (
                            <button
                                key={format.type}
                                type="button"
                                onClick={() => setSelectedFormat(format.type)}
                                className={cn(
                                    'flex flex-col items-center gap-2 rounded-lg border-2 p-4 text-center transition-colors',
                                    selectedFormat === format.type
                                        ? 'border-[var(--color-brand)] bg-[var(--color-brand-subtle)]'
                                        : 'border-[var(--shell-border)] hover:border-[var(--color-brand-muted)]'
                                )}
                            >
                                <Icon name={format.icon} size={32} />
                                <div>
                                    <p className="font-medium text-[var(--color-text-main)]">
                                        {format.label}
                                    </p>
                                    <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                        {format.description}
                                    </p>
                                </div>
                            </button>
                        ))}
                    </div>
                </div>

                {/* File Upload Area */}
                {selectedFormat && (
                    <div>
                        <label className="mb-3 block text-sm font-medium text-[var(--color-text-main)]">
                            Upload File
                        </label>
                        <div
                            onDrop={handleDrop}
                            onDragOver={handleDragOver}
                            onDragLeave={handleDragLeave}
                            className={cn(
                                'relative rounded-lg border-2 border-dashed p-8 text-center transition-colors',
                                isDragging
                                    ? 'border-[var(--color-brand)] bg-[var(--color-brand-subtle)]'
                                    : 'border-[var(--shell-border)]'
                            )}
                        >
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept={acceptedTypes}
                                onChange={handleFileSelect}
                                className="hidden"
                            />

                            {selectedFile ? (
                                <div className="flex items-center justify-center gap-3">
                                    <Icon name="file-check" size={32} className="text-[var(--color-success)]" />
                                    <div className="text-left">
                                        <p className="font-medium text-[var(--color-text-main)]">
                                            {selectedFile.name}
                                        </p>
                                        <p className="text-sm text-[var(--color-text-muted)]">
                                            {(selectedFile.size / 1024).toFixed(2)} KB
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setSelectedFile(null)}
                                        className="ml-auto rounded p-1 hover:bg-[var(--color-background-subtle)]"
                                    >
                                        <Icon name="x" size={16} />
                                    </button>
                                </div>
                            ) : (
                                <>
                                    <Icon
                                        name="upload-simple"
                                        size={48}
                                        className="mx-auto text-[var(--color-text-muted)]"
                                    />
                                    <p className="mt-4 font-medium text-[var(--color-text-main)]">
                                        Drop your file here or{' '}
                                        <button
                                            type="button"
                                            onClick={() => fileInputRef.current?.click()}
                                            className="text-[var(--color-brand)] hover:underline"
                                        >
                                            browse
                                        </button>
                                    </p>
                                    <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                                        Supports CSV, Excel (XLSX/XLS), and JSON files
                                    </p>
                                </>
                            )}
                        </div>
                    </div>
                )}

                {/* Info Message */}
                <div className="rounded-lg bg-[var(--color-info-subtle)] p-4">
                    <div className="flex gap-3">
                        <Icon name="info" size={20} className="mt-0.5 flex-shrink-0 text-[var(--color-info)]" />
                        <div className="text-sm text-[var(--color-text-body)]">
                            <p className="font-medium text-[var(--color-text-main)]">
                                Import Tips
                            </p>
                            <ul className="mt-2 space-y-1 text-sm">
                                <li>• First row should contain column headers</li>
                                <li>• We'll auto-detect and map your columns to our fields</li>
                                <li>• Duplicate {entityType} will be updated (matched by order number)</li>
                                <li>• Maximum file size: 10 MB</li>
                                <li>
                                    •{' '}
                                    <a
                                        href="/templates/orders-import-template.csv"
                                        download
                                        className="text-[var(--color-brand)] hover:underline"
                                    >
                                        Download sample CSV template
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                {/* Actions */}
                <div className="flex gap-3">
                    <button
                        type="button"
                        onClick={handleClose}
                        disabled={isUploading}
                        className="btn btn-secondary flex-1"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handleImport}
                        disabled={!selectedFile || !selectedFormat || isUploading}
                        className="btn btn-primary flex-1"
                    >
                        {isUploading ? (
                            <>
                                <Icon name="circle-notch" size={16} className="animate-spin" />
                                <span>Processing...</span>
                            </>
                        ) : (
                            <>
                                <Icon name="upload" size={16} />
                                <span>Import {entityType}</span>
                            </>
                        )}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
