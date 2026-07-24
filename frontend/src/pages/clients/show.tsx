import { AppLink } from '@/components/app-link';
import { LoyaltyPanel } from '@/components/clients/loyalty-panel';
import { NewClientDialog } from '@/components/clients/new-client-dialog';
import { NotesPanel } from '@/components/clients/notes-panel';
import { SegmentBadge } from '@/components/clients/segment-badge';
import { TagsEditor } from '@/components/clients/tags-editor';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ClientDetailSkeleton } from '@/components/ui/client-detail-skeleton';
import { KpiCell } from '@/components/ui/kpi-cell';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useClient } from '@/hooks/use-client';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { useToken } from '@/hooks/use-token';
import { formatCurrency, formatDate } from '@/lib/coupon-helpers';
import { statusBadgeClass, statusLabel } from '@/lib/order-status';
import { useSharedData } from '@/lib/shared-data';
import { AlertCircle, ArrowLeft, MessageCircle, Pencil, Phone as PhoneIcon } from 'lucide-react';
import { useState } from 'react';

function formatPhoneDisplay(phone: string | null): string {
    if (!phone) return 'Sin teléfono';
    if (phone.startsWith('57') && phone.length === 12) {
        return `+57 ${phone.slice(2, 5)} ${phone.slice(5, 8)} ${phone.slice(8)}`;
    }
    return phone;
}

