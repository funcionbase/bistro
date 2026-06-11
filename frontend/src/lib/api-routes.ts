// THIS FILE IS AUTO-GENERATED — DO NOT EDIT BY HAND.
// Run `node scripts/generate-api-routes.mjs` after changing routes/api.php.
//
// Source: `php artisan route:list --json` filtered by name prefix `api.`.
// Total routes: 342.
//
// Used by the SPA (#220) to build API URLs without depending on Ziggy.

export const apiRoutes = {
        account: {
            /** POST /api/v1/account/delete (name: api.account.delete) */
            delete: () => `/api/v1/account/delete`,
            /** PUT /api/v1/account/password (name: api.account.password) */
            password: () => `/api/v1/account/password`,
            /** PATCH /api/v1/account/profile (name: api.account.profile) */
            profile: () => `/api/v1/account/profile`,
        },
        alertRules: {
            /** GET /api/v1/alert-rules (name: api.alert-rules.index) */
            index: () => `/api/v1/alert-rules`,
            /** PUT /api/v1/alert-rules/{type} (name: api.alert-rules.upsert) */
            upsert: (type: string | number) => `/api/v1/alert-rules/${type ?? ''}`,
        },
        alerts: {
            /** POST /api/v1/alerts/{id}/action (name: api.alerts.action) */
            action: (id: string | number) => `/api/v1/alerts/${id ?? ''}/action`,
            /** POST /api/v1/alerts/{id}/dismiss (name: api.alerts.dismiss) */
            dismiss: (id: string | number) => `/api/v1/alerts/${id ?? ''}/dismiss`,
            /** GET /api/v1/alerts (name: api.alerts.index) */
            index: () => `/api/v1/alerts`,
            /** GET /api/v1/alerts/summary (name: api.alerts.summary) */
            summary: () => `/api/v1/alerts/summary`,
        },
        auth: {
            /** GET /api/v1/auth/branches-available (name: api.auth.branches-available) */
            branchesAvailable: () => `/api/v1/auth/branches-available`,
            /** POST /api/v1/auth/logout (name: api.auth.logout) */
            logout: () => `/api/v1/auth/logout`,
            /** POST /api/v1/auth/select-company (name: api.auth.select-company) */
            selectCompany: () => `/api/v1/auth/select-company`,
            /** POST /api/v1/auth/switch-branch (name: api.auth.switch-branch) */
            switchBranch: () => `/api/v1/auth/switch-branch`,
            /** POST /api/v1/auth/switch-company (name: api.auth.switch-company) */
            switchCompany: () => `/api/v1/auth/switch-company`,
        },
        billing: {
            invoices: {
                /** GET /api/v1/billing/invoices/export.csv (name: api.billing.invoices.csv) */
                csv: () => `/api/v1/billing/invoices/export.csv`,
                /** GET /api/v1/billing/invoices/{id}/download (name: api.billing.invoices.download) */
                download: (id: string | number) => `/api/v1/billing/invoices/${id ?? ''}/download`,
                /** GET /api/v1/billing/invoices (name: api.billing.invoices.index) */
                index: () => `/api/v1/billing/invoices`,
                /** GET /api/v1/billing/invoices/{id}/pdf (name: api.billing.invoices.pdf) */
                pdf: (id: string | number) => `/api/v1/billing/invoices/${id ?? ''}/pdf`,
                /** GET /api/v1/billing/invoices/{id} (name: api.billing.invoices.show) */
                show: (id: string | number) => `/api/v1/billing/invoices/${id ?? ''}`,
            },
            paymentProofs: {
                /** GET /api/v1/billing/payment-proofs (name: api.billing.payment-proofs.index) */
                index: () => `/api/v1/billing/payment-proofs`,
                /** GET /api/v1/billing/payment-proofs/{proof} (name: api.billing.payment-proofs.show) */
                show: (proof: string | number) => `/api/v1/billing/payment-proofs/${proof ?? ''}`,
                /** POST /api/v1/billing/payment-proofs (name: api.billing.payment-proofs.store) */
                store: () => `/api/v1/billing/payment-proofs`,
            },
            /** GET /api/v1/billing/plans (name: api.billing.plans) */
            plans: () => `/api/v1/billing/plans`,
            /** GET /api/v1/billing/subscription (name: api.billing.subscription) */
            subscription: () => `/api/v1/billing/subscription`,
        },
        /** GET /api/v1/bootstrap (name: api.bootstrap) */
        bootstrap: () => `/api/v1/bootstrap`,
        caja: {
            tableSessions: {
                /** POST /api/v1/caja/table-sessions/{id}/pay-all (name: api.caja.table-sessions.pay-all) */
                payAll: (id: string | number) => `/api/v1/caja/table-sessions/${id ?? ''}/pay-all`,
                /** POST /api/v1/caja/table-sessions/{id}/pay-partial (name: api.caja.table-sessions.pay-partial) */
                payPartial: (id: string | number) => `/api/v1/caja/table-sessions/${id ?? ''}/pay-partial`,
                /** POST /api/v1/caja/table-sessions/{id}/refund-item (name: api.caja.table-sessions.refund-item) */
                refundItem: (id: string | number) => `/api/v1/caja/table-sessions/${id ?? ''}/refund-item`,
                /** GET /api/v1/caja/table-sessions/{id} (name: api.caja.table-sessions.show) */
                show: (id: string | number) => `/api/v1/caja/table-sessions/${id ?? ''}`,
            },
        },
        cancellationRequests: {
            /** POST /api/v1/cancellation-requests/{id}/resolve (name: api.cancellation-requests.resolve) */
            resolve: (id: string | number) => `/api/v1/cancellation-requests/${id ?? ''}/resolve`,
        },
        cart: {
            /** POST /api/v1/cart/active-auto-apply (name: api.cart.active-auto-apply) */
            activeAutoApply: () => `/api/v1/cart/active-auto-apply`,
            /** POST /api/v1/cart/apply-coupon (name: api.cart.apply-coupon) */
            applyCoupon: () => `/api/v1/cart/apply-coupon`,
            /** POST /api/v1/cart/migrate-jwt/{jwt} (name: api.cart.migrate-jwt) */
            migrateJwt: (jwt: string | number) => `/api/v1/cart/migrate-jwt/${jwt ?? ''}`,
            /** GET /api/v1/cart/{jwt} (name: api.cart.show) */
            show: (jwt: string | number) => `/api/v1/cart/${jwt ?? ''}`,
        },
        cashRegister: {
            /** POST /api/v1/cash-register/close (name: api.cashRegister.close) */
            close: () => `/api/v1/cash-register/close`,
            /** GET /api/v1/cash-register/current (name: api.cashRegister.current) */
            current: () => `/api/v1/cash-register/current`,
            expenses: {
                /** GET /api/v1/cash-register/sessions/{id}/expenses (name: api.cashRegister.expenses.index) */
                index: (id: string | number) => `/api/v1/cash-register/sessions/${id ?? ''}/expenses`,
                /** POST /api/v1/cash-register/expenses (name: api.cashRegister.expenses.store) */
                store: () => `/api/v1/cash-register/expenses`,
            },
            /** POST /api/v1/cash-register/open (name: api.cashRegister.open) */
            open: () => `/api/v1/cash-register/open`,
        },
        chats: {
            bot: {
                /** PATCH /api/v1/chats/{id}/bot (name: api.chats.bot.update) */
                update: (id: string | number) => `/api/v1/chats/${id ?? ''}/bot`,
            },
            client: {
                /** GET /api/v1/chats/{id}/client (name: api.chats.client.show) */
                show: (id: string | number) => `/api/v1/chats/${id ?? ''}/client`,
            },
            contact: {
                /** PATCH /api/v1/chats/{id}/contact (name: api.chats.contact.update) */
                update: (id: string | number) => `/api/v1/chats/${id ?? ''}/contact`,
            },
            /** GET /api/v1/chats (name: api.chats.index) */
            index: () => `/api/v1/chats`,
            /** POST /api/v1/chats/{id}/mark-read (name: api.chats.mark-read) */
            markRead: (id: string | number) => `/api/v1/chats/${id ?? ''}/mark-read`,
            messages: {
                /** POST /api/v1/chats/{id}/messages (name: api.chats.messages.store) */
                store: (id: string | number) => `/api/v1/chats/${id ?? ''}/messages`,
            },
            /** POST /api/v1/chats/{id}/reassign-branch (name: api.chats.reassign-branch) */
            reassignBranch: (id: string | number) => `/api/v1/chats/${id ?? ''}/reassign-branch`,
            /** GET /api/v1/chats/{id} (name: api.chats.show) */
            show: (id: string | number) => `/api/v1/chats/${id ?? ''}`,
        },
        clients: {
            /** GET /api/v1/clients (name: api.clients.index) */
            index: () => `/api/v1/clients`,
            notes: {
                /** DELETE /api/v1/clients/{contact}/notes/{id} (name: api.clients.notes.destroy) */
                destroy: (contact: string | number, id: string | number) => `/api/v1/clients/${contact ?? ''}/notes/${id ?? ''}`,
                /** POST /api/v1/clients/{contact}/notes (name: api.clients.notes.store) */
                store: (contact: string | number) => `/api/v1/clients/${contact ?? ''}/notes`,
            },
            /** GET /api/v1/clients/{contact} (name: api.clients.show) */
            show: (contact: string | number) => `/api/v1/clients/${contact ?? ''}`,
            /** POST /api/v1/clients (name: api.clients.store) */
            store: () => `/api/v1/clients`,
            tags: {
                /** DELETE /api/v1/clients/{contact}/tags/{id} (name: api.clients.tags.destroy) */
                destroy: (contact: string | number, id: string | number) => `/api/v1/clients/${contact ?? ''}/tags/${id ?? ''}`,
                /** POST /api/v1/clients/{contact}/tags (name: api.clients.tags.store) */
                store: (contact: string | number) => `/api/v1/clients/${contact ?? ''}/tags`,
            },
        },
        companies: {
            /** GET /api/v1/companies/active (name: api.companies.active) */
            active: () => `/api/v1/companies/active`,
            settings: {
                /** GET /api/v1/companies/settings (name: api.companies.settings.index) */
                index: () => `/api/v1/companies/settings`,
                /** GET /api/v1/companies/settings/{key} (name: api.companies.settings.show) */
                show: (key: string | number) => `/api/v1/companies/settings/${key ?? ''}`,
                /** PATCH /api/v1/companies/settings (name: api.companies.settings.update) */
                update: () => `/api/v1/companies/settings`,
            },
        },
        company: {
            branches: {
                /** DELETE /api/v1/company/branches/{branch} (name: api.company.branches.destroy) */
                destroy: (branch: string | number) => `/api/v1/company/branches/${branch ?? ''}`,
                /** GET /api/v1/company/branches (name: api.company.branches.index) */
                index: () => `/api/v1/company/branches`,
                menu: {
                    /** POST /api/v1/company/branches/{branch}/menu/copy (name: api.company.branches.menu.copy) */
                    copy: (branch: string | number) => `/api/v1/company/branches/${branch ?? ''}/menu/copy`,
                },
                /** POST /api/v1/company/branches (name: api.company.branches.store) */
                store: () => `/api/v1/company/branches`,
                /** PATCH /api/v1/company/branches/{branch} (name: api.company.branches.update) */
                update: (branch: string | number) => `/api/v1/company/branches/${branch ?? ''}`,
                users: {
                    /** POST /api/v1/company/branches/{branch}/users (name: api.company.branches.users.attach) */
                    attach: (branch: string | number) => `/api/v1/company/branches/${branch ?? ''}/users`,
                    /** POST /api/v1/company/branches/bulk-assign (name: api.company.branches.users.bulkAssign) */
                    bulkAssign: () => `/api/v1/company/branches/bulk-assign`,
                    /** DELETE /api/v1/company/branches/{branch}/users/{userId} (name: api.company.branches.users.detach) */
                    detach: (branch: string | number, userId: string | number) => `/api/v1/company/branches/${branch ?? ''}/users/${userId ?? ''}`,
                    /** GET /api/v1/company/branches/{branch}/users (name: api.company.branches.users.index) */
                    index: (branch: string | number) => `/api/v1/company/branches/${branch ?? ''}/users`,
                },
            },
            kds: {
                stations: {
                    /** POST /api/v1/company/kds/stations/{id}/archive (name: api.company.kds.stations.archive) */
                    archive: (id: string | number) => `/api/v1/company/kds/stations/${id ?? ''}/archive`,
                    /** POST /api/v1/company/kds/stations (name: api.company.kds.stations.store) */
                    store: () => `/api/v1/company/kds/stations`,
                    tokens: {
                        /** DELETE /api/v1/company/kds/stations/{stationId}/tokens/{tokenId} (name: api.company.kds.stations.tokens.destroy) */
                        destroy: (stationId: string | number, tokenId: string | number) => `/api/v1/company/kds/stations/${stationId ?? ''}/tokens/${tokenId ?? ''}`,
                        /** GET /api/v1/company/kds/stations/{stationId}/tokens (name: api.company.kds.stations.tokens.index) */
                        index: (stationId: string | number) => `/api/v1/company/kds/stations/${stationId ?? ''}/tokens`,
                        /** POST /api/v1/company/kds/stations/{stationId}/tokens (name: api.company.kds.stations.tokens.store) */
                        store: (stationId: string | number) => `/api/v1/company/kds/stations/${stationId ?? ''}/tokens`,
                    },
                    /** PATCH /api/v1/company/kds/stations/{id} (name: api.company.kds.stations.update) */
                    update: (id: string | number) => `/api/v1/company/kds/stations/${id ?? ''}`,
                },
            },
            printers: {
                /** DELETE /api/v1/company/printers/{id} (name: api.company.printers.destroy) */
                destroy: (id: string | number) => `/api/v1/company/printers/${id ?? ''}`,
                /** GET /api/v1/company/printers (name: api.company.printers.index) */
                index: () => `/api/v1/company/printers`,
                /** POST /api/v1/company/printers (name: api.company.printers.store) */
                store: () => `/api/v1/company/printers`,
                /** POST /api/v1/company/printers/{id}/test (name: api.company.printers.test) */
                test: (id: string | number) => `/api/v1/company/printers/${id ?? ''}/test`,
                /** PUT /api/v1/company/printers/{id} (name: api.company.printers.update) */
                update: (id: string | number) => `/api/v1/company/printers/${id ?? ''}`,
            },
            /** GET /api/v1/company (name: api.company.show) */
            show: () => `/api/v1/company`,
            /** PUT /api/v1/company (name: api.company.update) */
            update: () => `/api/v1/company`,
            warehouses: {
                /** DELETE /api/v1/company/warehouses/{warehouse} (name: api.company.warehouses.destroy) */
                destroy: (warehouse: string | number) => `/api/v1/company/warehouses/${warehouse ?? ''}`,
                /** GET /api/v1/company/warehouses (name: api.company.warehouses.index) */
                index: () => `/api/v1/company/warehouses`,
                /** POST /api/v1/company/warehouses (name: api.company.warehouses.store) */
                store: () => `/api/v1/company/warehouses`,
                /** PATCH /api/v1/company/warehouses/{warehouse} (name: api.company.warehouses.update) */
                update: (warehouse: string | number) => `/api/v1/company/warehouses/${warehouse ?? ''}`,
            },
        },
        coupons: {
            /** DELETE /api/v1/coupons/{id} (name: api.coupons.destroy) */
            destroy: (id: string | number) => `/api/v1/coupons/${id ?? ''}`,
            /** GET /api/v1/coupons (name: api.coupons.index) */
            index: () => `/api/v1/coupons`,
            redemptions: {
                /** GET /api/v1/coupons/{id}/redemptions (name: api.coupons.redemptions.index) */
                index: (id: string | number) => `/api/v1/coupons/${id ?? ''}/redemptions`,
            },
            /** GET /api/v1/coupons/{id} (name: api.coupons.show) */
            show: (id: string | number) => `/api/v1/coupons/${id ?? ''}`,
            /** PATCH /api/v1/coupons/{id}/status (name: api.coupons.status) */
            status: (id: string | number) => `/api/v1/coupons/${id ?? ''}/status`,
            /** POST /api/v1/coupons (name: api.coupons.store) */
            store: () => `/api/v1/coupons`,
            /** PUT /api/v1/coupons/{id} (name: api.coupons.update) */
            update: (id: string | number) => `/api/v1/coupons/${id ?? ''}`,
            /** GET /api/v1/coupons/{code}/validate (name: api.coupons.validate) */
            validate: (code: string | number) => `/api/v1/coupons/${code ?? ''}/validate`,
        },
        /** POST /api/v1/csp-report (name: api.csp-report) */
        cspReport: () => `/api/v1/csp-report`,
        /** GET /api/v1/dashboard (name: api.dashboard) */
        dashboard: () => `/api/v1/dashboard`,
        deliveries: {
            /** GET /api/v1/deliveries/available (name: api.deliveries.available) */
            available: () => `/api/v1/deliveries/available`,
            /** PATCH /api/v1/deliveries/{id}/complete (name: api.deliveries.complete) */
            complete: (id: string | number) => `/api/v1/deliveries/${id ?? ''}/complete`,
            /** GET /api/v1/deliveries/couriers (name: api.deliveries.couriers) */
            couriers: () => `/api/v1/deliveries/couriers`,
            /** DELETE /api/v1/deliveries/{id} (name: api.deliveries.destroy) */
            destroy: (id: string | number) => `/api/v1/deliveries/${id ?? ''}`,
            /** GET /api/v1/deliveries (name: api.deliveries.index) */
            index: () => `/api/v1/deliveries`,
            /** GET /api/v1/deliveries/metrics (name: api.deliveries.metrics) */
            metrics: () => `/api/v1/deliveries/metrics`,
            /** GET /api/v1/deliveries/mine (name: api.deliveries.mine) */
            mine: () => `/api/v1/deliveries/mine`,
            /** POST /api/v1/deliveries/{id}/reassign (name: api.deliveries.reassign) */
            reassign: (id: string | number) => `/api/v1/deliveries/${id ?? ''}/reassign`,
            /** GET /api/v1/deliveries/reassign-reasons (name: api.deliveries.reassign-reasons) */
            reassignReasons: () => `/api/v1/deliveries/reassign-reasons`,
            /** PUT /api/v1/deliveries/{id}/reject (name: api.deliveries.reject) */
            reject: (id: string | number) => `/api/v1/deliveries/${id ?? ''}/reject`,
            /** PUT /api/v1/deliveries/{id}/revert (name: api.deliveries.revert) */
            revert: (id: string | number) => `/api/v1/deliveries/${id ?? ''}/revert`,
            /** POST /api/v1/deliveries/orders/{orderId}/self-assign (name: api.deliveries.self-assign) */
            selfAssign: (orderId: string | number) => `/api/v1/deliveries/orders/${orderId ?? ''}/self-assign`,
            /** GET /api/v1/deliveries/{id} (name: api.deliveries.show) */
            show: (id: string | number) => `/api/v1/deliveries/${id ?? ''}`,
            /** POST /api/v1/deliveries (name: api.deliveries.store) */
            store: () => `/api/v1/deliveries`,
        },
        dian: {
            defaultRecipient: {
                /** DELETE /api/v1/dian/default-recipient (name: api.dian.defaultRecipient.destroy) */
                destroy: () => `/api/v1/dian/default-recipient`,
                /** GET /api/v1/dian/default-recipient (name: api.dian.defaultRecipient.show) */
                show: () => `/api/v1/dian/default-recipient`,
                /** PUT /api/v1/dian/default-recipient (name: api.dian.defaultRecipient.update) */
                update: () => `/api/v1/dian/default-recipient`,
            },
            documents: {
                /** POST /api/v1/dian/documents/{document}/convert-to-fev (name: api.dian.documents.convertToFev) */
                convertToFev: (document: string | number) => `/api/v1/dian/documents/${document ?? ''}/convert-to-fev`,
                /** POST /api/v1/dian/documents/{document}/credit-note (name: api.dian.documents.creditNote) */
                creditNote: (document: string | number) => `/api/v1/dian/documents/${document ?? ''}/credit-note`,
                /** GET /api/v1/dian/documents (name: api.dian.documents.index) */
                index: () => `/api/v1/dian/documents`,
                /** GET /api/v1/dian/documents/{document}/pdf (name: api.dian.documents.pdf) */
                pdf: (document: string | number) => `/api/v1/dian/documents/${document ?? ''}/pdf`,
                /** POST /api/v1/dian/documents/{document}/print (name: api.dian.documents.print) */
                print: (document: string | number) => `/api/v1/dian/documents/${document ?? ''}/print`,
                /** POST /api/v1/dian/documents/{document}/retry (name: api.dian.documents.retry) */
                retry: (document: string | number) => `/api/v1/dian/documents/${document ?? ''}/retry`,
                /** GET /api/v1/dian/documents/{document} (name: api.dian.documents.show) */
                show: (document: string | number) => `/api/v1/dian/documents/${document ?? ''}`,
                /** POST /api/v1/dian/documents (name: api.dian.documents.store) */
                store: () => `/api/v1/dian/documents`,
                /** GET /api/v1/dian/documents/{document}/xml (name: api.dian.documents.xml) */
                xml: (document: string | number) => `/api/v1/dian/documents/${document ?? ''}/xml`,
            },
            fiscalProfile: {
                /** GET /api/v1/dian/fiscal-profile (name: api.dian.fiscal-profile.show) */
                show: () => `/api/v1/dian/fiscal-profile`,
                /** PUT /api/v1/dian/fiscal-profile (name: api.dian.fiscal-profile.update) */
                update: () => `/api/v1/dian/fiscal-profile`,
            },
            providerConfig: {
                /** GET /api/v1/dian/provider-config (name: api.dian.providerConfig.show) */
                show: () => `/api/v1/dian/provider-config`,
                /** PUT /api/v1/dian/provider-config (name: api.dian.providerConfig.update) */
                update: () => `/api/v1/dian/provider-config`,
            },
            recipients: {
                /** GET /api/v1/dian/recipients/lookup (name: api.dian.recipients.lookup) */
                lookup: () => `/api/v1/dian/recipients/lookup`,
                /** PUT /api/v1/dian/recipients/{contact}/dian-profile (name: api.dian.recipients.update) */
                update: (contact: string | number) => `/api/v1/dian/recipients/${contact ?? ''}/dian-profile`,
            },
            resolutions: {
                /** DELETE /api/v1/dian/resolutions/{resolution} (name: api.dian.resolutions.destroy) */
                destroy: (resolution: string | number) => `/api/v1/dian/resolutions/${resolution ?? ''}`,
                /** GET /api/v1/dian/resolutions (name: api.dian.resolutions.index) */
                index: () => `/api/v1/dian/resolutions`,
                /** POST /api/v1/dian/resolutions (name: api.dian.resolutions.store) */
                store: () => `/api/v1/dian/resolutions`,
                /** PUT /api/v1/dian/resolutions/{resolution} (name: api.dian.resolutions.update) */
                update: (resolution: string | number) => `/api/v1/dian/resolutions/${resolution ?? ''}`,
            },
        },
        employeePositions: {
            /** DELETE /api/v1/employee-positions/{id} (name: api.employeePositions.destroy) */
            destroy: (id: string | number) => `/api/v1/employee-positions/${id ?? ''}`,
            /** GET /api/v1/employee-positions (name: api.employeePositions.index) */
            index: () => `/api/v1/employee-positions`,
            /** POST /api/v1/employee-positions (name: api.employeePositions.store) */
            store: () => `/api/v1/employee-positions`,
        },
        employees: {
            /** POST /api/v1/employees/{id}/archive (name: api.employees.archive) */
            archive: (id: string | number) => `/api/v1/employees/${id ?? ''}/archive`,
            /** GET /api/v1/employees (name: api.employees.index) */
            index: () => `/api/v1/employees`,
            /** GET /api/v1/employees/{id}/salary (name: api.employees.salary) */
            salary: (id: string | number) => `/api/v1/employees/${id ?? ''}/salary`,
            /** GET /api/v1/employees/{id} (name: api.employees.show) */
            show: (id: string | number) => `/api/v1/employees/${id ?? ''}`,
            /** POST /api/v1/employees (name: api.employees.store) */
            store: () => `/api/v1/employees`,
            /** PUT /api/v1/employees/{id} (name: api.employees.update) */
            update: (id: string | number) => `/api/v1/employees/${id ?? ''}`,
            /** POST /api/v1/employees/{id}/vinculation-state (name: api.employees.vinculationState) */
            vinculationState: (id: string | number) => `/api/v1/employees/${id ?? ''}/vinculation-state`,
        },
        enrollment: {
            /** POST /api/v1/enrollment/company (name: api.enrollment.company) */
            company: () => `/api/v1/enrollment/company`,
            /** POST /api/v1/enrollment/invited (name: api.enrollment.invited) */
            invited: () => `/api/v1/enrollment/invited`,
            proof: {
                /** GET /api/v1/enrollment/proof/preview (name: api.enrollment.proof.preview) */
                preview: () => `/api/v1/enrollment/proof/preview`,
            },
            /** POST /api/v1/enrollment/user (name: api.enrollment.user) */
            user: () => `/api/v1/enrollment/user`,
        },
        exports: {
            billing: {
                /** POST /api/v1/exports/billing/pdf (name: api.exports.billing.pdf) */
                pdf: () => `/api/v1/exports/billing/pdf`,
            },
            coupons: {
                /** POST /api/v1/exports/coupons/pdf (name: api.exports.coupons.pdf) */
                pdf: () => `/api/v1/exports/coupons/pdf`,
            },
            couriers: {
                /** POST /api/v1/exports/couriers/pdf (name: api.exports.couriers.pdf) */
                pdf: () => `/api/v1/exports/couriers/pdf`,
            },
            metrics: {
                /** POST /api/v1/exports/metrics/pdf (name: api.exports.metrics.pdf) */
                pdf: () => `/api/v1/exports/metrics/pdf`,
            },
            orders: {
                /** POST /api/v1/exports/orders/csv (name: api.exports.orders.csv) */
                csv: () => `/api/v1/exports/orders/csv`,
                /** POST /api/v1/exports/orders/pdf (name: api.exports.orders.pdf) */
                pdf: () => `/api/v1/exports/orders/pdf`,
            },
        },
        external: {
            chats: {
                /** POST /api/external/chats/handoff (name: api.external.chats.handoff) */
                handoff: () => `/api/external/chats/handoff`,
                messages: {
                    /** GET /api/external/chats/messages (name: api.external.chats.messages.index) */
                    index: () => `/api/external/chats/messages`,
                    /** POST /api/external/chats/messages (name: api.external.chats.messages.store) */
                    store: () => `/api/external/chats/messages`,
                },
            },
            hours: {
                /** GET /api/external/hours/status (name: api.external.hours.status) */
                status: () => `/api/external/hours/status`,
            },
            loyalty: {
                /** POST /api/external/loyalty/lookup (name: api.external.loyalty.lookup) */
                lookup: () => `/api/external/loyalty/lookup`,
                /** POST /api/external/loyalty/redeem (name: api.external.loyalty.redeem) */
                redeem: () => `/api/external/loyalty/redeem`,
            },
        },
        features: {
            /** GET /api/v1/features (name: api.features.index) */
            index: () => `/api/v1/features`,
        },
        hours: {
            exceptions: {
                /** DELETE /api/v1/hours/exceptions/{id} (name: api.hours.exceptions.destroy) */
                destroy: (id: string | number) => `/api/v1/hours/exceptions/${id ?? ''}`,
                /** GET /api/v1/hours/exceptions (name: api.hours.exceptions.index) */
                index: () => `/api/v1/hours/exceptions`,
                /** POST /api/v1/hours/exceptions (name: api.hours.exceptions.store) */
                store: () => `/api/v1/hours/exceptions`,
                /** PUT /api/v1/hours/exceptions/{id} (name: api.hours.exceptions.update) */
                update: (id: string | number) => `/api/v1/hours/exceptions/${id ?? ''}`,
            },
            /** GET /api/v1/hours (name: api.hours.index) */
            index: () => `/api/v1/hours`,
            /** GET /api/v1/hours/status (name: api.hours.status) */
            status: () => `/api/v1/hours/status`,
            /** PUT /api/v1/hours (name: api.hours.update) */
            update: () => `/api/v1/hours`,
        },
        inventory: {
            history: {
                /** GET /api/v1/inventory/history/valuation (name: api.inventory.history.valuation) */
                valuation: () => `/api/v1/inventory/history/valuation`,
            },
            ingredients: {
                /** DELETE /api/v1/inventory/ingredients/{id} (name: api.inventory.ingredients.destroy) */
                destroy: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}`,
                /** GET /api/v1/inventory/ingredients (name: api.inventory.ingredients.index) */
                index: () => `/api/v1/inventory/ingredients`,
                /** POST /api/v1/inventory/ingredients/{id}/restore (name: api.inventory.ingredients.restore) */
                restore: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}/restore`,
                /** GET /api/v1/inventory/ingredients/{id} (name: api.inventory.ingredients.show) */
                show: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}`,
                /** POST /api/v1/inventory/ingredients (name: api.inventory.ingredients.store) */
                store: () => `/api/v1/inventory/ingredients`,
                /** PATCH /api/v1/inventory/ingredients/{id} (name: api.inventory.ingredients.update) */
                update: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}`,
            },
            movements: {
                /** POST /api/v1/inventory/ingredients/{id}/movements/adjustment (name: api.inventory.movements.adjustment) */
                adjustment: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}/movements/adjustment`,
                /** POST /api/v1/inventory/ingredients/{id}/movements/entry (name: api.inventory.movements.entry) */
                entry: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}/movements/entry`,
                /** GET /api/v1/inventory/ingredients/{id}/movements (name: api.inventory.movements.index) */
                index: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}/movements`,
                /** POST /api/v1/inventory/ingredients/{id}/movements/waste (name: api.inventory.movements.waste) */
                waste: (id: string | number) => `/api/v1/inventory/ingredients/${id ?? ''}/movements/waste`,
            },
            transfers: {
                /** POST /api/v1/inventory/transfers (name: api.inventory.transfers.store) */
                store: () => `/api/v1/inventory/transfers`,
            },
            /** GET /api/v1/inventory/valuation (name: api.inventory.valuation) */
            valuation: () => `/api/v1/inventory/valuation`,
        },
        invitations: {
            /** POST /api/v1/invitations/{id}/resend (name: api.invitations.resend) */
            resend: (id: string | number) => `/api/v1/invitations/${id ?? ''}/resend`,
            /** POST /api/v1/invitations (name: api.invitations.store) */
            store: () => `/api/v1/invitations`,
        },
        kds: {
            station: {
                items: {
                    /** PATCH /api/v1/kds/{stationSlug}/items/{itemId}/mark-in-kitchen (name: api.kds.station.items.mark-in-kitchen) */
                    markInKitchen: (stationSlug: string | number, itemId: string | number) => `/api/v1/kds/${stationSlug ?? ''}/items/${itemId ?? ''}/mark-in-kitchen`,
                    /** PATCH /api/v1/kds/{stationSlug}/items/{itemId}/mark-ready (name: api.kds.station.items.mark-ready) */
                    markReady: (stationSlug: string | number, itemId: string | number) => `/api/v1/kds/${stationSlug ?? ''}/items/${itemId ?? ''}/mark-ready`,
                    /** PATCH /api/v1/kds/{stationSlug}/items/{itemId}/mark-served (name: api.kds.station.items.mark-served) */
                    markServed: (stationSlug: string | number, itemId: string | number) => `/api/v1/kds/${stationSlug ?? ''}/items/${itemId ?? ''}/mark-served`,
                },
                tickets: {
                    /** GET /api/v1/kds/{stationSlug}/tickets (name: api.kds.station.tickets.index) */
                    index: (stationSlug: string | number) => `/api/v1/kds/${stationSlug ?? ''}/tickets`,
                },
            },
            stations: {
                /** GET /api/v1/kds/stations (name: api.kds.stations.index) */
                index: () => `/api/v1/kds/stations`,
            },
            tickets: {
                /** GET /api/v1/kds/tickets (name: api.kds.tickets.index) */
                index: () => `/api/v1/kds/tickets`,
                /** PATCH /api/v1/kds/tickets/{item}/mark-in-kitchen (name: api.kds.tickets.mark-in-kitchen) */
                markInKitchen: (item: string | number) => `/api/v1/kds/tickets/${item ?? ''}/mark-in-kitchen`,
                /** PATCH /api/v1/kds/tickets/{item}/mark-ready (name: api.kds.tickets.mark-ready) */
                markReady: (item: string | number) => `/api/v1/kds/tickets/${item ?? ''}/mark-ready`,
                /** PATCH /api/v1/kds/tickets/{item}/mark-served (name: api.kds.tickets.mark-served) */
                markServed: (item: string | number) => `/api/v1/kds/tickets/${item ?? ''}/mark-served`,
            },
        },
        loyalty: {
            /** POST /api/v1/loyalty/accounts/{phone}/adjust (name: api.loyalty.adjust) */
            adjust: (phone: string | number) => `/api/v1/loyalty/accounts/${phone ?? ''}/adjust`,
            /** GET /api/v1/loyalty/accounts (name: api.loyalty.index) */
            index: () => `/api/v1/loyalty/accounts`,
            /** POST /api/v1/loyalty/accounts/{phone}/redeem (name: api.loyalty.redeem) */
            redeem: (phone: string | number) => `/api/v1/loyalty/accounts/${phone ?? ''}/redeem`,
            reports: {
                /** GET /api/v1/loyalty/reports/summary (name: api.loyalty.reports.summary) */
                summary: () => `/api/v1/loyalty/reports/summary`,
            },
            /** GET /api/v1/loyalty/accounts/{phone} (name: api.loyalty.show) */
            show: (phone: string | number) => `/api/v1/loyalty/accounts/${phone ?? ''}`,
        },
        /** GET /api/v1/me (name: api.me) */
        me: () => `/api/v1/me`,
        menus: {
            /** PATCH /api/v1/menus/{id}/activate (name: api.menus.activate) */
            activate: (id: string | number) => `/api/v1/menus/${id ?? ''}/activate`,
            categories: {
                /** DELETE /api/v1/menus/{id}/categories/{catId} (name: api.menus.categories.destroy) */
                destroy: (id: string | number, catId: string | number) => `/api/v1/menus/${id ?? ''}/categories/${catId ?? ''}`,
                /** POST /api/v1/menus/{id}/categories (name: api.menus.categories.store) */
                store: (id: string | number) => `/api/v1/menus/${id ?? ''}/categories`,
                /** PUT /api/v1/menus/{id}/categories/{catId} (name: api.menus.categories.update) */
                update: (id: string | number, catId: string | number) => `/api/v1/menus/${id ?? ''}/categories/${catId ?? ''}`,
            },
            /** PATCH /api/v1/menus/{id}/deactivate (name: api.menus.deactivate) */
            deactivate: (id: string | number) => `/api/v1/menus/${id ?? ''}/deactivate`,
            /** DELETE /api/v1/menus/{id} (name: api.menus.destroy) */
            destroy: (id: string | number) => `/api/v1/menus/${id ?? ''}`,
            /** POST /api/v1/menus/{id}/duplicate (name: api.menus.duplicate) */
            duplicate: (id: string | number) => `/api/v1/menus/${id ?? ''}/duplicate`,
            /** GET /api/v1/menus (name: api.menus.index) */
            index: () => `/api/v1/menus`,
            items: {
                /** PATCH /api/v1/menus/{id}/categories/{catId}/items/{itemId}/availability (name: api.menus.items.availability) */
                availability: (id: string | number, catId: string | number, itemId: string | number) => `/api/v1/menus/${id ?? ''}/categories/${catId ?? ''}/items/${itemId ?? ''}/availability`,
                /** GET /api/v1/menus/{menu}/items/{itemId}/cost (name: api.menus.items.cost) */
                cost: (menu: string | number, itemId: string | number) => `/api/v1/menus/${menu ?? ''}/items/${itemId ?? ''}/cost`,
                /** DELETE /api/v1/menus/{id}/categories/{catId}/items/{itemId} (name: api.menus.items.destroy) */
                destroy: (id: string | number, catId: string | number, itemId: string | number) => `/api/v1/menus/${id ?? ''}/categories/${catId ?? ''}/items/${itemId ?? ''}`,
                /** POST /api/v1/menus/{id}/items/{itemId}/image (name: api.menus.items.image) */
                image: (id: string | number, itemId: string | number) => `/api/v1/menus/${id ?? ''}/items/${itemId ?? ''}/image`,
                recipe: {
                    /** GET /api/v1/menus/{menu}/items/{itemId}/recipe (name: api.menus.items.recipe.show) */
                    show: (menu: string | number, itemId: string | number) => `/api/v1/menus/${menu ?? ''}/items/${itemId ?? ''}/recipe`,
                    /** PUT /api/v1/menus/{menu}/items/{itemId}/recipe (name: api.menus.items.recipe.upsert) */
                    upsert: (menu: string | number, itemId: string | number) => `/api/v1/menus/${menu ?? ''}/items/${itemId ?? ''}/recipe`,
                },
                /** POST /api/v1/menus/{id}/categories/{catId}/items (name: api.menus.items.store) */
                store: (id: string | number, catId: string | number) => `/api/v1/menus/${id ?? ''}/categories/${catId ?? ''}/items`,
                /** PUT /api/v1/menus/{id}/categories/{catId}/items/{itemId} (name: api.menus.items.update) */
                update: (id: string | number, catId: string | number, itemId: string | number) => `/api/v1/menus/${id ?? ''}/categories/${catId ?? ''}/items/${itemId ?? ''}`,
                /** PUT /api/v1/menus/{id}/items/{itemId} (name: api.menus.items.update-direct) */
                updateDirect: (id: string | number, itemId: string | number) => `/api/v1/menus/${id ?? ''}/items/${itemId ?? ''}`,
            },
            /** GET /api/v1/public/menu/{companyNit} (name: api.menus.public) */
            public: (companyNit: string | number) => `/api/v1/public/menu/${companyNit ?? ''}`,
            /** PATCH /api/v1/menus/{id}/schedule (name: api.menus.schedule) */
            schedule: (id: string | number) => `/api/v1/menus/${id ?? ''}/schedule`,
            /** GET /api/v1/menus/{id} (name: api.menus.show) */
            show: (id: string | number) => `/api/v1/menus/${id ?? ''}`,
            /** POST /api/v1/menus (name: api.menus.store) */
            store: () => `/api/v1/menus`,
            /** POST /api/v1/menus/sync-schedule (name: api.menus.sync-schedule) */
            syncSchedule: () => `/api/v1/menus/sync-schedule`,
            /** PUT /api/v1/menus/{id} (name: api.menus.update) */
            update: (id: string | number) => `/api/v1/menus/${id ?? ''}`,
        },
        metrics: {
            activity: {
                /** GET /api/v1/metrics/activity/heatmap (name: api.metrics.activity.heatmap) */
                heatmap: () => `/api/v1/metrics/activity/heatmap`,
            },
            cart: {
                /** GET /api/v1/metrics/cart/abandonment (name: api.metrics.cart.abandonment) */
                abandonment: () => `/api/v1/metrics/cart/abandonment`,
            },
            carts: {
                /** GET /api/v1/metrics/carts/abandonment (name: api.metrics.carts.abandonment) */
                abandonment: () => `/api/v1/metrics/carts/abandonment`,
            },
            dishes: {
                /** GET /api/v1/metrics/dishes/margin (name: api.metrics.dishes.margin) */
                margin: () => `/api/v1/metrics/dishes/margin`,
                /** GET /api/v1/metrics/dishes/ranking (name: api.metrics.dishes.ranking) */
                ranking: () => `/api/v1/metrics/dishes/ranking`,
            },
            foodcost: {
                item: {
                    /** GET /api/v1/metrics/foodcost/items/{menuItemId}/history (name: api.metrics.foodcost.item.history) */
                    history: (menuItemId: string | number) => `/api/v1/metrics/foodcost/items/${menuItemId ?? ''}/history`,
                },
                /** GET /api/v1/metrics/foodcost/summary (name: api.metrics.foodcost.summary) */
                summary: () => `/api/v1/metrics/foodcost/summary`,
            },
            items: {
                /** GET /api/v1/metrics/items/top (name: api.metrics.items.top) */
                top: () => `/api/v1/metrics/items/top`,
            },
            kpis: {
                /** GET /api/v1/metrics/kpis/today (name: api.metrics.kpis.today) */
                today: () => `/api/v1/metrics/kpis/today`,
            },
            /** GET /api/v1/metrics/menu-engineering (name: api.metrics.menu-engineering) */
            menuEngineering: () => `/api/v1/metrics/menu-engineering`,
            offline: {
                /** GET /api/v1/metrics/offline/operation (name: api.metrics.offline.operation) */
                operation: () => `/api/v1/metrics/offline/operation`,
            },
            orders: {
                /** GET /api/v1/metrics/orders/active (name: api.metrics.orders.active) */
                active: () => `/api/v1/metrics/orders/active`,
                /** GET /api/v1/metrics/orders/heatmap (name: api.metrics.orders.heatmap) */
                heatmap: () => `/api/v1/metrics/orders/heatmap`,
            },
            /** GET /api/v1/metrics/summary (name: api.metrics.summary) */
            summary: () => `/api/v1/metrics/summary`,
        },
        orders: {
            /** POST /api/v1/orders/{id}/items (name: api.orders.appendItems) */
            appendItems: (id: string | number) => `/api/v1/orders/${id ?? ''}/items`,
            /** POST /api/v1/orders/{orderId}/assign-courier (name: api.orders.assign-courier) */
            assignCourier: (orderId: string | number) => `/api/v1/orders/${orderId ?? ''}/assign-courier`,
            /** GET /api/v1/orders/{orderId}/available-deliverers (name: api.orders.available-deliverers) */
            availableDeliverers: (orderId: string | number) => `/api/v1/orders/${orderId ?? ''}/available-deliverers`,
            /** POST /api/v1/orders/{id}/cancel (name: api.orders.cancel) */
            cancel: (id: string | number) => `/api/v1/orders/${id ?? ''}/cancel`,
            /** POST /api/v1/orders/{id}/close-with-payment (name: api.orders.closeWithPayment) */
            closeWithPayment: (id: string | number) => `/api/v1/orders/${id ?? ''}/close-with-payment`,
            /** GET /api/v1/orders (name: api.orders.index) */
            index: () => `/api/v1/orders`,
            /** GET /api/v1/orders/pending-approvals (name: api.orders.pending-approvals) */
            pendingApprovals: () => `/api/v1/orders/pending-approvals`,
            /** GET /api/v1/orders/pending-cancellations (name: api.orders.pending-cancellations) */
            pendingCancellations: () => `/api/v1/orders/pending-cancellations`,
            receipt: {
                /** GET /api/v1/orders/{id}/receipt-escpos (name: api.orders.receipt.escpos) */
                escpos: (id: string | number) => `/api/v1/orders/${id ?? ''}/receipt-escpos`,
            },
            /** POST /api/v1/orders/{id}/refund (name: api.orders.refund) */
            refund: (id: string | number) => `/api/v1/orders/${id ?? ''}/refund`,
            /** GET /api/v1/orders/{id} (name: api.orders.show) */
            show: (id: string | number) => `/api/v1/orders/${id ?? ''}`,
            /** POST /api/v1/orders (name: api.orders.store) */
            store: () => `/api/v1/orders`,
            /** POST /api/v1/orders/sync-batch (name: api.orders.syncBatch) */
            syncBatch: () => `/api/v1/orders/sync-batch`,
            /** GET /api/v1/orders/tables (name: api.orders.tables) */
            tables: () => `/api/v1/orders/tables`,
            /** PATCH /api/v1/orders/{id}/status (name: api.orders.updateStatus) */
            updateStatus: (id: string | number) => `/api/v1/orders/${id ?? ''}/status`,
        },
        public: {
            loyalty: {
                /** POST /api/v1/public/loyalty/{nit}/lookup (name: api.public.loyalty.lookup) */
                lookup: (nit: string | number) => `/api/v1/public/loyalty/${nit ?? ''}/lookup`,
                /** POST /api/v1/public/loyalty/{nit}/redeem (name: api.public.loyalty.redeem) */
                redeem: (nit: string | number) => `/api/v1/public/loyalty/${nit ?? ''}/redeem`,
            },
            table: {
                /** GET /api/v1/public/table/{qr_token}/contact-lookup (name: api.public.table.contact_lookup) */
                contact_lookup: (qr_token: string | number) => `/api/v1/public/table/${qr_token ?? ''}/contact-lookup`,
                items: {
                    /** POST /api/v1/public/table/{qr_token}/items (name: api.public.table.items.add) */
                    add: (qr_token: string | number) => `/api/v1/public/table/${qr_token ?? ''}/items`,
                    /** DELETE /api/v1/public/table/{qr_token}/items/{item} (name: api.public.table.items.cancel) */
                    cancel: (qr_token: string | number, item: string | number) => `/api/v1/public/table/${qr_token ?? ''}/items/${item ?? ''}`,
                    /** PATCH /api/v1/public/table/{qr_token}/items/{item} (name: api.public.table.items.update) */
                    update: (qr_token: string | number, item: string | number) => `/api/v1/public/table/${qr_token ?? ''}/items/${item ?? ''}`,
                },
                /** GET /api/v1/public/table/{qr_token} (name: api.public.table.join) */
                join: (qr_token: string | number) => `/api/v1/public/table/${qr_token ?? ''}`,
                /** GET /api/v1/public/table/{qr_token}/menu (name: api.public.table.menu) */
                menu: (qr_token: string | number) => `/api/v1/public/table/${qr_token ?? ''}/menu`,
                notes: {
                    /** POST /api/v1/public/table/{qr_token}/notes (name: api.public.table.notes.add) */
                    add: (qr_token: string | number) => `/api/v1/public/table/${qr_token ?? ''}/notes`,
                },
                /** GET /api/v1/public/table/{qr_token}/state (name: api.public.table.state) */
                state: (qr_token: string | number) => `/api/v1/public/table/${qr_token ?? ''}/state`,
                /** POST /api/v1/public/table/{qr_token}/submit (name: api.public.table.submit) */
                submit: (qr_token: string | number) => `/api/v1/public/table/${qr_token ?? ''}/submit`,
            },
        },
        purchases: {
            attachments: {
                /** DELETE /api/v1/purchases/{id}/attachments/{attachmentId} (name: api.purchases.attachments.destroy) */
                destroy: (id: string | number, attachmentId: string | number) => `/api/v1/purchases/${id ?? ''}/attachments/${attachmentId ?? ''}`,
                /** GET /api/v1/purchases/{id}/attachments/{attachmentId}/download (name: api.purchases.attachments.download) */
                download: (id: string | number, attachmentId: string | number) => `/api/v1/purchases/${id ?? ''}/attachments/${attachmentId ?? ''}/download`,
                /** GET /api/v1/purchases/{id}/attachments (name: api.purchases.attachments.index) */
                index: (id: string | number) => `/api/v1/purchases/${id ?? ''}/attachments`,
                /** POST /api/v1/purchases/{id}/attachments (name: api.purchases.attachments.store) */
                store: (id: string | number) => `/api/v1/purchases/${id ?? ''}/attachments`,
            },
            /** POST /api/v1/purchases/{id}/cancel (name: api.purchases.cancel) */
            cancel: (id: string | number) => `/api/v1/purchases/${id ?? ''}/cancel`,
            /** GET /api/v1/purchases (name: api.purchases.index) */
            index: () => `/api/v1/purchases`,
            /** POST /api/v1/purchases/{id}/pay (name: api.purchases.pay) */
            pay: (id: string | number) => `/api/v1/purchases/${id ?? ''}/pay`,
            /** POST /api/v1/purchases/{id}/receive (name: api.purchases.receive) */
            receive: (id: string | number) => `/api/v1/purchases/${id ?? ''}/receive`,
            /** POST /api/v1/purchases/{id}/settle-refund (name: api.purchases.settle_refund) */
            settle_refund: (id: string | number) => `/api/v1/purchases/${id ?? ''}/settle-refund`,
            /** GET /api/v1/purchases/{id} (name: api.purchases.show) */
            show: (id: string | number) => `/api/v1/purchases/${id ?? ''}`,
            /** POST /api/v1/purchases (name: api.purchases.store) */
            store: () => `/api/v1/purchases`,
            /** POST /api/v1/purchases/{id}/submit (name: api.purchases.submit) */
            submit: (id: string | number) => `/api/v1/purchases/${id ?? ''}/submit`,
            /** PATCH /api/v1/purchases/{id} (name: api.purchases.update) */
            update: (id: string | number) => `/api/v1/purchases/${id ?? ''}`,
            /** POST /api/v1/purchases/{id}/void (name: api.purchases.void) */
            void: (id: string | number) => `/api/v1/purchases/${id ?? ''}/void`,
        },
        push: {
            subscriptions: {
                /** DELETE /api/v1/push/subscriptions (name: api.push.subscriptions.destroy) */
                destroy: () => `/api/v1/push/subscriptions`,
                /** GET /api/v1/push/subscriptions/me (name: api.push.subscriptions.index) */
                index: () => `/api/v1/push/subscriptions/me`,
                /** POST /api/v1/push/subscriptions (name: api.push.subscriptions.store) */
                store: () => `/api/v1/push/subscriptions`,
            },
        },
        reports: {
            /** GET /api/v1/reports/cash-drawer (name: api.reports.cashDrawer) */
            cashDrawer: () => `/api/v1/reports/cash-drawer`,
            cashRegister: {
                /** GET /api/v1/reports/cash-register/sessions (name: api.reports.cashRegister.index) */
                index: () => `/api/v1/reports/cash-register/sessions`,
                /** GET /api/v1/reports/cash-register/sessions/{id} (name: api.reports.cashRegister.show) */
                show: (id: string | number) => `/api/v1/reports/cash-register/sessions/${id ?? ''}`,
            },
            /** GET /api/v1/reports/download/{token} (name: api.reports.download) */
            download: (token: string | number) => `/api/v1/reports/download/${token ?? ''}`,
            /** POST /api/v1/reports/export (name: api.reports.export) */
            export: () => `/api/v1/reports/export`,
            /** GET /api/v1/reports/orders (name: api.reports.orders) */
            orders: () => `/api/v1/reports/orders`,
            /** GET /api/v1/reports/workforce (name: api.reports.workforce) */
            workforce: () => `/api/v1/reports/workforce`,
        },
        roles: {
            /** DELETE /api/v1/roles/{id} (name: api.roles.destroy) */
            destroy: (id: string | number) => `/api/v1/roles/${id ?? ''}`,
            /** GET /api/v1/roles (name: api.roles.index) */
            index: () => `/api/v1/roles`,
            /** POST /api/v1/roles (name: api.roles.store) */
            store: () => `/api/v1/roles`,
            /** PUT /api/v1/roles/{id} (name: api.roles.update) */
            update: (id: string | number) => `/api/v1/roles/${id ?? ''}`,
        },
        shifts: {
            /** POST /api/v1/shifts/{id}/cancel (name: api.shifts.cancel) */
            cancel: (id: string | number) => `/api/v1/shifts/${id ?? ''}/cancel`,
            /** GET /api/v1/shifts (name: api.shifts.index) */
            index: () => `/api/v1/shifts`,
            /** POST /api/v1/shifts (name: api.shifts.store) */
            store: () => `/api/v1/shifts`,
            /** POST /api/v1/shifts/suggest (name: api.shifts.suggest) */
            suggest: () => `/api/v1/shifts/suggest`,
            /** PUT /api/v1/shifts/{id} (name: api.shifts.update) */
            update: (id: string | number) => `/api/v1/shifts/${id ?? ''}`,
        },
        suppliers: {
            /** DELETE /api/v1/suppliers/{id} (name: api.suppliers.destroy) */
            destroy: (id: string | number) => `/api/v1/suppliers/${id ?? ''}`,
            /** GET /api/v1/suppliers (name: api.suppliers.index) */
            index: () => `/api/v1/suppliers`,
            /** POST /api/v1/suppliers/{id}/restore (name: api.suppliers.restore) */
            restore: (id: string | number) => `/api/v1/suppliers/${id ?? ''}/restore`,
            /** GET /api/v1/suppliers/{id} (name: api.suppliers.show) */
            show: (id: string | number) => `/api/v1/suppliers/${id ?? ''}`,
            /** POST /api/v1/suppliers (name: api.suppliers.store) */
            store: () => `/api/v1/suppliers`,
            /** PATCH /api/v1/suppliers/{id} (name: api.suppliers.update) */
            update: (id: string | number) => `/api/v1/suppliers/${id ?? ''}`,
        },
        tables: {
            /** DELETE /api/v1/tables/{id} (name: api.tables.destroy) */
            destroy: (id: string | number) => `/api/v1/tables/${id ?? ''}`,
            /** GET /api/v1/tables (name: api.tables.index) */
            index: () => `/api/v1/tables`,
            /** POST /api/v1/tables/{id}/regenerate-qr (name: api.tables.regenerate-qr) */
            regenerateQr: (id: string | number) => `/api/v1/tables/${id ?? ''}/regenerate-qr`,
            /** POST /api/v1/tables/{id}/restore (name: api.tables.restore) */
            restore: (id: string | number) => `/api/v1/tables/${id ?? ''}/restore`,
            /** POST /api/v1/tables (name: api.tables.store) */
            store: () => `/api/v1/tables`,
            /** PATCH /api/v1/tables/{id} (name: api.tables.update) */
            update: (id: string | number) => `/api/v1/tables/${id ?? ''}`,
        },
        tableSessions: {
            /** POST /api/v1/table-sessions/{id}/accepts-new-guests (name: api.table-sessions.accepts-new-guests) */
            acceptsNewGuests: (id: string | number) => `/api/v1/table-sessions/${id ?? ''}/accepts-new-guests`,
            /** POST /api/v1/table-sessions/{id}/notes (name: api.table-sessions.add-note) */
            addNote: (id: string | number) => `/api/v1/table-sessions/${id ?? ''}/notes`,
            /** POST /api/v1/table-sessions/{id}/approve-batch (name: api.table-sessions.approve-batch) */
            approveBatch: (id: string | number) => `/api/v1/table-sessions/${id ?? ''}/approve-batch`,
            /** GET /api/v1/table-sessions/billable (name: api.table-sessions.billable) */
            billable: () => `/api/v1/table-sessions/billable`,
            /** POST /api/v1/table-sessions/{id}/items/{item}/cancel (name: api.table-sessions.cancel-item) */
            cancelItem: (id: string | number, item: string | number) => `/api/v1/table-sessions/${id ?? ''}/items/${item ?? ''}/cancel`,
            /** POST /api/v1/table-sessions/{id}/close-empty (name: api.table-sessions.close-empty) */
            closeEmpty: (id: string | number) => `/api/v1/table-sessions/${id ?? ''}/close-empty`,
            /** PATCH /api/v1/table-sessions/{id}/items/{item}/notes (name: api.table-sessions.edit-item-notes) */
            editItemNotes: (id: string | number, item: string | number) => `/api/v1/table-sessions/${id ?? ''}/items/${item ?? ''}/notes`,
            /** GET /api/v1/table-sessions (name: api.table-sessions.index) */
            index: () => `/api/v1/table-sessions`,
            /** POST /api/v1/table-sessions/{id}/items/{item}/reject (name: api.table-sessions.reject-item) */
            rejectItem: (id: string | number, item: string | number) => `/api/v1/table-sessions/${id ?? ''}/items/${item ?? ''}/reject`,
            /** GET /api/v1/table-sessions/{id} (name: api.table-sessions.show) */
            show: (id: string | number) => `/api/v1/table-sessions/${id ?? ''}`,
        },
        users: {
            /** DELETE /api/v1/users/{id} (name: api.users.destroy) */
            destroy: (id: string | number) => `/api/v1/users/${id ?? ''}`,
            /** GET /api/v1/users (name: api.users.index) */
            index: () => `/api/v1/users`,
            /** PUT /api/v1/users/{id}/permissions (name: api.users.updatePermissions) */
            updatePermissions: (id: string | number) => `/api/v1/users/${id ?? ''}/permissions`,
            /** PUT /api/v1/users/{id}/role (name: api.users.updateRole) */
            updateRole: (id: string | number) => `/api/v1/users/${id ?? ''}/role`,
            /** PATCH /api/v1/users/{id}/status (name: api.users.updateStatus) */
            updateStatus: (id: string | number) => `/api/v1/users/${id ?? ''}/status`,
        },
        webhooks: {
            /** POST /api/v1/webhooks/dian/{provider} (name: api.webhooks.dian) */
            dian: (provider: string | number) => `/api/v1/webhooks/dian/${provider ?? ''}`,
            ses: {
                /** POST /api/v1/webhooks/ses-notifications (name: api.webhooks.ses.receive) */
                receive: () => `/api/v1/webhooks/ses-notifications`,
            },
            whatsapp: {
                /** POST /api/v1/webhooks/whatsapp (name: api.webhooks.whatsapp.receive) */
                receive: () => `/api/v1/webhooks/whatsapp`,
                /** GET /api/v1/webhooks/whatsapp (name: api.webhooks.whatsapp.verify) */
                verify: () => `/api/v1/webhooks/whatsapp`,
            },
        },
        whatsapp: {
            /** DELETE /api/v1/whatsapp (name: api.whatsapp.disconnect) */
            disconnect: () => `/api/v1/whatsapp`,
            /** POST /api/v1/whatsapp/embedded-signup-callback (name: api.whatsapp.embedded-signup-callback) */
            embeddedSignupCallback: () => `/api/v1/whatsapp/embedded-signup-callback`,
            /** POST /api/v1/whatsapp/naas-request (name: api.whatsapp.naas-request) */
            naasRequest: () => `/api/v1/whatsapp/naas-request`,
            phone: {
                /** DELETE /api/v1/whatsapp/phone (name: api.whatsapp.phone.delete) */
                delete: () => `/api/v1/whatsapp/phone`,
            },
            /** GET /api/v1/whatsapp (name: api.whatsapp.show) */
            show: () => `/api/v1/whatsapp`,
            verification: {
                /** GET /api/v1/whatsapp/verification/reject (name: api.whatsapp.verification.reject) */
                reject: () => `/api/v1/whatsapp/verification/reject`,
                /** POST /api/v1/whatsapp/verification/request (name: api.whatsapp.verification.request) */
                request: () => `/api/v1/whatsapp/verification/request`,
                /** POST /api/v1/whatsapp/verification/verify (name: api.whatsapp.verification.verify) */
                verify: () => `/api/v1/whatsapp/verification/verify`,
            },
        },
        workforceSettings: {
            /** GET /api/v1/workforce-settings (name: api.workforceSettings.show) */
            show: () => `/api/v1/workforce-settings`,
            /** PUT /api/v1/workforce-settings (name: api.workforceSettings.update) */
            update: () => `/api/v1/workforce-settings`,
        },
    } as const;

export type ApiRoutes = typeof apiRoutes;
