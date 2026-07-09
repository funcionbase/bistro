import { useGa4 } from '@/hooks/use-ga4';
import { SpaAppLayout } from '@/layouts/spa-app-layout';
import BranchSelectorRoute from '@/pages/auth/branch-selector';
import CompanySelectorRoute from '@/pages/auth/company-selector';
import { EnrollmentCompanyGuard } from '@/pages/enrollment/company-guard';
import ErrorBoundary from '@/pages/error-boundary';
import HoursRoute from '@/pages/hours';
import NotFound from '@/pages/not-found';
import { lazy } from 'react';
import { createBrowserRouter, Outlet, useParams, useSearchParams } from 'react-router-dom';

/**
 * Router del shell SPA standalone.
 *
 * - Route raíz pathless: solo aporta `errorElement` — captura cualquier error
 *   de runtime (chunk lazy caído tras un deploy, excepción en render) y lo
 *   muestra con la página de error del DS en vez del fallback feo de React
 *   Router.
 * - Rutas auth (sin layout): selectores de empresa/sede.
 * - Rutas autenticadas: anidadas bajo SpaAppLayout (sidebar + header).
 * - Rutas standalone: under-review y kds por estación (layout propio).
 * - Catch-all `*`: página 404 React (NotFound) — el backend solo sirve API.
 *
 * Las páginas se importan lazy desde `@/pages/**`; el code splitting da un
 * chunk por ruta.
 */

// Páginas autenticadas (bajo SpaAppLayout).
const Dashboard = lazy(() => import('@/pages/dashboard'));
const MeIndex = lazy(() => import('@/pages/me/index'));
const MePerfil = lazy(() => import('@/pages/me/perfil'));
const MeAgenda = lazy(() => import('@/pages/me/agenda'));
const LoyaltyReports = lazy(() => import('@/pages/loyalty/reports'));
const Billing = lazy(() => import('@/pages/billing/index'));
const ClientsIndex = lazy(() => import('@/pages/clients/index'));
const ClientsShow = lazy(() => import('@/pages/clients/show'));
const CouponsIndex = lazy(() => import('@/pages/coupons/index'));
const CouponsShow = lazy(() => import('@/pages/coupons/show'));
const MenuIndex = lazy(() => import('@/pages/menu/index'));
const MenuShow = lazy(() => import('@/pages/menu/show'));
const MetricsIndex = lazy(() => import('@/pages/metrics/index'));
const ReportsIndex = lazy(() => import('@/pages/reports/index'));
const Chats = lazy(() => import('@/pages/chats'));
const InventoryIndex = lazy(() => import('@/pages/inventory/index'));
const PurchasesIndex = lazy(() => import('@/pages/purchases/index'));
const DeliveriesIndex = lazy(() => import('@/pages/deliveries/index'));
const DeliveriesMetrics = lazy(() => import('@/pages/deliveries/metrics'));
const DeliveriesMine = lazy(() => import('@/pages/deliveries/mine'));
const EmployeesIndex = lazy(() => import('@/pages/employees/index'));
const EmployeesCreate = lazy(() => import('@/pages/employees/create'));
const EmployeesShow = lazy(() => import('@/pages/employees/show'));
const EmployeesReports = lazy(() => import('@/pages/employees/reports'));
const KdsIndex = lazy(() => import('@/pages/kds/index'));
const OrdersBoard = lazy(() => import('@/pages/orders/board'));
const OrdersTables = lazy(() => import('@/pages/orders/tables/index'));
const TableSessionsIndex = lazy(() => import('@/pages/orders/table-sessions/index'));
const TableSessionsShow = lazy(() => import('@/pages/orders/table-sessions/show'));
const OrderShow = lazy(() => import('@/pages/orders/show'));
const CajaIndex = lazy(() => import('@/pages/caja/index'));
const CajaTableSession = lazy(() => import('@/pages/caja/table-session'));
const Roles = lazy(() => import('@/pages/roles/roles'));
const Users = lazy(() => import('@/pages/users/users'));
const CompanyBranches = lazy(() => import('@/pages/company/branches/index'));
const CompanyKds = lazy(() => import('@/pages/company/kds'));
const CompanyPreferences = lazy(() => import('@/pages/company/preferences'));
const CompanyPrinters = lazy(() => import('@/pages/company/printers'));
const CompanySettings = lazy(() => import('@/pages/company/settings'));
const CompanyTables = lazy(() => import('@/pages/company/tables/index'));
const CompanyWarehouses = lazy(() => import('@/pages/company/warehouses/index'));
const CompanyWhatsapp = lazy(() => import('@/pages/company/whatsapp'));
const Planner = lazy(() => import('@/pages/planner/week'));

