import { AppLink } from '@/components/app-link';
import { MenuStatusBadge } from '@/components/menu/menu-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { useDateFormatter } from '@/hooks/use-date-formatter';
import type { RestaurantMenu } from '@/types';
import { BookOpen, CalendarDays, Copy, LoaderCircle, PowerOff, Trash2 } from 'lucide-react';

const DAY_LABELS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

interface MenuCardProps {
    menu: RestaurantMenu;
    onPublish?: (menu: RestaurantMenu) => void;
    onDeactivate?: (menu: RestaurantMenu) => void;
    onDuplicate?: (menu: RestaurantMenu) => void;
    onDelete?: (menu: RestaurantMenu) => void;
    onSchedule?: (menu: RestaurantMenu) => void;
    isDuplicating?: boolean;
    isPublishing?: boolean;
    isDeactivating?: boolean;
    isDeleting?: boolean;
}

export default function MenuCard({
    menu,
    onPublish,
    onDeactivate,
    onDuplicate,
    onDelete,
    onSchedule,
    isDuplicating = false,
    isPublishing = false,
    isDeactivating = false,
    isDeleting = false,
}: MenuCardProps) {
    const formatDate = useDateFormatter();
    const isDraft = menu.status === 'draft';
    const isScheduled = Array.isArray(menu.active_days) && menu.active_days.length > 0;
    const hasAnyAction = Boolean(onPublish || onDuplicate || onDelete || onSchedule);

    return (
        <Card className="flex flex-col overflow-hidden rounded-lg shadow-sm transition-shadow hover:shadow-md">
            <CardHeader className="pb-2">
                <div className="flex w-full min-w-0 items-start justify-between gap-2">
                    <div className="min-w-0 flex-1 overflow-hidden">
                        <p className="block w-full max-w-full overflow-hidden font-semibold text-ellipsis whitespace-nowrap" title={menu.name}>
                            {menu.name}
                        </p>
                        {menu.description && (
                            <p
                                className="text-muted-foreground mt-0.5 block w-full max-w-full overflow-hidden text-sm text-ellipsis whitespace-nowrap italic"
                                title={menu.description}
                            >
                                {menu.description}
                            </p>
                        )}
                    </div>
                    <MenuStatusBadge menu={menu} className="shrink-0 whitespace-nowrap" />
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                <p className="text-muted-foreground text-sm">
                    {menu.structure.categories.length} categoría{menu.structure.categories.length !== 1 ? 's' : ''}
                </p>

                {isScheduled && menu.active_days && (
                    <div className="flex flex-wrap gap-1">
                        {menu.active_days.map((day) => (
                            <span key={day} className="bg-primary/10 text-primary rounded-full px-2 py-0.5 text-xs font-medium">
                                {DAY_LABELS[day]}
                            </span>
                        ))}
                    </div>
                )}

                <p className="text-muted-foreground text-xs">Actualizado {formatDate(menu.updated_at)}</p>

                <div className="flex flex-wrap gap-2">
                    <AppLink href={`/menu/${menu.id}`}>
                        <Button size="sm" variant="outline" className="gap-1.5">
                            <BookOpen className="h-3.5 w-3.5" />
                            {hasAnyAction ? 'Ver / Editar' : 'Ver'}
                        </Button>
                    </AppLink>

                    {/* Publicar solo aparece para borradores. Un menu ya programado no
                        debe "publicarse" como permanente (perderia su programacion) —
                        se usa el boton "Programar" para ajustar dias. */}
                    {isDraft && onPublish && (
                        <Button size="sm" disabled={isPublishing} onClick={() => onPublish(menu)}>
                            {isPublishing ? <LoaderCircle className="h-3.5 w-3.5 animate-spin" /> : 'Publicar'}
                        </Button>
                    )}

                    {!isDraft && onSchedule && (
                        <Button size="sm" variant="outline" onClick={() => onSchedule(menu)} className="gap-1.5">
                            <CalendarDays className="h-3.5 w-3.5" />
                            Programar
                        </Button>
                    )}

                    {/* Desactivar: vuelve un menu activo/programado a borrador. Util
                        cuando se quiere dejar de servir esa carta en la sede sin
                        tener que activar otra inmediatamente. */}
                    {!isDraft && onDeactivate && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={isDeactivating}
                            onClick={() => onDeactivate(menu)}
                            className="gap-1.5 text-[color:var(--color-status-warning)] hover:text-[color:var(--color-status-warning)]"
                        >
                            {isDeactivating ? (
                                <LoaderCircle className="h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <>
                                    <PowerOff className="h-3.5 w-3.5" />
                                    Desactivar
                                </>
                            )}
                        </Button>
                    )}

                    {onDuplicate && (
                        <Button size="sm" variant="outline" disabled={isDuplicating} onClick={() => onDuplicate(menu)} className="gap-1.5">
                            {isDuplicating ? (
                                <LoaderCircle className="h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <>
                                    <Copy className="h-3.5 w-3.5" />
                                    Duplicar
                                </>
                            )}
                        </Button>
                    )}

                    {isDraft && onDelete && (
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={isDeleting}
                            onClick={() => onDelete(menu)}
                            className="text-destructive hover:text-destructive"
                        >
                            {isDeleting ? <LoaderCircle className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
