import ManualLayout from '@/layouts/manual-layout';

export default function ManualPlanner() {
    return (
        <ManualLayout
            currentSlug="planner"
            pageTitle="Planificador de turnos"
            pageDescription="Programa los turnos de tu equipo en una vista semanal o mensual: asigna empleados, horarios y cargos, y lleva el control de la cobertura de tu operación."
            metaTitle="Planificador de turnos — Manual bistro.flexyflow.co"
            metaDescription="Cómo usar el planificador de turnos de bistro.flexyflow.co: vista semanal, mensual, asignación de empleados por cargo y control de cobertura del local."
            sectionLabel="administración"
            readingTime="5 min"
        >
            <h2>¿Para qué sirve?</h2>
            <p>
                El planificador te ayuda a organizar quién trabaja en qué horario durante la semana o el mes.
                Es especialmente útil cuando manejas turnos rotativos, tienes varios cajeros y meseros, o
                necesitas saber de un vistazo si hay cobertura suficiente para un día de alta demanda.
            </p>
            <p>
                El planificador es por <strong>sede</strong>. Si tienes varias sedes, cada una tiene su propio
                cronograma.
            </p>

            <h2>Vistas disponibles</h2>
            <ul>
                <li>
                    <strong>Vista semanal</strong> (predeterminada): una grilla lunes-domingo con los turnos
                    del equipo. En cada celda ves el empleado, el cargo y el rango de horas del turno.
                </li>
                <li>
                    <strong>Vista mensual</strong>: un calendario con puntos de turnos por día. Útil para
                    planear el mes completo y detectar semanas con poca gente.
                </li>
            </ul>
            <p>
                Cambias entre vistas con las pestañas en la parte superior. La vista mensual se abre en{' '}
                <em>planificador → calendario</em>.
            </p>

            <h2>Crear un turno</h2>
            <ol>
                <li>
                    Ve a <strong>planificador</strong> y navega a la semana o el día donde quieres asignar.
                </li>
                <li>Dale a <kbd>+ Nuevo turno</kbd>.</li>
                <li>
                    Elige:
                    <ul>
                        <li>
                            <strong>Empleado</strong> — solo aparecen miembros activos de la sede.
                        </li>
                        <li>
                            <strong>Fecha, hora de inicio y hora de fin.</strong>
                        </li>
                        <li>
                            <strong>Cargo</strong> — el rol que va a desempeñar ese día (mesero, cajero,
                            cocinero, etc.). Es informativo, no afecta los permisos del panel.
                        </li>
                    </ul>
                </li>
                <li>Guarda. El turno aparece en la grilla al instante.</li>
            </ol>

            <h2>Editar o cancelar un turno</h2>
            <p>
                Haz clic sobre el turno en la grilla y elige <em>editar</em> o <em>cancelar</em>. Al cancelar
                se te pide un motivo (cambio de horario, incapacidad, etc.) que queda en el registro.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Turno cancelado vs. eliminado:</strong> los turnos no se borran duro — quedan
                    marcados como "cancelado" en el historial para que puedas ver la rotación real de tu equipo
                    a final de mes.
                </p>
            </div>

            <h2>Indicadores en la vista semanal</h2>
            <p>Al tope de la vista semanal ves dos contadores:</p>
            <ul>
                <li>
                    <strong>Turnos esta semana:</strong> cuántos turnos hay programados en los 7 días.
                </li>
                <li>
                    <strong>Empleados en la semana:</strong> cuántas personas distintas tienen turno esta
                    semana.
                </li>
            </ul>

            <h2>Quién puede usar el planificador</h2>
            <p>
                El acceso al planificador requiere el permiso de <strong>ver y gestionar turnos</strong>. Por
                defecto solo lo tienen el Propietario y el Administrador. Si quieres que el Gerente también
                pueda programar turnos, asígnaselo desde{' '}
                <em>usuarios → roles → editar rol Gerente → permisos de planificador</em>.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>El planificador no bloquea el acceso al panel:</strong> un empleado puede
                    ingresar a bistro flexy fuera de su turno programado — el planificador es de organización,
                    no de control de acceso horario.
                </p>
            </div>
        </ManualLayout>
    );
}