export default function ClientShow() {
    // Route param es contacts.id (UUID string). Si llega vacío, useClient lo
    // trata como null y el hook no dispara fetch.
    const rawId = window.location.pathname.split('/').pop() ?? '';
    const contactId = rawId !== '' ? rawId : null;
    const { permissions = [] } = useSharedData();
    const token = useToken();
    const canEdit = permissions.includes('clients.update');
    const canDelete = permissions.includes('clients.delete');
    const canEditLoyalty = permissions.includes('loyalty.update');
    const canViewLoyalty = permissions.includes('loyalty.read');
    const orderStatuses = useOrderStatuses();

    const { profile, loading, error, refresh, addNote, deleteNote, addTag, deleteTag } = useClient(token, contactId);
    const [editOpen, setEditOpen] = useState(false);

    return (
        <PageShell title={profile?.name || formatPhoneDisplay(profile?.phone ?? null)}>
            <div className="flex flex-col gap-6 p-4 lg:p-6">
                <div className="flex items-center gap-2">
                    <AppLink href="/clients">
                        <Button variant="ghost" size="sm">
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Contactos
                        </Button>
                    </AppLink>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading && !profile && <ClientDetailSkeleton />}

                {profile && (
                    <>
                        <Card>
                            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2">
                                <div>
                                    <CardTitle className="text-foreground text-2xl font-semibold tracking-tight">
                                        {profile.name || profile.legal_name || 'Contacto sin nombre'}
                                    </CardTitle>
                                    <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                        {profile.doc_number && (
                                            <span>
                                                <span className="font-medium">{profile.doc_type ?? 'DOC'}</span>{' '}
                                                <span className="font-mono">{profile.doc_number}</span>
                                                {profile.dv && <span className="font-mono">-{profile.dv}</span>}
                                            </span>
                                        )}
                                        <span className="flex items-center gap-1">
                                            <PhoneIcon className="h-3.5 w-3.5" />
                                            <span className="font-mono">{formatPhoneDisplay(profile.phone)}</span>
                                        </span>
                                        <SegmentBadge segment={profile.segment} />
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {canEdit && (
                                        <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
                                            <Pencil className="mr-1 h-4 w-4" />
                                            Editar
                                        </Button>
                                    )}
                                    {profile.chats.length > 0 && (
                                        <AppLink href={`/chats?chat=${profile.chats[0].id}`}>
                                            <Button variant="outline" size="sm">
                                                <MessageCircle className="mr-1 h-4 w-4" />
                                                Ver chat
                                            </Button>
                                        </AppLink>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                                    <KpiCell label="Órdenes totales" value={String(profile.kpis.total_orders)} />
                                    <KpiCell label="Total gastado" value={formatCurrency(profile.kpis.total_spent)} />
                                    <KpiCell label="Ticket promedio" value={formatCurrency(profile.kpis.average_ticket)} />
                                    <KpiCell label="Última orden" value={formatDate(profile.kpis.last_order_at)} />
                                    <KpiCell label="Primera orden" value={formatDate(profile.kpis.first_order_at)} />
                                    <KpiCell label="Órdenes (60d)" value={String(profile.kpis.orders_last_60d)} />
                                    <KpiCell label="Gasto (90d)" value={formatCurrency(profile.kpis.spent_last_90d)} />
                                    <KpiCell label="Cancelaciones" value={`${Math.round(profile.kpis.cancellation_rate * 100)}%`} />
                                </div>

                                <div className="mt-4">
                                    <h3 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">Etiquetas</h3>
                                    <TagsEditor tags={profile.tags} canEdit={canEdit} canDelete={canDelete} onAdd={addTag} onDelete={deleteTag} />
                                </div>
                            </CardContent>
                        </Card>

                        {canViewLoyalty && profile.phone && <LoyaltyPanel token={token} phone={profile.phone} canEdit={canEditLoyalty} />}

                        <Tabs defaultValue="orders" className="w-full">
                            <TabsList className="flex h-auto w-full flex-wrap justify-start gap-1 sm:w-auto">
                                <TabsTrigger value="orders">Órdenes ({profile.orders.length})</TabsTrigger>
                                <TabsTrigger value="chats">Chats ({profile.chats.length})</TabsTrigger>
                                <TabsTrigger value="notes">Notas ({profile.notes.length})</TabsTrigger>
                            </TabsList>

                            <TabsContent value="orders" className="mt-4">
                                {profile.orders.length === 0 ? (
                                    <p className="text-muted-foreground text-sm italic">Sin órdenes registradas.</p>
                                ) : (
                                    <ul className="bg-card divide-y rounded-lg border shadow-sm">
                                        {profile.orders.map((order) => (
                                            <li
                                                key={order.id}
                                                className="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="bg-muted rounded px-1.5 py-0.5 font-mono text-xs">#{order.id}</span>
                                                    <span
                                                        className={`rounded px-1.5 py-0.5 text-xs ${statusBadgeClass(orderStatuses, order.status)}`}
                                                    >
                                                        {statusLabel(orderStatuses, order.status)}
                                                    </span>
                                                    <span className="text-muted-foreground text-xs">
                                                        {order.ordered_at ? formatDate(order.ordered_at) : '—'}
                                                    </span>
                                                    <span className="text-muted-foreground text-xs">
                                                        · {order.items_count} ítem{order.items_count === 1 ? '' : 's'}
                                                    </span>
                                                </div>
                                                <span className="font-medium tabular-nums sm:ml-auto">{formatCurrency(order.total)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </TabsContent>

                            <TabsContent value="chats" className="mt-4">
                                {profile.chats.length === 0 ? (
                                    <p className="text-muted-foreground text-sm italic">Sin conversaciones registradas.</p>
                                ) : (
                                    <ul className="bg-card divide-y rounded-lg border shadow-sm">
                                        {profile.chats.map((chat) => (
                                            <li
                                                key={chat.id}
                                                className="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="bg-muted rounded px-1.5 py-0.5 font-mono text-xs">#{chat.id}</span>
                                                    <span className="text-muted-foreground text-xs capitalize">{chat.source}</span>
                                                    <span className="text-muted-foreground text-xs">
                                                        Último mensaje: {chat.last_message_at ? formatDate(chat.last_message_at) : '—'}
                                                    </span>
                                                </div>
                                                <AppLink href={`/chats?chat=${chat.id}`} className="text-primary text-xs hover:underline sm:ml-auto">
                                                    Abrir →
                                                </AppLink>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </TabsContent>

                            <TabsContent value="notes" className="mt-4">
                                <NotesPanel notes={profile.notes} canEdit={canEdit} canDelete={canDelete} onAdd={addNote} onDelete={deleteNote} />
                            </TabsContent>
                        </Tabs>

                        {canEdit && (
                            <NewClientDialog
                                open={editOpen}
                                onOpenChange={setEditOpen}
                                onSaved={() => void refresh()}
                                initialContact={{
                                    id: profile.id,
                                    kind: profile.kind,
                                    doc_type: profile.doc_type,
                                    doc_number: profile.doc_number,
                                    dv: profile.dv,
                                    phone: profile.phone,
                                    name: profile.name,
                                    legal_name: profile.legal_name,
                                    email: profile.email,
                                    notes: profile.contact_notes,
                                }}
                            />
                        )}
                    </>
                )}
            </div>
        </PageShell>
    );
}
