import { AppLink } from '@/components/app-link';
import CategoryFormModal from '@/components/menu/category-form-modal';
import ItemFormModal from '@/components/menu/item-form-modal';
import MenuManager from '@/components/menu/menu-manager';
import MenuPreview from '@/components/menu/menu-preview';
import PublishModal from '@/components/menu/publish-modal';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { MenuDetailSkeleton } from '@/components/ui/menu-detail-skeleton';
import { useToast } from '@/components/ui/toast';
import { usePermissions } from '@/hooks/use-permissions';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import type { MenuCategory, MenuItem, RestaurantMenu } from '@/types';
import { AlertCircle, ArrowLeft, Lock } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';

export default function MenuShow() {
    const id = window.location.pathname.split('/').pop() ?? '';
    const navigate = useNavigate();
    const activeToken = useToken();
    const { showToast } = useToast();
    const { has } = usePermissions();
    const canCreate = has('menu.create');
    const canUpdate = has('menu.update');
    const canDelete = has('menu.delete');
    const [menu, setMenu] = useState<RestaurantMenu | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const [showCategoryModal, setShowCategoryModal] = useState(false);
    const [editingCategory, setEditingCategory] = useState<MenuCategory | null>(null);

    const [showItemModal, setShowItemModal] = useState(false);
    const [editingItem, setEditingItem] = useState<MenuItem | null>(null);
    const [editingItemCategoryId, setEditingItemCategoryId] = useState<string | null>(null);

    const [showPreview, setShowPreview] = useState(false);
    const [showPublishModal, setShowPublishModal] = useState(false);
    const [isPublishing, setIsPublishing] = useState(false);

    const [categoryToDelete, setCategoryToDelete] = useState<MenuCategory | null>(null);
    const [deletingCategory, setDeletingCategory] = useState(false);
    const [itemToDelete, setItemToDelete] = useState<{ category: MenuCategory; item: MenuItem } | null>(null);
    const [deletingItem, setDeletingItem] = useState(false);

    const isMounted = useRef(true);

    const fetchMenu = useCallback(async () => {
        if (!activeToken) return;
        try {
            const res = await apiFetch(`/api/v1/menus/${id}`);
            const data = await res.json();
            if (!isMounted.current) return;
            if (!res.ok) {
                setError(data.message ?? 'Error al cargar el menú.');
                return;
            }
            setMenu(data.data);
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [activeToken, id]);

    useEffect(() => {
        isMounted.current = true;
        fetchMenu();
        return () => {
            isMounted.current = false;
        };
    }, [fetchMenu]);

    function handleDeleteCategory(categoryId: string) {
        const category = menu?.structure.categories.find((c) => c.id === categoryId);
        if (category) setCategoryToDelete(category);
    }

    async function confirmDeleteCategory() {
        if (!categoryToDelete) return;
        const target = categoryToDelete;
        setDeletingCategory(true);
        try {
            const res = await apiFetch(`/api/v1/menus/${id}/categories/${target.id}`, { method: 'DELETE' });
            if (res.ok) {
                setMenu((prev) => {
                    if (!prev) return prev;
                    const categories = prev.structure.categories.filter((c) => c.id !== target.id);
                    return { ...prev, structure: { ...prev.structure, categories } };
                });
                setCategoryToDelete(null);
            } else {
                const data = await res.json().catch(() => ({}));
                showToast('error', data.message || 'No se pudo eliminar la categoría.');
            }
        } catch {
            showToast('error', 'Error de conexión al eliminar.');
        } finally {
            setDeletingCategory(false);
        }
    }

    function handleDeleteItem(categoryId: string, itemId: string) {
        const category = menu?.structure.categories.find((c) => c.id === categoryId);
        const item = category?.items.find((i) => i.id === itemId);
        if (category && item) setItemToDelete({ category, item });
    }

    async function confirmDeleteItem() {
        if (!itemToDelete) return;
        const { category, item } = itemToDelete;
        setDeletingItem(true);
        try {
            const res = await apiFetch(`/api/v1/menus/${id}/categories/${category.id}/items/${item.id}`, { method: 'DELETE' });
            if (res.ok) {
                setMenu((prev) => {
                    if (!prev) return prev;
                    const categories = prev.structure.categories.map((cat) => {
                        if (cat.id !== category.id) return cat;
                        return {
                            ...cat,
                            items: cat.items.filter((i) => i.id !== item.id),
                        };
                    });
                    return { ...prev, structure: { ...prev.structure, categories } };
                });
                setItemToDelete(null);
            } else {
                const data = await res.json().catch(() => ({}));
                showToast('error', data.message || 'No se pudo eliminar el ítem.');
            }
        } catch {
            showToast('error', 'Error de conexión al eliminar.');
        } finally {
            setDeletingItem(false);
        }
    }

    function handleCategorySaved(category: MenuCategory) {
        setMenu((prev) => {
            if (!prev) return prev;
            const exists = prev.structure.categories.find((c) => c.id === category.id);
            const categories = exists
                ? prev.structure.categories.map((c) => (c.id === category.id ? { ...c, ...category } : c))
                : [...prev.structure.categories, category];
            return { ...prev, structure: { ...prev.structure, categories } };
        });
        setShowCategoryModal(false);
        setEditingCategory(null);
    }

    function handleItemSaved(item: MenuItem) {
        if (!editingItemCategoryId) return;
        setMenu((prev) => {
            if (!prev) return prev;
            const categories = prev.structure.categories.map((cat) => {
                if (cat.id !== editingItemCategoryId) return cat;
                const exists = cat.items.find((i) => i.id === item.id);
                const items = exists ? cat.items.map((i) => (i.id === item.id ? item : i)) : [...cat.items, item];
                return { ...cat, items };
            });
            return { ...prev, structure: { ...prev.structure, categories } };
        });
        setShowItemModal(false);
        setEditingItem(null);
        setEditingItemCategoryId(null);
    }

    function handleAvailabilityToggle(categoryId: string, itemId: string, available: boolean) {
        setMenu((prev) => {
            if (!prev) return prev;
            const categories = prev.structure.categories.map((cat) => {
                if (cat.id !== categoryId) return cat;
                return {
                    ...cat,
                    items: cat.items.map((item) => (item.id === itemId ? { ...item, available } : item)),
                };
            });
            return { ...prev, structure: { ...prev.structure, categories } };
        });
    }

    async function handlePublish() {
        if (!menu) return;
        setIsPublishing(true);
        try {
            const res = await apiFetch(`/api/v1/menus/${menu.id}/activate`, {
                method: 'PATCH',
            });
            if (res.ok) {
                setShowPublishModal(false);
                navigate('/menu');
            } else {
                const data = await res.json();
                showToast('error', data.message || 'No se pudo publicar el menú.');
            }
        } catch {
            showToast('error', 'Error de conexión al publicar.');
        } finally {
            setIsPublishing(false);
        }
    }

    return (
        <PageShell title={menu?.name ?? 'Menú'}>
            <div className="flex h-full flex-col">
                {loading ? (
                    <MenuDetailSkeleton />
                ) : (
                    <>
                        <div className="flex items-center gap-3 border-b px-4 py-4 sm:px-6">
                            <AppLink href="/menu">
                                <Button variant="ghost" size="icon" className="h-9 w-9 shrink-0">
                                    <ArrowLeft className="h-4 w-4" />
                                </Button>
                            </AppLink>
                            <div className="min-w-0 flex-1">
                                <h1 className="text-foreground truncate text-lg font-semibold tracking-tight md:text-xl">{menu?.name}</h1>
                                {menu?.description && <p className="text-muted-foreground truncate text-sm">{menu.description}</p>}
                            </div>
                        </div>

                        {error ? (
                            <div className="m-4 sm:m-6">
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            </div>
                        ) : menu ? (
                            <div className="flex-1 overflow-auto p-4 sm:p-6">
                                {!canUpdate && (
                                    <Alert variant="warning" className="mb-4">
                                        <Lock className="h-4 w-4" />
                                        <AlertDescription>Vista de solo lectura: tu rol no permite editar este menú.</AlertDescription>
                                    </Alert>
                                )}
                                <MenuManager
                                    menu={menu}
                                    onCategoryDeleted={handleDeleteCategory}
                                    onItemDeleted={handleDeleteItem}
                                    onItemAvailabilityToggle={handleAvailabilityToggle}
                                    onPublish={() => setShowPublishModal(true)}
                                    onPreview={() => setShowPreview(true)}
                                    onAddCategory={() => {
                                        setEditingCategory(null);
                                        setShowCategoryModal(true);
                                    }}
                                    onEditCategory={(category) => {
                                        setEditingCategory(category);
                                        setShowCategoryModal(true);
                                    }}
                                    onAddItem={(categoryId) => {
                                        setEditingItem(null);
                                        setEditingItemCategoryId(categoryId);
                                        setShowItemModal(true);
                                    }}
                                    onEditItem={(categoryId, item) => {
                                        setEditingItem(item);
                                        setEditingItemCategoryId(categoryId);
                                        setShowItemModal(true);
                                    }}
                                    canCreate={canCreate}
                                    canUpdate={canUpdate}
                                    canDelete={canDelete}
                                />
                            </div>
                        ) : null}
                    </>
                )}
            </div>

            {showCategoryModal && (
                <CategoryFormModal
                    menuId={id}
                    category={editingCategory}
                    onClose={() => {
                        setShowCategoryModal(false);
                        setEditingCategory(null);
                    }}
                    onSaved={handleCategorySaved}
                />
            )}

            {showItemModal && editingItemCategoryId && (
                <ItemFormModal
                    menuId={id}
                    categoryId={editingItemCategoryId}
                    item={editingItem}
                    onClose={() => {
                        setShowItemModal(false);
                        setEditingItem(null);
                        setEditingItemCategoryId(null);
                    }}
                    onSaved={handleItemSaved}
                />
            )}

            {showPreview && menu && (
                <Dialog open={showPreview} onOpenChange={setShowPreview}>
                    <DialogContent className="max-h-[90vh] max-w-2xl overflow-auto">
                        <DialogHeader>
                            <DialogTitle>Vista previa del menú</DialogTitle>
                        </DialogHeader>
                        <MenuPreview menu={menu} />
                    </DialogContent>
                </Dialog>
            )}

            {menu && (
                <PublishModal
                    isOpen={showPublishModal}
                    menu={menu}
                    onConfirm={handlePublish}
                    onCancel={() => setShowPublishModal(false)}
                    isLoading={isPublishing}
                />
            )}

            <ConfirmDialog
                open={categoryToDelete !== null}
                title="¿Eliminar esta categoría?"
                message={
                    categoryToDelete
                        ? `Se eliminará "${categoryToDelete.name}" y todos sus ítems. Esta acción no se puede deshacer.`
                        : ''
                }
                confirmLabel="Eliminar"
                loading={deletingCategory}
                onConfirm={() => void confirmDeleteCategory()}
                onCancel={() => setCategoryToDelete(null)}
            />

            <ConfirmDialog
                open={itemToDelete !== null}
                title="¿Eliminar este ítem?"
                message={
                    itemToDelete
                        ? `Se eliminará "${itemToDelete.item.name}". Esta acción no se puede deshacer.`
                        : ''
                }
                confirmLabel="Eliminar"
                loading={deletingItem}
                onConfirm={() => void confirmDeleteItem()}
                onCancel={() => setItemToDelete(null)}
            />
        </PageShell>
    );
}
