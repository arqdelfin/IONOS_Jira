# 📤 Instrucciones para Subir a GitHub

## Opción 1: Crear repositorio en GitHub y hacer Push (Recomendado)

### Paso 1: Crear nuevo repositorio en GitHub

1. Ve a [github.com/new](https://github.com/new)
2. Rellena los datos:
   - **Repository name**: `IONOS_JIRA`
   - **Description**: Sistema de Consultas Web con BD IONOS
   - **Privacy**: Selecciona "Private" o "Public" según prefieras
   - **Do NOT initialize** (ya tenemos commits locales)
3. Haz clic en "Create repository"

### Paso 2: Vincular el repositorio remoto

Después de crear el repositorio, GitHub te mostrará un comando. Ejecuta en tu terminal:

```bash
git remote add origin https://github.com/arqdelfin/IONOS_JIRA.git
git branch -M main
git push -u origin main
```

**O si prefieres usar SSH** (más seguro):

```bash
git remote add origin git@github.com:arqdelfin/IONOS_JIRA.git
git branch -M main
git push -u origin main
```

### Paso 3: Verificar que se subió correctamente

```bash
git remote -v
# Debería mostrar:
# origin  https://github.com/arqdelfin/IONOS_JIRA.git (fetch)
# origin  https://github.com/arqdelfin/IONOS_JIRA.git (push)
```

---

## Opción 2: Usar GitHub CLI (si está instalado)

```bash
# Iniciar sesión
gh auth login

# Crear repositorio público en GitHub
gh repo create IONOS_JIRA --public --source=. --remote=origin --push
```

---

## Opción 3: Crear repositorio privado (con token)

Si prefieres un repositorio privado:

```bash
git remote add origin https://TOKEN@github.com/arqdelfin/IONOS_JIRA.git
git push -u origin main
```

Reemplaza `TOKEN` con tu [Personal Access Token](https://github.com/settings/tokens) de GitHub.

---

## ⚠️ Importante: Nunca Subas el Archivo .env

El archivo `.env` está en `.gitignore`, por lo que NO se subirá automáticamente. 

Verifica que no esté incluido:

```bash
git ls-files | grep .env
# No debería mostrar nada
```

---

## Verificar estado del Push

```bash
# Ver ramas locales y remotas
git branch -a

# Ver el último commit en GitHub
git log -1 --oneline

# Ver historial completo
git log --oneline --graph --all
```

---

## Después de Subir

1. **Configurar las opciones del repositorio**:
   - Ve a `Settings` → `Branches`
   - Configura protecciones de rama si quieres

2. **Agregar un `.github/workflows/` para CI/CD** (opcional)

3. **Invitar colaboradores** (si es necesario)

4. **Crear Issues y Pull Requests** para gestionar cambios

---

## Comandos Útiles Posteriores

```bash
# Actualizar repositorio remoto
git push origin main

# Traer cambios del repositorio remoto
git pull origin main

# Ver cambios pendientes
git status

# Hacer commit de cambios locales
git add .
git commit -m "Descripción del cambio"

# Ver historial
git log --oneline -10
```

---

¿Necesitas ayuda con algún paso específico?
