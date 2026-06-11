import type { ExceptionFormState } from '@/components/hours/exception-modal';
import { EMPTY_EXCEPTION, ExceptionModal } from '@/components/hours/exception-modal';
import { ExceptionsCalendar } from '@/components/hours/exceptions-calendar';
import { MenuPriorityBanner } from '@/components/hours/menu-priority-banner';
import { OpenStatusBadge } from '@/components/hours/open-status-badge';
import { WeeklyScheduleEditor } from '@/components/hours/weekly-schedule-editor';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { HoursSkeleton } from '@/components/ui/hours-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { useToast } from '@/components/ui/toast';
import { useBusinessHours } from '@/hooks/use-business-hours';
import { useToken } from '@/hooks/use-token';
import type { BusinessHour, BusinessHourException, BusinessHourExceptionFormData, BusinessHourFormData } from '@/types/business-hours';
import { AlertCircle, Plus, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useDocumentTitle } from '@/lib/use-document-title';

const DEFAULT_HOURS: BusinessHourFormData[] = Array.from({ length: 7 }, (_, i) => ({
    day_of_week: i,
    is_enabled: i >= 1 && i <= 5,
    open_time: '08:00',
    close_time: '22:00',
}));

function toFormTime(raw: string | null): string {
    if (!raw) return '';
    return raw.slice(0, 5);
}

function buildScheduleFromApi(apiHours: BusinessHour[]): BusinessHourFormData[] {
    const byDay = Object.fromEntries(apiHours.map((h) => [h.day_of_week, h]));
    return Array.from({ length: 7 }, (_, i) => {
        const h = byDay[i];
        if (!h) return { ...DEFAULT_HOURS[i] };
        return {
            day_of_week: i,
            is_enabled: h.is_enabled,
            open_time: toFormTime(h.open_time) || '08:00',
            close_time: toFormTime(h.close_time) || '22:00',
        };
    });
}

/**
 * Horarios de operación — ruta SPA (#220, Fase 3).
 *
 * Migrada de Inertia: el layout (sidebar + header) lo aporta SpaAppLayout
 * como route layout; esta página renderiza sólo su contenido. La data
 * sigue viniendo de useBusinessHours (apiFetch) — sin cambios de backend.
 */
