# AmazonMarket - Sistema de Gestion de Ventas

Sistema Point of Sale (POS) y gestion comercial para empresa de comercio electronico. Desarrollado en PHP 8.2 con arquitectura MVC personalizada, sin dependencias de frameworks externos.

## Caracteristicas

- **POS (Punto de Venta):** Carrito de compras, seleccion de cliente, comprobante y metodo de pago
- **Gestion de Productos:** CRUD con categorias, unidades de medida, control de stock y codigo de barras
- **Gestion de Clientes:** Documentos (DNI, RUC, Pasaporte, Carne), validacion por tipo
- **Gestion de Usuarios:** Roles y permisos (RBAC), autenticacion por sesion
- **Reportes (10 tipos):** Ventas, productos mas/menos vendidos, inventario, clientes, vendedores, categorias, comprobantes, metodos de pago y resumen ejecutivo
- **Exportacion:** PDF (FPDF) y Excel (OpenSpout) para cada reporte
- **Envio por Email:** Envio de reportes PDF via PHPMailer/SMTP
- **Dashboard:** KPIs en tiempo real, graficos Canvas puros (sin librerias externas)
- **Almacenamiento Seguro:** Imagenes con nombres hash (SHA-256), fuera del Document Root, protegidas con `.htaccess`

## Requisitos

- PHP 8.2+
- MySQL 8.0+ / MariaDB 10.6+
- XAMPP (Apache + MySQL)

## Instalacion

### 1. Clonar el repositorio

```bash
git clone https://github.com/jypp16/Amazon-Market.git
cd Amazon-Market
```

### 2. Base de datos

```bash
mysql -u root -e "CREATE DATABASE amazon_market CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root amazon_market < amazon_market.sql
```

### 3. Configurar variables de entorno

```bash
cp .env.example .env
```

Editar `.env` con tus credenciales:

```env
DB_HOST=localhost
DB_NAME=amazon_market
DB_USER=root
DB_PASS=

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USER=tu-email@gmail.com
MAIL_PASS=tu-app-password
MAIL_FROM=tu-email@gmail.com

STORAGE_PATH=C:/xampp/storage/amazon_market/productos
```

### 4. Almacenamiento de imagenes

Crear el directorio de almacenamiento seguro (fuera del Document Root):

```bash
mkdir -p C:/xampp/storage/amazon_market/productos
```

### 5. Acceder al sistema

```
http://localhost/Amazon-Market
```

## Estructura del Proyecto

```
Amazon-Market/
├── Assets/
│   ├── css/            # Estilos CSS
│   ├── js/             # JavaScript (API, modal, reportes, POS)
│   └── img/            # Imagenes estaticas
├── Config/
│   └── Config.php      # Carga de .env y definicion de constantes
├── Controllers/
│   ├── API/            # Controladores REST (JSON)
│   ├── ReporteController.php
│   ├── AuthController.php
│   └── ...
├── Helpers/
│   └── helpers.php     # Funciones auxiliares (CSRF, sanitizacion)
├── Libraries/
│   ├── Core/           # Framework MVC (Model, View, Load, ApiController)
│   ├── Mailer/         # PHPMailer wrapper
│   ├── Middleware/      # Auth, RBAC, CSRF, Sanitizacion
│   └── PDF/            # FPDF + ReportPDF (estilos corporativos)
├── Models/
│   └── ...Model.php    # Capa de acceso a datos (ORM ligero)
├── Services/
│   ├── ReporteService.php
│   ├── MailService.php
│   ├── ExcelService.php
│   ├── VentaService.php
│   ├── StorageService.php
│   └── DashboardService.php
├── Views/
│   ├── Reporte/        # 10 vistas de reportes
│   ├── templates/mail/ # Plantillas de email
│   ├── Header.php
│   ├── Footer.php
│   └── ...
├── index.php           # Front controller / Router
├── .env.example
└── amazon_market.sql
```

## Arquitectura

- **MVC personalizado:** `Libraries/Core/` contiene Model, View, Load, ApiController
- **API REST:** Rutas `/api/*` mapeadas a controladores API con dispatch por verbo HTTP
- **RBAC:** Sistema de roles y permisos con middleware
- **CSRF:** Tokens en meta tags para AJAX y campos ocultos para formularios
- **Rate Limiting:** Proteccion contra fuerza bruta en login
- **Validacion:** Server-side con clase `Validation` + sanitizacion de inputs

## Tecnologias

| Capa | Tecnologia |
|------|-----------|
| Backend | PHP 8.2 |
| Base de datos | MySQL 8.0 |
| PDF | FPDF (ligero, coordenadas) |
| Excel | OpenSpout v4.32 |
| Email | PHPMailer (SMTP) |
| Frontend | HTML5, CSS3, JavaScript vanilla |
| Graficos | Canvas API pura (sin Chart.js) |
| Seguridad | CSRF, RBAC, Rate Limiting, Hash Storage |

## Licencia

Proyecto academico
