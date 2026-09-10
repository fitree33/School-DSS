import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type DragEvent, type FormEvent, useRef, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FieldError, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon, DocumentIcon, UploadIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import {
    fetchDocumentImportOptions,
    importKeys,
    uploadDocumentImport,
} from '@/features/imports/api';
import { formatFileSize, validateImportFile } from '@/features/imports/model';

export function ImportUploadPage() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const inputRef = useRef<HTMLInputElement>(null);
    const { hasPermission } = useAuth();
    const [file, setFile] = useState<File | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [uploadProgress, setUploadProgress] = useState<number | null>(null);
    const [isDragging, setIsDragging] = useState(false);

    const optionsQuery = useQuery({
        queryKey: importKeys.options,
        queryFn: fetchDocumentImportOptions,
        staleTime: 5 * 60_000,
    });

    const uploadMutation = useMutation({
        mutationFn: (selectedFile: File) => uploadDocumentImport(selectedFile, setUploadProgress),
        onSuccess: async (documentImport) => {
            queryClient.setQueryData(importKeys.detail(documentImport.public_id), documentImport);
            await queryClient.invalidateQueries({ queryKey: importKeys.lists() });
            navigate(`/imports/${documentImport.public_id}`, { replace: true });
        },
    });

    if (!hasPermission('imports.create')) return <Navigate replace to="/forbidden" />;
    if (isApiError(optionsQuery.error) && optionsQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (optionsQuery.isPending) return <LoadingBlock label="กำลังเตรียมหน้ารับไฟล์ PDF" />;
    if (optionsQuery.isError) {
        return (
            <ErrorState
                action={<button className="spa-button-primary" onClick={() => void optionsQuery.refetch()} type="button">ลองใหม่</button>}
                message={isApiError(optionsQuery.error) ? optionsQuery.error.message : 'ไม่สามารถโหลดข้อกำหนดการอัปโหลดได้'}
                title="เตรียมการอัปโหลดไม่สำเร็จ"
            />
        );
    }

    const constraints = optionsQuery.data.constraints;
    const accept = [...new Set(['.pdf', ...constraints.accepted_mime_types])].join(',');

    const chooseFile = (nextFile: File | null) => {
        const validationError = validateImportFile(nextFile, constraints);
        setFile(nextFile);
        setFileError(validationError);
        setSubmitError(null);
        setUploadProgress(null);
    };

    const openFilePicker = () => {
        if (!inputRef.current || uploadMutation.isPending) return;
        inputRef.current.value = '';
        inputRef.current.click();
    };

    const clearFile = () => {
        if (inputRef.current) inputRef.current.value = '';
        setFile(null);
        setFileError(null);
        setSubmitError(null);
        setUploadProgress(null);
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(false);
        if (uploadMutation.isPending) return;
        chooseFile(event.dataTransfer.files.item(0));
    };

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (uploadMutation.isPending) return;

        const validationError = validateImportFile(file, constraints);
        setFileError(validationError);
        setSubmitError(null);
        if (!file || validationError) return;

        setUploadProgress(0);

        try {
            await uploadMutation.mutateAsync(file);
        } catch (unknownError) {
            if (unknownError instanceof ApiError) {
                if (unknownError.status === 403) {
                    navigate('/forbidden', { replace: true });
                    return;
                }

                setFileError(unknownError.errors.document?.[0] ?? null);
                setSubmitError(unknownError.message);
            } else {
                setSubmitError('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่');
            }
        }
    };

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to="/imports">
                <ArrowLeftIcon className="size-4" />กลับรายการนำเข้า
            </Link>

            <PageHeader
                description="อัปโหลด PDF ที่เลือกหรือคัดลอกข้อความได้ ระบบจะเก็บไฟล์ต้นฉบับไว้และแจ้งสถานะระหว่างอ่านข้อมูล"
                eyebrow="PDF / AI Project Import"
                title="อัปโหลดเอกสารโครงการ"
            />

            {submitError && <ErrorState message={submitError} title="อัปโหลดไม่สำเร็จ" />}

            <form className="spa-card mx-auto max-w-4xl p-5 sm:p-8" onSubmit={handleSubmit}>
                <div
                    className={`rounded-2xl border-2 border-dashed px-6 py-12 text-center transition ${isDragging ? 'border-teal-500 bg-teal-50' : fileError ? 'border-rose-300 bg-rose-50/40' : 'border-slate-300 bg-slate-50 hover:border-teal-400 hover:bg-teal-50/40'}`}
                    onDragEnter={(event) => { event.preventDefault(); setIsDragging(true); }}
                    onDragLeave={(event) => { event.preventDefault(); setIsDragging(false); }}
                    onDragOver={(event) => event.preventDefault()}
                    onDrop={handleDrop}
                >
                    <input
                        accept={accept}
                        className="sr-only"
                        disabled={uploadMutation.isPending}
                        id="import-document"
                        onChange={(event) => chooseFile(event.target.files?.item(0) ?? null)}
                        ref={inputRef}
                        type="file"
                    />
                    <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-white text-teal-700 shadow-sm ring-1 ring-slate-200">
                        <UploadIcon className="size-8" />
                    </div>
                    <h2 className="mt-5 text-lg font-bold text-slate-900">ลากไฟล์ PDF มาวาง หรือเลือกจากเครื่อง</h2>
                    <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">
                        รองรับ PDF ขนาดไม่เกิน {formatFileSize(constraints.max_bytes)} และต้องเป็นไฟล์ที่อ่านข้อความได้
                    </p>
                    <button className="spa-button-secondary mt-5" disabled={uploadMutation.isPending} onClick={openFilePicker} type="button">
                        เลือกไฟล์
                    </button>
                    <FieldError errors={fileError ? [fileError] : undefined} />
                </div>

                {file && !fileError && (
                    <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
                        <div className="flex items-center gap-3">
                            <div className="grid size-11 shrink-0 place-items-center rounded-xl bg-rose-50 text-rose-700"><DocumentIcon className="size-5" /></div>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-bold text-slate-900">{file.name}</p>
                                <p className="mt-1 text-xs text-slate-500">{formatFileSize(file.size)} · ไฟล์นี้จะเป็นหลักฐานต้นฉบับและไม่ถูกแทนที่ภายหลัง</p>
                            </div>
                            {!uploadMutation.isPending && <button className="text-sm font-semibold text-rose-700 hover:text-rose-800" onClick={clearFile} type="button">นำออก</button>}
                        </div>

                        {uploadMutation.isPending && (
                            <div className="mt-4" role="status">
                                <div className="flex items-center justify-between text-xs font-semibold text-slate-600">
                                    <span>{uploadProgress === 100 ? 'ส่งไฟล์แล้ว กำลังสร้างรายการนำเข้า…' : 'กำลังอัปโหลด…'}</span>
                                    <span>{uploadProgress === null ? 'กำลังดำเนินการ' : `${uploadProgress}%`}</span>
                                </div>
                                <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div className={`h-full rounded-full bg-teal-600 transition-all ${uploadProgress === null ? 'w-1/3 animate-pulse' : ''}`} style={uploadProgress === null ? undefined : { width: `${uploadProgress}%` }} />
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <div className="mt-7 rounded-2xl border border-sky-200 bg-sky-50 px-5 py-4 text-sm leading-6 text-sky-950">
                    <p className="font-bold">ก่อนอัปโหลด</p>
                    <p className="mt-1">ไฟล์สแกนหรือ PDF ที่มีแต่รูปภาพยังไม่รองรับ หากระบบอ่านข้อความไม่ได้ จะแจ้งสาเหตุและเก็บไฟล์ต้นฉบับไว้ให้ตรวจสอบ</p>
                </div>

                <div className="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link className="spa-button-secondary" to="/imports">ยกเลิก</Link>
                    <button className="spa-button-primary" disabled={!file || Boolean(fileError) || uploadMutation.isPending} type="submit">
                        {uploadMutation.isPending ? 'กำลังอัปโหลด…' : 'อัปโหลดและเริ่มประมวลผล'}
                    </button>
                </div>
            </form>
        </div>
    );
}
