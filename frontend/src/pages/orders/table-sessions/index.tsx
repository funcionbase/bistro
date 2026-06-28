import { Navigate } from 'react-router-dom';

// ponytail: la vista de sesiones quedó absorbida por /orders/tables (#unificacion-mesas)
export default function TableSessionsIndex() {
    return <Navigate to="/orders/tables" replace />;
}
