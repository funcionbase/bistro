# Notas pendientes

> Movidas desde comentarios `//` en `package.json` (JSON no admite comentarios y rompía `npm run build`).

- A las páginas de error como Error 404 en el frontend, hacer que sigan el design system del guideline.
- URLs a revisar:
  - http://localhost:5173/
  - http://localhost:5173/enrollment/user
  - http://localhost:5173/enrollment/company
- A `http://localhost:5173/enrollment/company` solo se puede llegar si se pasó por `http://localhost:5173/enrollment/user`, ya que el usuario que haga el enrollamiento será el dueño de la empresa. Si no, se pueden crear empresas huérfanas.
