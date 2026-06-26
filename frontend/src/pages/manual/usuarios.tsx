import ManualLayout from '@/layouts/manual-layout';

export default function ManualUsuarios() {
    return (
        <ManualLayout
            currentSlug="usuarios"
            pageTitle="Usuarios, roles y permisos"
            pageDescription="Cómo decides qué puede tocar cada miembro de tu operación. Tres roles base, ocho plantillas listas, un catálogo de unos 82 permisos y excepciones individuales cuando hace falta."
            metaTitle="Usuarios, roles y permisos — Manual bistro.flexyflow.co"
            metaDescription="Tres roles base, ocho plantillas operativas listas para usar y un editor fino para personalizar lo que cada miembro del equipo puede hacer. Invitaciones por correo con vigencia de 7 días."
            sectionLabel="administración"
            readingTime="9 min"
        >
            <h2>Cómo funciona</h2>
            <p>
                Cada miembro de tu equipo tiene un <strong>rol</strong> dentro del negocio. El rol decide a qué
                módulos puede entrar (menú, pedidos, caja, métricas, inventario, chats, etc.) y qué puede hacer
                en cada uno (<em>ver</em>, <em>crear</em>, <em>actualizar</em>, <em>eliminar</em>).
            </p>
            <p>
                Cuando creas tu negocio, bistro flexy te deja armados los tres roles base. A partir de ahí
                decides si te quedas con esos o si activas las plantillas operativas pre-armadas (mesero, cocinero,
                cajero, gerente…) para no tener que configurar permisos uno por uno.
            </p>

            <h3>Las cuatro acciones por módulo</h3>
            <ul>
                <li>
                    <strong>Ver:</strong> puede abrir la sección y consultar lo que hay.
                </li>
                <li>
                    <strong>Crear:</strong> agregar cosas nuevas (un menú, un plato, un cupón, un usuario, etc.).
                </li>
                <li>
                    <strong>Actualizar:</strong> modificar lo que ya existe.
                </li>
                <li>
                    <strong>Eliminar:</strong> borrar (en muchos módulos es archivar, no borrar duro — el
                    historial DIAN no se puede tocar).
                </li>
            </ul>

            <h3>Cuántos permisos hay</h3>
            <p>
                El catálogo tiene alrededor de <strong>82 permisos</strong> agrupados por dominio. No te asustes:
                nunca los vas a tocar uno por uno porque las plantillas ya traen combinaciones razonables.
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Dominio</th>
                        <th>Qué cubre</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Operaciones</td>
                        <td>Pedidos, menú, horarios, domicilios, KDS y estaciones.</td>
                    </tr>
                    <tr>
                        <td>Caja</td>
                        <td>Apertura y cierre de caja, pagos divididos, devoluciones.</td>
                    </tr>
                    <tr>
                        <td>Inventario</td>
                        <td>Insumos, recetas, compras, proveedores, bodegas, transferencias entre sedes.</td>
                    </tr>
                    <tr>
                        <td>Marketing</td>
                        <td>Cupones, fidelización, fichas de clientes (CRM).</td>
                    </tr>
                    <tr>
                        <td>Comunicación</td>
                        <td>Chats por WhatsApp, reasignación de chats entre sedes.</td>
                    </tr>
                    <tr>
                        <td>Analítica</td>
                        <td>Reportes, métricas, vista consolidada cross-sede.</td>
                    </tr>
                    <tr>
                        <td>Equipo (workforce)</td>
                        <td>Empleados, turnos, ver salarios, reportes de nómina.</td>
                    </tr>
                    <tr>
                        <td>Administración</td>
                        <td>Usuarios, roles, configuración de empresa, facturación, notificaciones.</td>
                    </tr>
                    <tr>
                        <td>Multi-sede</td>
                        <td>Crear y administrar sedes, asignar usuarios, copiar menú entre sedes.</td>
                    </tr>
                    <tr>
                        <td>DIAN</td>
                        <td>Perfil fiscal, resoluciones, proveedor tecnológico, documentos electrónicos.</td>
                    </tr>
                </tbody>
            </table>

            <h2>Los tres roles base del sistema</h2>
            <ul>
                <li>
                    <strong>Propietario</strong> — acceso total. Es el rol del dueño (el que abrió la cuenta) y
                    siempre debe existir al menos uno activo. Es el único que puede <em>cambiar el número de
                    WhatsApp conectado</em> o <em>desconectar la cuenta de WhatsApp</em>.
                </li>
                <li>
                    <strong>Administrador</strong> — acceso casi total, pensado para gerentes o socios. Por
                    defecto no recibe los permisos sensibles de sede ni los reservados al Propietario.
                </li>
                <li>
                    <strong>Empleado</strong> — solo lectura por defecto. Sirve de punto de partida para construir
                    accesos limitados u operativos sin tocar configuración.
                </li>
            </ul>
            <p>
                Estos tres <strong>no se pueden editar ni eliminar</strong> — son inmutables.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Última línea de seguridad:</strong> nunca te puedes quedar sin Propietario activo. El
                    sistema bloquea cualquier acción (eliminar miembro, desactivar, cambiar rol) que dejaría a la
                    empresa sin dueño.
                </p>
            </div>

            <h2>Las ocho plantillas operativas</h2>
            <p>
                Pre-armadas para los cargos típicos de tu operación. A diferencia de los tres base,{' '}
                <strong>estas sí las puedes renombrar, ajustar permisos o eliminar</strong>.
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Plantilla</th>
                        <th>Para qué se diseñó</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Mesero</strong>
                        </td>
                        <td>
                            Aprobar y rechazar tandas, editar notas del pedido, resolver solicitudes de
                            cancelación, ver y responder chats.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Cocinero</strong>
                        </td>
                        <td>
                            Tablero de cocina (KDS) exclusivo: ver órdenes y mover el estado de los ítems.
                            Nada de caja ni configuración.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Cajero</strong>
                        </td>
                        <td>Caja con pago dividido, devoluciones (refund), reportes propios del turno.</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Gerente</strong>
                        </td>
                        <td>
                            Operación de sede: cierra órdenes, ajusta menú, gestiona turnos y atiende inventario.
                            Sin contabilidad ni configuración fiscal.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Contador</strong>
                        </td>
                        <td>
                            Lectura financiera consolidada de todas las sedes: facturación, compras, proveedores,
                            reportes de nómina. Sin operación.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Marketing</strong>
                        </td>
                        <td>Cupones (sin poder eliminarlos), fidelización, clientes y chats.</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Bodeguero</strong>
                        </td>
                        <td>
                            Inventario completo, gestión de bodegas, compras (sin poder pagarlas ni eliminarlas) y
                            proveedores.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Supervisor</strong>
                        </td>
                        <td>
                            Vista casi solo lectura de todos los módulos operativos, con permiso de actualizar
                            pedidos y domicilios.
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2>Crear roles personalizados</h2>
            <ol>
                <li>
                    Vas a <strong>administración → usuarios y roles → roles</strong> y le das a{' '}
                    <kbd>nuevo rol</kbd>.
                </li>
                <li>Le pones nombre, descripción y un <strong>color</strong>.</li>
                <li>
                    Marcas los módulos y las acciones que quieres habilitar. Puedes usar{' '}
                    <strong>"Clonar permisos de…"</strong> para partir de un rol existente.
                </li>
                <li>
                    Hay <strong>interruptores por columna</strong> arriba de la matriz (Ver, Crear, Actualizar,
                    Eliminar) para marcar todos los módulos en un solo click.
                </li>
                <li>Guardas. Ya puedes asignarlo desde la tabla de miembros.</li>
            </ol>
            <p>
                <strong>No puedes eliminar</strong> un rol que tenga miembros asignados. Primero los pasas a
                otro rol y luego lo borras.
            </p>

            <h2>Invitar y administrar miembros</h2>
            <p>
                En <strong>administración → usuarios y roles → usuarios</strong> ves la tabla con todos los
                miembros del equipo.
            </p>

            <h3>Invitaciones por correo (vigencia 7 días)</h3>
            <p>
                Escribes el correo y eliges el rol con el que arrancará la persona. La invitación va por email
                con un enlace personal y <strong>dura 7 días</strong> antes de vencerse.
            </p>
            <ul>
                <li>
                    La persona entra al enlace, inicia sesión con su Google y queda enrolada al instante.
                </li>
                <li>
                    Si ya tenía cuenta en flexyflow, simplemente se le agrega tu operación como un negocio más.
                </li>
            </ul>

            <h2>Editor fino de permisos (excepciones individuales)</h2>
            <p>
                Cada miembro puede tener <strong>permisos extra</strong> que se suman a los de su rol, sin
                necesidad de cambiarle el rol completo. Útil para:
            </p>
            <ul>
                <li>
                    <em>"Camilo, el cocinero, está cubriendo al gerente esta semana. Dale acceso temporal a
                    métricas sin moverlo del rol Cocinero."</em>
                </li>
            </ul>

            <div className="callout callout-warn">
                <p>
                    <strong>Regla de oro:</strong> nadie puede otorgar permisos que él mismo no tiene. Esto evita
                    que alguien escale poco a poco hasta tener más acceso del que su jefe le dio.
                </p>
            </div>

            <div className="callout callout-info">
                <p>
                    <strong>Auto-protección:</strong> nadie puede modificar su propio rol, sus propios permisos ni
                    sacarse a sí mismo del equipo.
                </p>
            </div>

            <h2>Un ejemplo de equipo bien armado</h2>
            <p>Una <strong>pizzería con 8 personas</strong>:</p>
            <table>
                <thead>
                    <tr>
                        <th>Miembro</th>
                        <th>Rol</th>
                        <th>Excepciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Don Hernán (dueño)</td>
                        <td>Propietario</td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td>María (gerente)</td>
                        <td>Gerente (plantilla)</td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td>Sofía (cajera mañana)</td>
                        <td>Cajero (plantilla)</td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td>Andrea (cajera tarde)</td>
                        <td>Cajero (plantilla)</td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td>Camilo (jefe de cocina)</td>
                        <td>Cocinero (plantilla)</td>
                        <td>+ ver métricas</td>
                    </tr>
                    <tr>
                        <td>Mateo (cocinero)</td>
                        <td>Cocinero (plantilla)</td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td>Carlos (bodega/compras)</td>
                        <td>Bodeguero (plantilla)</td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td>Laura (contadora externa)</td>
                        <td>Contador (plantilla)</td>
                        <td>—</td>
                    </tr>
                </tbody>
            </table>
        </ManualLayout>
    );
}
