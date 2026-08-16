import { useRef, useState, type DragEvent, type InputHTMLAttributes } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type FileUploadProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange'> & {
    /** Label text */
    label?: string;
    /** Helper text */
    helperText?: string;
    /** Callback when files are selected */
    onFilesSelected: (files: File[]) => void;
    /** Maximum file size in bytes */
    maxSize?: number;
    /** Accepted file types (MIME types or extensions) */
    accept?: string;
    /** Allow multiple files */
    multiple?: boolean;
    /** Show preview of selected files */
    showPreview?: boolean;
    /** Error message */
    error?: string;
    /** Compact mode (smaller) */
    compact?: boolean;
};

/**
 * File upload component with drag-and-drop support.
 *
 * Features:
 * - Drag and drop files
 * - Click to browse
 * - Multiple file support
 * - File type validation
 * - File size validation
 * - Preview selected files
 * - Error states
 *
 * @example
 * ```tsx
 * <FileUpload
 *   onFilesSelected={(files) => handleUpload(files)}
 *   accept="image/*"
 *   multiple
 *   maxSize={5 * 1024 * 1024} // 5MB
 *   showPreview
 * />
 * ```
 */
export function FileUpload({
    label,
    helperText,
    onFilesSelected,
    maxSize,
    accept,
    multiple = false,
    showPreview = true,
    error,
    compact = false,
    disabled,
    className,
    ...props
}: FileUploadProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
    const [validationError, setValidationError] = useState<string | null>(null);

    const displayError = error || validationError;

    const validateFiles = (files: File[]): { valid: File[]; error: string | null } => {
        const validFiles: File[] = [];
        let errorMessage: string | null = null;

        for (const file of files) {
            // Check file size
            if (maxSize && file.size > maxSize) {
                errorMessage = `File "${file.name}" is too large. Maximum size is ${formatFileSize(maxSize)}.`;
                continue;
            }

            // Check file type
            if (accept) {
                const acceptedTypes = accept.split(',').map((t) => t.trim());
                const fileExtension = '.' + file.name.split('.').pop()?.toLowerCase();
                const isAccepted = acceptedTypes.some((type) => {
                    if (type.startsWith('.')) {
                        return fileExtension === type.toLowerCase();
                    }
                    // MIME type check
                    return file.type.match(new RegExp(type.replace('*', '.*')));
                });

                if (!isAccepted) {
                    errorMessage = `File "${file.name}" type is not accepted.`;
                    continue;
                }
            }

            validFiles.push(file);
        }

        return { valid: validFiles, error: errorMessage };
    };

    const handleFiles = (files: FileList | null) => {
        if (!files || files.length === 0) return;

        const fileArray = Array.from(files);
        const { valid, error: validationErr } = validateFiles(fileArray);

        setValidationError(validationErr);

        if (valid.length > 0) {
            setSelectedFiles(valid);
            onFilesSelected(valid);
        }
    };

    const handleDragEnter = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
        if (!disabled) {
            setIsDragging(true);
        }
    };

    const handleDragLeave = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragging(false);
    };

    const handleDragOver = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
    };

    const handleDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragging(false);

        if (disabled) return;

        const files = e.dataTransfer.files;
        handleFiles(files);
    };

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        handleFiles(e.target.files);
    };

    const handleClick = () => {
        if (!disabled) {
            inputRef.current?.click();
        }
    };

    const handleRemoveFile = (index: number) => {
        const newFiles = selectedFiles.filter((_, i) => i !== index);
        setSelectedFiles(newFiles);
        onFilesSelected(newFiles);
        setValidationError(null);
    };

    return (
        <div className={cn('space-y-1.5', className)}>
            {label && (
                <label className="block text-sm font-medium text-[var(--color-text-main)]">
                    {label}
                </label>
            )}
            {/* Drop zone */}
            <div
                onDragEnter={handleDragEnter}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                onClick={handleClick}
                style={{ borderRadius: 'var(--shell-radius)' }}
                className={cn(
                    'cursor-pointer border-2 border-dashed transition-all duration-150',
                    'flex flex-col items-center justify-center',
                    'bg-[var(--color-card-bg)]',
                    compact ? 'p-4' : 'p-8',
                    // States
                    isDragging && !disabled && 'border-[var(--color-brand)] bg-[var(--color-brand-subtle)]',
                    !isDragging &&
                        !disabled &&
                        !displayError &&
                        'border-[var(--color-border-light)] hover:border-[var(--color-brand)] hover:bg-[var(--color-brand-subtle)]',
                    displayError && 'border-red-300 bg-red-50',
                    disabled && 'cursor-not-allowed opacity-50',
                )}
            >
                <input
                    ref={inputRef}
                    type="file"
                    accept={accept}
                    multiple={multiple}
                    disabled={disabled}
                    onChange={handleInputChange}
                    className="hidden"
                    {...props}
                />

                <div className="flex flex-col items-center gap-2 text-center">
                    <div
                        className={cn(
                            'rounded-lg p-2',
                            isDragging
                                ? 'bg-[var(--color-brand)] text-white'
                                : 'bg-[var(--color-brand-subtle)] text-[var(--color-brand)]',
                        )}
                    >
                        <Icon name="upload-simple" size={compact ? 20 : 24} weight="bold" />
                    </div>

                    <div>
                        <p className={cn('font-medium text-[var(--color-text-main)]', compact ? 'text-xs' : 'text-sm')}>
                            {isDragging ? (
                                'Drop files here'
                            ) : (
                                <>
                                    <span className="text-[var(--color-brand)]">Click to upload</span> or drag and drop
                                </>
                            )}
                        </p>
                        {!compact && (
                            <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                {accept ? `Accepted: ${accept}` : 'Any file type'}
                                {maxSize && ` • Max ${formatFileSize(maxSize)}`}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            {/* Error message */}
            {helperText && !displayError && (
                <p className="text-xs text-[var(--color-text-muted)]">{helperText}</p>
            )}
            {displayError && (
                <p className="text-xs font-medium text-red-600" role="alert">
                    {displayError}
                </p>
            )}

            {/* Preview selected files */}
            {showPreview && selectedFiles.length > 0 && (
                <div className="space-y-2">
                    <p className="text-xs font-medium text-[var(--color-text-muted)]">
                        Selected {selectedFiles.length} file{selectedFiles.length !== 1 ? 's' : ''}
                    </p>
                    <div className="space-y-2">
                        {selectedFiles.map((file, index) => (
                            <div
                                key={index}
                                className="flex items-center gap-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-card-bg)] p-3"
                            >
                                <div className="flex-none rounded bg-[var(--color-brand-subtle)] p-2 text-[var(--color-brand)]">
                                    <Icon name="file" size={16} />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-[var(--color-text-main)]">
                                        {file.name}
                                    </p>
                                    <p className="text-xs text-[var(--color-text-muted)]">{formatFileSize(file.size)}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        handleRemoveFile(index);
                                    }}
                                    className="flex-none rounded-lg p-1 text-[var(--color-text-muted)] transition-colors hover:bg-red-100 hover:text-red-600"
                                    aria-label={`Remove ${file.name}`}
                                >
                                    <Icon name="x" size={16} weight="bold" />
                                </button>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

function formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';

    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