export default function HoursRoute() {
    useDocumentTitle('Horarios de operación');

    const token = useToken();
    const { showToast } = useToast();

    const {
        hours,
        exceptions,
        status,
        canUpdate,
        loading,
        error,
        fetchHours,
        fetchExceptions,
        fetchStatus,
        updateHours,
        createException,
        updateException,
        deleteException,
    } = useBusinessHours(token);

    const readOnly = !canUpdate;

    const [schedule, setSchedule] = useState<BusinessHourFormData[]>(DEFAULT_HOURS);
    const [saving, setSaving] = useState(false);
    const [showModal, setShowModal] = useState(false);
    const [editingException, setEditingException] = useState<BusinessHourException | null>(null);
    const [exceptionForm, setExceptionForm] = useState<ExceptionFormState>(EMPTY_EXCEPTION);
    const [submittingException, setSubmittingException] = useState(false);
    const [exceptionErrors, setExceptionErrors] = useState<Record<string, string[]>>({});
    const [confirmDelete, setConfirmDelete] = useState<BusinessHourException | null>(null);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
        if (hours.length > 0) {
            setSchedule(buildScheduleFromApi(hours));
        }
    }, [hours]);

    function updateDay(idx: number, patch: Partial<BusinessHourFormData>) {
        setSchedule((prev) => prev.map((d, i) => (i === idx ? { ...d, ...patch } : d)));
    }

    async function handleSaveSchedule() {
        setSaving(true);
        try {
            await updateHours(schedule);
            await fetchStatus();
            showToast('success', 'Horario semanal actualizado.');
        } catch (err: unknown) {
            const apiErr = err as { message?: string };
            showToast('error', apiErr?.message ?? 'Error al guardar el horario.');
        } finally {
            setSaving(false);
        }
    }

    const openCreateException = useCallback((prefillDate?: string) => {
        setEditingException(null);
        setExceptionForm({ ...EMPTY_EXCEPTION, exception_date: prefillDate ?? '' });
        setExceptionErrors({});
        setShowModal(true);
    }, []);

    function openEditException(exc: BusinessHourException) {
        setEditingException(exc);
        setExceptionForm({
            exception_date: exc.exception_date,
            reason: exc.reason,
            is_open: exc.is_open,
            open_time: toFormTime(exc.open_time) || '10:00',
            close_time: toFormTime(exc.close_time) || '18:00',
        });
        setExceptionErrors({});
        setShowModal(true);
    }

    async function handleSubmitException() {
        setSubmittingException(true);
        setExceptionErrors({});
        const payload: BusinessHourExceptionFormData = {
            exception_date: exceptionForm.exception_date,
            reason: exceptionForm.reason,
            is_open: exceptionForm.is_open,
            open_time: exceptionForm.open_time,
            close_time: exceptionForm.close_time,
        };
        try {
            if (editingException) {
                await updateException(editingException.id, payload);
                showToast('success', 'Excepción actualizada.');
            } else {
                await createException(payload);
                showToast('success', 'Excepción creada.');
            }
            setShowModal(false);
            await fetchExceptions();
            await fetchStatus();
        } catch (err: unknown) {
            const apiErr = err as { errors?: Record<string, string[]>; message?: string };
            if (apiErr?.errors) {
                setExceptionErrors(apiErr.errors);
            } else {
                showToast('error', apiErr?.message ?? 'Error al guardar la excepción.');
            }
        } finally {
            setSubmittingException(false);
        }
    }

    async function handleDeleteException() {
        if (!confirmDelete) return;
        setDeleting(true);
        const id = confirmDelete.id;
        const [y, m, d] = confirmDelete.exception_date.split('-');
        const label = new Date(Number(y), Number(m) - 1, Number(d)).toLocaleDateString('es-CO', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
        setConfirmDelete(null);
        try {
            await deleteException(id);
            await fetchExceptions();
            await fetchStatus();
            showToast('success', `Excepción del ${label} eliminada.`);
        } catch (err: unknown) {
            const apiErr = err as { message?: string };
            showToast('error', apiErr?.message ?? 'No se pudo eliminar la excepción.');
        } finally {
            setDeleting(false);
        }
    }

    function refreshAll() {
        fetchHours();
        fetchExceptions();
        fetchStatus();
    }

    const headerActions = (
        <>
            <Button
                variant="outline"
                size="sm"
                onClick={() => void refreshAll()}
                disabled={loading}
                title="Actualizar horarios, excepciones y estado"
            >
                <RefreshCw className={`mr-1.5 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                Actualizar
            </Button>
            {!readOnly && (
                <Button size="sm" onClick={() => openCreateException()}>
                    <Plus className="mr-1.5 h-4 w-4" />
                    Nueva excepción
                </Button>
            )}
        </>
    );

    return (
        <>
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading && hours.length === 0 ? (
                    <HoursSkeleton />
                ) : (
                    <>
                        <PageHeader
                            eyebrow="HORARIOS"
                            title="Horarios de operación"
                            description="Configura horarios semanales y excepciones para bot, carrito y menú público."
                            actions={headerActions}
                        />

                        <OpenStatusBadge status={status} loading={loading} />

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        <MenuPriorityBanner />

                        <div className="grid gap-6 xl:grid-cols-2">
                            <WeeklyScheduleEditor
                                schedule={schedule}
                                loading={loading}
                                saving={saving}
                                readOnly={readOnly}
                                onUpdateDay={updateDay}
                                onSave={handleSaveSchedule}
                            />

                            <ExceptionsCalendar
                                exceptions={exceptions}
                                readOnly={readOnly}
                                onAdd={openCreateException}
                                onEdit={openEditException}
                                onDelete={setConfirmDelete}
                            />
                        </div>
                    </>
                )}
            </div>

            {showModal && (
                <ExceptionModal
                    editing={editingException}
                    form={exceptionForm}
                    onChange={(patch) => setExceptionForm((prev) => ({ ...prev, ...patch }))}
                    onSubmit={handleSubmitException}
                    onCancel={() => setShowModal(false)}
                    submitting={submittingException}
                    errors={exceptionErrors}
                />
            )}

            <ConfirmDialog
                open={confirmDelete !== null}
                title="Eliminar excepción"
                message={`¿Eliminar la excepción del ${
                    confirmDelete
                        ? (() => {
                              const [y, m, d] = confirmDelete.exception_date.split('-');
                              return new Date(Number(y), Number(m) - 1, Number(d)).toLocaleDateString('es-CO', {
                                  day: 'numeric',
                                  month: 'long',
                                  year: 'numeric',
                              });
                          })()
                        : ''
                }? Esta acción no se puede deshacer.`}
                confirmLabel="Eliminar"
                loading={deleting}
                onConfirm={handleDeleteException}
                onCancel={() => setConfirmDelete(null)}
            />
        </>
    );
}