const DianConfig = lazy(() => import('@/pages/company/dian'));
const DianDocuments = lazy(() => import('@/pages/dian/documents'));
const SettingsAppearance = lazy(() => import('@/pages/settings/appearance'));
const SettingsNotifications = lazy(() => import('@/pages/settings/notifications'));
const SettingsProfile = lazy(() => import('@/pages/settings/profile'));
const SettingsPassword = lazy(() => import('@/pages/settings/password'));

// Auth email/password (scaffolding — el usuario lo habilita a futuro).
const Login = lazy(() => import('@/pages/auth/login'));
const Register = lazy(() => import('@/pages/auth/register'));
const ForgotPassword = lazy(() => import('@/pages/auth/forgot-password'));
const ResetPassword = lazy(() => import('@/pages/auth/reset-password'));
const ConfirmPassword = lazy(() => import('@/pages/auth/confirm-password'));
const VerifyEmail = lazy(() => import('@/pages/auth/verify-email'));

// Páginas con layout propio (fuera de SpaAppLayout).
const Welcome = lazy(() => import('@/pages/welcome'));
const CompanyUnderReview = lazy(() => import('@/pages/company/under-review'));
const KdsStation = lazy(() => import('@/pages/kds/station'));
const EnrollmentUser = lazy(() => import('@/pages/enrollment/user'));
const EnrollmentCompany = lazy(() => import('@/pages/enrollment/company'));

// Mesa con QR (#191) — flujo público sin auth. El QR físico apunta a
// `/t/{qrToken}`; cada página hidrata su contexto desde la API pública
// `/api/v1/public/table/{qrToken}` y opera con la cookie `tdt_*`.
const TableJoin = lazy(() => import('@/pages/table/join'));
const TableMenu = lazy(() => import('@/pages/table/menu'));

// Manual de usuario — páginas públicas sin auth ni layout SpaAppLayout.
// El contenido vive en markdown (`src/data/manual/*.md`) servido por la página
// genérica `ManualMarkdownPage`; index/faq/legal conservan JSX propio.
const ManualIndex = lazy(() => import('@/pages/manual/index'));
const ManualMarkdownPage = lazy(() => import('@/pages/manual/page'));
const ManualFaq = lazy(() => import('@/pages/manual/faq'));
const ManualLegalContrato = lazy(() => import('@/pages/manual/legal-contrato'));

// Carta pública (destino del QR impreso): `/menus/{nit}?table=N`. El QR
// codifica `/menus/{nit}`; la página persiste el NIT en localStorage y la
// mesa en sessionStorage, de modo que `/menus` (sin NIT) resuelve la última
// empresa escaneada al recargar. Pública, sin layout ni auth.
const PublicMenu = lazy(() => import('@/pages/menu/public'));

/**
 * Adapta `PublicMenu` (que recibe `nit`/`table` por props, herencia del flujo
 * Inertia anterior) al router SPA: lee `:nit` de la ruta y `?table=` del query
 * string. En `/menus` (sin `:nit`) pasa `null` → la página resuelve desde
 * localStorage.
 */
function PublicMenuRoute() {
    const { nit } = useParams();
    const [searchParams] = useSearchParams();
    return <PublicMenu nit={nit ?? null} table={searchParams.get('table')} branchToken={searchParams.get('branch')} />;
}

/**
 * Route raíz: además del `errorElement`, engancha GA4 para TODO el árbol —
 * incluidas las rutas públicas (landing, manual, menú QR, login) que quedaban
 * fuera cuando el hook vivía en `SpaSharedDataBridge` (solo panel autenticado)
 * y son justo las del posicionamiento SEO. El Measurement ID sale del fallback
 * build-time `VITE_GA4_ID` (mismo valor que el runtime de bootstrap en pdn).
 */
function RootRoute() {
    useGa4(null);
    return <Outlet />;
}

