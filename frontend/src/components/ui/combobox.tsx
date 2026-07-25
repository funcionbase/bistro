import { cn } from '@/lib/utils';
import { Check, ChevronsUpDown, LoaderCircle, Plus, Search, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export interface ComboboxOption {
    value: string;
    label: string;
    disabled?: boolean;
}

interface BaseComboboxProps {
    options: ComboboxOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    loadingText?: string;
    disabled?: boolean;
    id?: string;
    className?: string;
    /** Contenido fijo al pie del panel (ej. acción "Crear X"). Al hacer click dentro, el panel se cierra. */
    footer?: React.ReactNode;
    /** Muestra una "X" para limpiar la selección. */
    clearable?: boolean;
    /**
     * Panel flotante (absolute z-50). Úsalo fuera de modales/dialogs.
     * Por defecto false: panel inline para no romper overflow de Radix Dialog.
     */
    floating?: boolean;
    /**
     * Modo servidor: si se pasa, el filtrado lo hace el padre. El componente
     * emite el query (debounced) y NO filtra localmente.
     */
    onSearchChange?: (query: string) => void;
    /** Debounce del `onSearchChange` (ms). Default 250. */
    searchDebounceMs?: number;
    /** Estado de carga (modo async): muestra spinner en la lista. */
    loading?: boolean;
    /** Paginación: se invoca al acercarse al final del scroll. */
    onReachEnd?: () => void;
    /** Free-text: crea una opción a partir del texto buscado (fila "Crear «…»"). */
    onCreateOption?: (label: string) => void;
    /** Etiqueta de la fila de creación. Default: `Crear «query»`. */
    createOptionLabel?: (query: string) => string;
    /** Nº de opciones a partir del cual se virtualiza la lista. Default 100. */
    virtualizeThreshold?: number;
    /**
     * Render custom por fila (ej. categoría/stock del insumo, íconos, texto
     * secundario). Recibe el estado `{ selected, active }`. Debe caber en una
     * fila (~36px) para no romper la virtualización.
     */
    renderOption?: (option: ComboboxOption, state: { selected: boolean; active: boolean }) => React.ReactNode;
    /** Control externo del panel (modo controlado). Si se omite, el componente lo maneja solo. */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

interface SingleComboboxProps extends BaseComboboxProps {
    multiple?: false;
    value: string;
    onChange: (value: string) => void;
}

interface MultiComboboxProps extends BaseComboboxProps {
    multiple: true;
    value: string[];
    onChange: (value: string[]) => void;
}

export type ComboboxProps = SingleComboboxProps | MultiComboboxProps;

const ITEM_HEIGHT = 36; // ponytail: 36px denso desktop; subir a 44 si móvil lo pide
const LIST_MAX_HEIGHT = 240;
const OVERSCAN = 6;

/** Normaliza para búsqueda insensible a mayúsculas y acentos. */
function normalize(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '');
}

/**
 * Selector con buscador (combobox) del Design System.
 *
 * Capacidades: selección única o múltiple (`multiple`), navegación por teclado
 * (↑/↓/Home/End/Enter/Escape), filtrado local o servidor (`onSearchChange`
 * debounced) con estado de carga, paginación por scroll (`onReachEnd`), limpiar
 * (`clearable`), creación de opción libre (`onCreateOption`) y virtualización
 * automática para listas grandes (`virtualizeThreshold`).
 *
 * Despliega el panel **en el flujo normal** del documento (no flotante): evita
 * el clipping del `overflow` de modales y el conflicto con el focus-trap de
 * Radix Dialog, y se ve correcto en mobile. Ubica el combobox en su propia fila
 * para que el panel tenga ancho completo.
 */
export function Combobox(props: ComboboxProps) {
    const {
        options,
        placeholder = 'Selecciona…',
        searchPlaceholder = 'Buscar…',
        emptyText = 'Sin resultados.',
        loadingText = 'Cargando…',
        disabled,
        id,
        className,
        footer,
        clearable,
        floating = false,
        onSearchChange,
        searchDebounceMs = 250,
        loading,
        onReachEnd,
        onCreateOption,
        createOptionLabel,
        virtualizeThreshold = 100,
        renderOption,
        onOpenChange,
    } = props;

    const multiple = props.multiple === true;
    const serverMode = typeof onSearchChange === 'function';

    const selectedValues = useMemo<string[]>(
        () => (multiple ? ((props.value as string[]) ?? []) : props.value ? [props.value as string] : []),
        [multiple, props.value],
    );
    const selectedSet = useMemo(() => new Set(selectedValues), [selectedValues]);

    // Panel controlado o no: si llega `open` por props, manda el padre.
    const [openState, setOpenState] = useState(false);
    const isOpenControlled = props.open !== undefined;
    const open = isOpenControlled ? !!props.open : openState;
    const setOpen = useCallback(
        (next: boolean | ((prev: boolean) => boolean)) => {
            const resolved = typeof next === 'function' ? (next as (p: boolean) => boolean)(open) : next;
            if (!isOpenControlled) {
                setOpenState(resolved);
            }
            onOpenChange?.(resolved);
        },
        [isOpenControlled, open, onOpenChange],
    );
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const [scrollTop, setScrollTop] = useState(0);

    const rootRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLUListElement>(null);
    // Guarda para que `onReachEnd` se dispare una sola vez por llegada al final
    // (no en cada evento de scroll mientras se está en la zona).
    const reachedEndRef = useRef(false);

    // Cache value→label: en modo async la opción seleccionada puede no estar en
    // el `options` actual (filtrado), pero igual queremos mostrar su etiqueta.
    const [labelCache, setLabelCache] = useState<Record<string, string>>({});
    useEffect(() => {
        setLabelCache((prev) => {
            const next = { ...prev };
            for (const o of options) {
                next[o.value] = o.label;
            }
            return next;
        });
    }, [options]);
    const labelOf = useCallback((v: string) => labelCache[v] ?? options.find((o) => o.value === v)?.label ?? v, [labelCache, options]);

    // Debounce del query en modo servidor.
    useEffect(() => {
        if (!serverMode) {
            return;
        }
        const t = setTimeout(() => onSearchChange?.(query), searchDebounceMs);
        return () => clearTimeout(t);
    }, [query, serverMode, onSearchChange, searchDebounceMs]);

    const filtered = useMemo(() => {
        if (serverMode) {
            return options;
        }
        const q = normalize(query.trim());
        if (!q) {
            return options;
        }
        return options.filter((o) => normalize(o.label).includes(q));
    }, [options, query, serverMode]);

    const trimmed = query.trim();
    const showCreate = !!onCreateOption && trimmed.length > 0 && !filtered.some((o) => normalize(o.label) === normalize(trimmed));
    const createOffset = showCreate ? 1 : 0;
    const totalRows = createOffset + filtered.length;

    // Al abrir: limpia query, resetea navegación y enfoca el buscador.
    useEffect(() => {
        if (!open) {
            return;
        }
        setQuery('');
        // En single, abre posicionado sobre la opción ya seleccionada.
        const initialActive = !multiple && selectedValues.length > 0 ? Math.max(0, options.findIndex((o) => o.value === selectedValues[0])) : 0;
        setActiveIndex(initialActive);
        setScrollTop(0);
        const t = setTimeout(() => inputRef.current?.focus(), 0);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        setActiveIndex(0);
    }, [query]);

    // Cierra al hacer click fuera.
    useEffect(() => {
        if (!open) {
            return;
        }
        function onDocMouseDown(e: MouseEvent) {
            if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [open, setOpen]);

    function emit(next: string | string[]) {
        if (multiple) {
            (props as MultiComboboxProps).onChange(next as string[]);
        } else {
            (props as SingleComboboxProps).onChange(next as string);
        }
    }

    const selectValue = useCallback(
        (value: string) => {
            if (multiple) {
                emit(selectedSet.has(value) ? selectedValues.filter((v) => v !== value) : [...selectedValues, value]);
            } else {
                emit(value);
                setOpen(false);
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [multiple, selectedSet, selectedValues],
    );

    function clear(e: React.MouseEvent) {
        e.stopPropagation();
        emit(multiple ? [] : '');
    }

    function chooseRow(rowIndex: number) {
        if (showCreate && rowIndex === 0) {
            onCreateOption?.(trimmed);
            if (!multiple) {
                setOpen(false);
            }
            return;
        }
        const opt = filtered[rowIndex - createOffset];
        if (opt && !opt.disabled) {
            selectValue(opt.value);
        }
    }

    function onKeyDown(e: React.KeyboardEvent) {
        if (e.key === 'Escape') {
            setOpen(false);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex((i) => Math.min(i + 1, Math.max(totalRows - 1, 0)));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Home') {
            e.preventDefault();
            setActiveIndex(0);
        } else if (e.key === 'End') {
            e.preventDefault();
            setActiveIndex(Math.max(totalRows - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (totalRows > 0) {
                chooseRow(activeIndex);
            }
        }
    }

    // Mantiene visible la fila activa al navegar con teclado.
    useEffect(() => {
        if (!open || !listRef.current) {
            return;
        }
        const el = listRef.current;
        const top = activeIndex * ITEM_HEIGHT;
        const bottom = top + ITEM_HEIGHT;
        if (top < el.scrollTop) {
            el.scrollTop = top;
        } else if (bottom > el.scrollTop + el.clientHeight) {
            el.scrollTop = bottom - el.clientHeight;
        }
    }, [activeIndex, open]);

    // Resetea la guarda cuando cambia el catálogo (p.ej. llegó la página nueva),
    // para permitir el siguiente `onReachEnd` al seguir bajando.
    useEffect(() => {
        reachedEndRef.current = false;
    }, [options.length]);

    function onListScroll(e: React.UIEvent<HTMLUListElement>) {
        const el = e.currentTarget;
        setScrollTop(el.scrollTop);
        if (!onReachEnd) {
            return;
        }
        const nearEnd = el.scrollHeight - (el.scrollTop + el.clientHeight) < ITEM_HEIGHT * 3;
        if (nearEnd && !reachedEndRef.current) {
            reachedEndRef.current = true;
            onReachEnd();
        } else if (!nearEnd) {
            reachedEndRef.current = false;
        }
    }

    // Virtualización: solo renderiza la ventana visible de opciones.
    const virtualize = filtered.length > virtualizeThreshold;
    const optScroll = Math.max(0, scrollTop - createOffset * ITEM_HEIGHT);
    const startIndex = virtualize ? Math.max(0, Math.floor(optScroll / ITEM_HEIGHT) - OVERSCAN) : 0;
    const endIndex = virtualize ? Math.min(filtered.length, Math.ceil((optScroll + LIST_MAX_HEIGHT) / ITEM_HEIGHT) + OVERSCAN) : filtered.length;
    const topPad = startIndex * ITEM_HEIGHT;
    const bottomPad = (filtered.length - endIndex) * ITEM_HEIGHT;
    const visible = filtered.slice(startIndex, endIndex);

    const hasSelection = selectedValues.length > 0;
    const triggerLabel = multiple
        ? selectedValues.length === 0
            ? placeholder
            : selectedValues.length === 1
              ? labelOf(selectedValues[0])
              : `${selectedValues.length} seleccionados`
        : hasSelection
          ? labelOf(selectedValues[0])
          : placeholder;

    const showClear = !!clearable && hasSelection && !disabled;
    const rowId = (i: number) => (id ? `${id}-row-${i}` : undefined);

    return (
        <div ref={rootRef} className={cn('relative', className)}>
            <button
                type="button"
                id={id}
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                onClick={() => setOpen((o) => !o)}
                className={cn(
                    'border-input bg-background flex h-9 max-sm:min-h-11 w-full items-center rounded-md border py-1 pl-3 text-left text-sm shadow-sm',
                    showClear ? 'pr-14' : 'pr-9',
                    'focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-hidden',
                    'disabled:cursor-not-allowed disabled:opacity-60',
                )}
            >
                <span className={cn('truncate', !hasSelection && 'text-muted-foreground')}>{triggerLabel}</span>
            </button>
            {/* Chevron y "limpiar" van FUERA del <button> (no anidar interactivos). */}
            <ChevronsUpDown className="text-muted-foreground pointer-events-none absolute top-1/2 right-2.5 h-4 w-4 -translate-y-1/2" />
            {showClear && (
                <button
                    type="button"
                    tabIndex={-1}
                    aria-label="Limpiar selección"
                    onClick={clear}
                    className="text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute top-1/2 right-8 -translate-y-1/2 rounded-sm focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    <X className="h-4 w-4" />
                </button>
            )}

            {open && (
                <div
                    className={cn(
                        'border-input bg-popover text-popover-foreground mt-1 overflow-hidden rounded-md border shadow-md',
                        floating && 'absolute z-50 w-full',
                    )}
                    data-combobox-panel
                >
                    <div className="relative border-b">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                        {loading && <LoaderCircle className="text-muted-foreground absolute top-1/2 right-2.5 h-4 w-4 -translate-y-1/2 animate-spin" />}
                        <input
                            ref={inputRef}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder={searchPlaceholder}
                            role="combobox"
                            aria-expanded={open}
                            aria-controls={id ? `${id}-listbox` : undefined}
                            aria-activedescendant={rowId(activeIndex)}
                            className="h-9 w-full bg-transparent pr-8 pl-8 text-sm outline-hidden"
                        />
                    </div>
                    <ul
                        ref={listRef}
                        id={id ? `${id}-listbox` : undefined}
                        role="listbox"
                        aria-multiselectable={multiple || undefined}
                        onScroll={onListScroll}
                        style={{ maxHeight: LIST_MAX_HEIGHT }}
                        className="overflow-y-auto py-1"
                    >
                        {showCreate && (
                            <li>
                                <button
                                    type="button"
                                    id={rowId(0)}
                                    role="option"
                                    aria-selected={false}
                                    onClick={() => chooseRow(0)}
                                    onMouseEnter={() => setActiveIndex(0)}
                                    style={{ height: ITEM_HEIGHT }}
                                    className={cn(
                                        'flex w-full items-center gap-2 px-3 text-left text-sm',
                                        activeIndex === 0 ? 'bg-muted text-foreground' : 'hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    <Plus className="h-4 w-4 shrink-0" />
                                    <span className="truncate">{createOptionLabel ? createOptionLabel(trimmed) : `Crear «${trimmed}»`}</span>
                                </button>
                            </li>
                        )}

                        {loading && filtered.length === 0 ? (
                            <li className="text-muted-foreground px-3 py-2 text-sm">{loadingText}</li>
                        ) : filtered.length === 0 ? (
                            <li className="text-muted-foreground px-3 py-2 text-sm">{emptyText}</li>
                        ) : (
                            <>
                                {topPad > 0 && <li aria-hidden style={{ height: topPad }} />}
                                {visible.map((o, k) => {
                                    const rowIndex = createOffset + startIndex + k;
                                    const isSelected = selectedSet.has(o.value);
                                    const isActive = activeIndex === rowIndex;
                                    const isDisabled = !!o.disabled;
                                    return (
                                        <li key={o.value}>
                                            <button
                                                type="button"
                                                id={rowId(rowIndex)}
                                                role="option"
                                                aria-selected={isSelected}
                                                aria-disabled={isDisabled || undefined}
                                                disabled={isDisabled}
                                                onClick={() => chooseRow(rowIndex)}
                                                onMouseEnter={() => setActiveIndex(rowIndex)}
                                                style={{ height: ITEM_HEIGHT }}
                                                className={cn(
                                                    'flex w-full items-center justify-between gap-2 px-3 text-left text-sm',
                                                    isDisabled
                                                        ? 'cursor-not-allowed opacity-50'
                                                        : isActive
                                                          ? 'bg-muted text-foreground'
                                                          : 'hover:bg-muted hover:text-foreground',
                                                    isSelected && !isActive && !isDisabled && 'bg-muted/50',
                                                )}
                                            >
                                                {renderOption ? (
                                                    renderOption(o, { selected: isSelected, active: isActive })
                                                ) : (
                                                    <>
                                                        <span className="truncate">{o.label}</span>
                                                        {isSelected && <Check className="h-4 w-4 shrink-0" />}
                                                    </>
                                                )}
                                            </button>
                                        </li>
                                    );
                                })}
                                {bottomPad > 0 && <li aria-hidden style={{ height: bottomPad }} />}
                                {loading && <li className="text-muted-foreground px-3 py-2 text-center text-xs">{loadingText}</li>}
                            </>
                        )}
                    </ul>

                    {footer && (
                        <div className="border-t p-1" onClick={() => setOpen(false)}>
                            {footer}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
