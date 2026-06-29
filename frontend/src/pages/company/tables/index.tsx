// ponytail: absorbida por /orders/tables?tab=config (#unificacion-mesas)
import { Navigate } from 'react-router-dom';

export default function CompanyTablesRedirect() {
    return <Navigate to="/orders/tables?tab=config" replace />;
}
