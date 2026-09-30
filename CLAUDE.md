# OEC WP Theme

## Antes de hacer cambios: revisar la última versión publicada

Puede haber otras sesiones publicando versiones al mismo tiempo. Antes de
tocar código, y otra vez antes de publicar, revisar qué hay en GitHub:

```bash
git fetch origin --tags
gh release list --limit 3
git status -sb
```

- Si `main` está atrás de `origin/main`, traer esos commits antes de empezar.
- El número de versión nuevo es **la última release de GitHub + 1**, nunca
  el que uno recuerda de antes.

## Publicar una versión

1. Subir la versión en los dos lugares (tienen que coincidir):
   `Version:` en `style.css` (la que compara el actualizador) y
   `OEC_THEME_VERSION` en `functions.php`.
2. Commit con título `vX.Y.Z: <resumen>` y push a `main`.
3. Release en GitHub con el zip del tema:

   ```bash
   git archive --format=zip --prefix=oec-wp-theme/ -o oec-wp-theme.zip HEAD
   gh release create vX.Y.Z oec-wp-theme.zip --title vX.Y.Z --notes "<qué cambia>"
   ```

4. En wp-admin de producción: "Forzar búsqueda de actualización" y actualizar.
