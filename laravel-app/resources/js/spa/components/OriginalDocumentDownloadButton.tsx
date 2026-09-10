import { useMutation } from '@tanstack/react-query';
import { useRef } from 'react';

import { apiClient } from '@/api/client';

interface OriginalDocumentDownloadButtonProps {
    url: string;
    filename: string;
    label?: string;
}

async function downloadOriginalDocument(url: string, filename: string): Promise<void> {
    // Use the SPA client so Sanctum receives the session cookie and browser request context.
    const response = await apiClient.get<Blob>(url, { responseType: 'blob' });

    if (!(response.data instanceof Blob) || response.data.type.split(';')[0].toLowerCase() !== 'application/pdf') {
        throw new Error('ไฟล์ที่ได้รับไม่ใช่ PDF กรุณาลองใหม่');
    }

    const link = document.createElement('a');
    const blobUrl = URL.createObjectURL(response.data);
    link.href = blobUrl;
    link.download = filename;
    link.hidden = true;

    try {
        document.body.appendChild(link);
        link.click();
    } finally {
        link.remove();
        // Allow the browser to consume the URL before releasing the original bytes.
        window.setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000);
    }
}

export function OriginalDocumentDownloadButton({ url, filename, label = 'เปิด / ดาวน์โหลด' }: OriginalDocumentDownloadButtonProps) {
    const downloading = useRef(false);
    const downloadMutation = useMutation({ mutationFn: () => downloadOriginalDocument(url, filename) });

    const handleDownload = async () => {
        if (downloading.current) return;
        downloading.current = true;

        try {
            await downloadMutation.mutateAsync();
        } catch {
            // The mutation error is shown beside the download action.
        } finally {
            downloading.current = false;
        }
    };

    return (
        <div className="flex max-w-full shrink-0 flex-col items-start gap-2">
            <button aria-busy={downloadMutation.isPending} className="spa-button-secondary" disabled={downloadMutation.isPending} onClick={handleDownload} type="button">
                {downloadMutation.isPending ? 'กำลังดาวน์โหลด…' : label}
            </button>
            {downloadMutation.isError && <p className="max-w-sm text-sm text-rose-700" role="alert">{downloadMutation.error instanceof Error ? downloadMutation.error.message : 'ไม่สามารถดาวน์โหลดไฟล์ต้นฉบับได้ กรุณาลองใหม่'}</p>}
        </div>
    );
}
