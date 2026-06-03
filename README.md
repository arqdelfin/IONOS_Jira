# IONOS_JIRA - Sistema de Consultas Web con Base de Datos IONOS

Sistema web seguro de gestión y consulta de base de datos IONOS con autenticación de usuarios, protección CSRF y prevención de SQL Injection.

## 🚀 Características

- **Autenticación Segura**: Login con validación de contraseñas usando `password_verify()`
- **Protección CSRF**: Tokens CSRF en todos los formularios
- **SQL Injection Prevention**: Prepared statements en todas las consultas
- **Gestión de Sesiones**: Timeout configurable y cookies seguras
- **Variables de Entorno**: Credenciales en `.env` (no versionadas)
- **Manejo de Errores**: Logging seguro sin exposición de detalles
- **Control de Intentos**: Limitador de intentos fallidos de login

## 📋 Requisitos

- PHP 7.4+
- MySQL/MariaDB
- Servidor web (Apache/Nginx)
- OpenSSL (para bcrypt)

## 🔧 Instalación

### 1. Clonar el Repositorio

```bash
git clone https://github.com/arqdelfin/IONOS_JIRA.git
cd IONOS_JIRA
```

### 2. Configurar Variables de Entorno

```bash
cp .env.example .env
# Editar .env con tus credenciales
nano .env
```

Variables requeridas:
```
DB_HOST=tu_host_de_bd
DB_NAME=tu_base_datos
DB_USER=tu_usuario_bd
DB_PASSWORD=tu_password_bd
SESSION_TIMEOUT=1800
APP_DEBUG=false
```

### 3. Crear Directorio de Logs

```bash
mkdir logs
chmod 755 logs
```

### 4. Configurar la Base de Datos

La BD debe tener al menos estas tablas:

#### `t_usuarios`
```sql
CREATE TABLE t_usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario VARCHAR(100) UNIQUE NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL
);
```

#### `t_consultasweb`
```sql
CREATE TABLE t_consultasweb (
    id INT PRIMARY KEY AUTO_INCREMENT,
    consulta VARCHAR(255) NOT NULL,
    query TEXT NOT NULL
);
```

### 5. Hasher Contraseñas de Usuarios

Para insertar un usuario con contraseña segura:

```php
$password_hash = password_hash('mi_contraseña_segura', PASSWORD_DEFAULT);
// Guardar $password_hash en la BD
```

O en SQL directo (con PHP ejecutándolo primero):
```sql
INSERT INTO t_usuarios (usuario, nombre, apellidos, password_hash) 
VALUES ('admin', 'Admin', 'User', '$2y$10$...');
```

## 🏃 Uso

### Acceder al Sistema

1. Navega a `http://localhost/ruta/public/login.php`
2. Inicia sesión con tu usuario y contraseña
3. Selecciona una consulta predefinida y ejecuta

### Ejecutar Consultas

1. Las consultas están predefinidas en `t_consultasweb`
2. El sistema aplicará filtros dinámicos si se proporcionan
3. Los resultados se pueden exportar a CSV

### Cerrar Sesión

Haz clic en el botón "Cerrar sesión" en la esquina superior derecha.

## 🔐 Seguridad

Este proyecto implementa múltiples capas de protección:

### Implementado ✅
- [x] Prepared Statements (SQL Injection Prevention)
- [x] CSRF Tokens
- [x] Password Hashing (bcrypt)
- [x] Session Timeout
- [x] Secure Cookies (httponly, samesite)
- [x] Input Validation & Sanitization
- [x] Error Logging (no exposición en producción)
- [x] Environment Variables (.env)

### Recomendado 🎯
- [ ] HTTPS/SSL Obligatorio
- [ ] Rate Limiting
- [ ] 2FA (Two-Factor Authentication)
- [ ] Auditoría de Logs
- [ ] WAF (Web Application Firewall)
- [ ] Penetration Testing

Ver [SECURITY_IMPROVEMENTS.md](SECURITY_IMPROVEMENTS.md) para detalles completos.

## 📁 Estructura de Archivos

```
IONOS_JIRA/
├── config/
│   ├── conexion.php         # Conexión a BD
│   └── env_loader.php       # Cargador de .env
├── includes/
│   ├── consultas_manager.php    # Gestión de consultas
│   ├── exportar_csv.php         # Exportación CSV
│   ├── header.php               # Cabecera HTML
│   ├── login_manager.php        # Gestión de login
│   └── security.php             # Funciones de seguridad
├── public/
│   ├── bienvenida.php       # Página de inicio
│   ├── consultas.php        # Resultados de consultas
│   ├── estilos.css          # Estilos CSS
│   └── login.php            # Página de login
├── logs/                    # Archivos de log (creados por el app)
├── .env                     # Variables de entorno (NO versionar)
├── .env.example            # Plantilla de .env
├── .gitignore              # Archivos a ignorar
├── datoswebDU.php          # Controlador principal
└── SECURITY_IMPROVEMENTS.md # Documentación de seguridad
```

## 🔗 API Endpoints

### Login
```
POST /datoswebDU.php
accion=login
usuario=admin
password_hash=password
csrf_token=token
```

### Logout
```
GET/POST /datoswebDU.php?accion=logout
```

### Ejecutar Consulta
```
POST /datoswebDU.php
accion=consulta
tabla=nombre_tabla
columns[]=col1&columns[]=col2
filtros[0][columna]=col1
filtros[0][operador]=igual
filtros[0][valor]=valor
csrf_token=token
```

## 🐛 Troubleshooting

### "Error de conexión a BD"
- Verificar credenciales en `.env`
- Confirmar que el servidor MySQL está activo
- Revisar `logs/error.log`

### "Sesión expirada"
- Ajustar `SESSION_TIMEOUT` en `.env`
- Limpiar cookies del navegador
- Iniciar sesión nuevamente

### "Error de seguridad CSRF"
- Los tokens expiran con la sesión
- Recargar la página para obtener un nuevo token
- No usar back/forward del navegador

## 📝 Logging

Los errores se registran en `logs/error.log`:

```bash
tail -f logs/error.log  # Ver logs en tiempo real
```

## 🤝 Contribuir

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto está bajo licencia MIT. Ver [LICENSE](LICENSE) para más detalles.

## 👨‍💻 Autor

**Delfin** - Desarrollo Web Seguro

- GitHub: [@arqdelfin](https://github.com/arqdelfin)

## ⚠️ Disclaimer

Este es un sistema de demostración. Para producción:
- Realizar auditoría de seguridad profesional
- Implementar HTTPS/SSL obligatorio
- Usar certificados válidos
- Configurar firewall y WAF
- Realizar backups regulares
- Implementar 2FA

## 📞 Soporte

Para reportar bugs o sugerir mejoras, abre un issue en GitHub.

---

**Última actualización**: Junio 2026  
**Estado**: Activo y mantenido ✅