export const router = createBrowserRouter([
    {
        // Route raíz pathless: agrupa todas las rutas bajo un único
        // `errorElement` que captura los errores de runtime de cualquier hija.
        element: <RootRoute />,
        errorElement: <ErrorBoundary />,
        children: [
            { path: '/', element: <Welcome /> },
            { path: '/auth/company-selector', element: <CompanySelectorRoute /> },
            { path: '/auth/branch-selector', element: <BranchSelectorRoute /> },

            // Standalone (layout propio, sin sidebar global).
            { path: '/company/under-review', element: <CompanyUnderReview /> },
            { path: '/kds/:stationSlug', element: <KdsStation /> },
            { path: '/enrollment/user', element: <EnrollmentUser /> },
            {
                // El paso de empresa exige haber completado el paso personal
                // (quien registra la empresa queda como Propietario).
                path: '/enrollment/company',
                element: (
                    <EnrollmentCompanyGuard>
                        <EnrollmentCompany />
                    </EnrollmentCompanyGuard>
                ),
            },

            // Mesa con QR (#191) — públicas, sin layout ni auth.
            { path: '/t/:qrToken', element: <TableJoin /> },
            { path: '/t/:qrToken/menu', element: <TableMenu /> },

            // Carta pública (QR impreso) — pública, sin layout ni auth.
            { path: '/menus', element: <PublicMenuRoute /> },
            { path: '/menus/:nit', element: <PublicMenuRoute /> },

            // Manual de usuario — públicas, sin auth, layout propio. Las páginas
            // de contenido resuelven por :slug → markdown en src/data/manual/.
            { path: '/manual', element: <ManualIndex /> },
            { path: '/manual/faq', element: <ManualFaq /> },
            { path: '/manual/legal/contrato', element: <ManualLegalContrato /> },
            { path: '/manual/:slug', element: <ManualMarkdownPage /> },

            { path: '/login', element: <Login /> },
            { path: '/register', element: <Register /> },
            { path: '/forgot-password', element: <ForgotPassword /> },
            { path: '/reset-password/:token', element: <ResetPassword /> },
            { path: '/confirm-password', element: <ConfirmPassword /> },
            { path: '/verify-email', element: <VerifyEmail /> },

            // Autenticadas con sidebar.
            {
                element: <SpaAppLayout />,
                children: [
                    { path: '/dashboard', element: <Dashboard /> },
                    { path: '/hours', element: <HoursRoute /> },
                    { path: '/me', element: <MeIndex /> },
                    { path: '/me/profile', element: <MePerfil /> },
                    { path: '/me/schedule', element: <MeAgenda /> },
                    { path: '/loyalty/reports', element: <LoyaltyReports /> },
                    { path: '/billing', element: <Billing /> },
                    { path: '/clients', element: <ClientsIndex /> },
                    { path: '/clients/:phone', element: <ClientsShow /> },
                    { path: '/coupons', element: <CouponsIndex /> },
                    { path: '/coupons/:id', element: <CouponsShow /> },
                    { path: '/menu', element: <MenuIndex /> },
                    { path: '/menu/:id', element: <MenuShow /> },
                    { path: '/company/metrics', element: <MetricsIndex /> },
                    { path: '/company/reports', element: <ReportsIndex /> },
                    { path: '/chats', element: <Chats /> },
                    { path: '/inventory', element: <InventoryIndex /> },
                    { path: '/purchases', element: <PurchasesIndex /> },
                    { path: '/orders/deliveries', element: <DeliveriesIndex /> },
                    { path: '/deliveries/metrics', element: <DeliveriesMetrics /> },
                    { path: '/my-deliveries', element: <DeliveriesMine /> },
                    { path: '/employees', element: <EmployeesIndex /> },
                    { path: '/employees/new', element: <EmployeesCreate /> },
                    { path: '/employees/reports', element: <EmployeesReports /> },
                    { path: '/employees/:id', element: <EmployeesShow /> },
                    { path: '/kds', element: <KdsIndex /> },
                    { path: '/orders/board', element: <OrdersBoard /> },
                    { path: '/orders/tables', element: <OrdersTables /> },
                    { path: '/orders/table-sessions', element: <TableSessionsIndex /> },
                    { path: '/orders/table-sessions/:id', element: <TableSessionsShow /> },
                    { path: '/orders/:id', element: <OrderShow /> },
                    { path: '/orders/cashier', element: <CajaIndex /> },
                    { path: '/cashier/table-sessions/:id', element: <CajaTableSession /> },
                    { path: '/identities/roles', element: <Roles /> },
                    { path: '/identities/users', element: <Users /> },
                    { path: '/company/branches', element: <CompanyBranches /> },
                    { path: '/company/kds', element: <CompanyKds /> },
                    { path: '/company/preferences', element: <CompanyPreferences /> },
                    { path: '/company/printers', element: <CompanyPrinters /> },
                    { path: '/company/settings', element: <CompanySettings /> },
                    { path: '/company/tables', element: <CompanyTables /> },
                    { path: '/company/warehouses', element: <CompanyWarehouses /> },
                    { path: '/company/whatsapp', element: <CompanyWhatsapp /> },
                    { path: '/company/dian', element: <DianConfig /> },
                    { path: '/dian/documents', element: <DianDocuments /> },
                    { path: '/planner', element: <Planner /> },
                    { path: '/settings/appearance', element: <SettingsAppearance /> },
                    { path: '/settings/notifications', element: <SettingsNotifications /> },
                    { path: '/settings/profile', element: <SettingsProfile /> },
                    { path: '/settings/password', element: <SettingsPassword /> },
                ],
            },

            { path: '*', element: <NotFound /> },
        ],
    },
]);
