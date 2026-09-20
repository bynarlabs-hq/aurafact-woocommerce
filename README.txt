=== Aurafact WooCommerce ===
Contributors: aurafact
Tags: facturación electrónica, SRI, Ecuador, factura, WooCommerce, Aurafact, blocks, classic
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.1
WC tested up to: 11.1
Stable tag: 1.1.0
License: GPL v3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Facturación electrónica ecuatoriana para WooCommerce. Conecta tu tienda con Aurafact y cumple con el SRI.

== Description ==

**Aurafact WooCommerce** integra tu tienda WooCommerce con la plataforma de facturación electrónica Aurafact, permitiéndote emitir comprobantes electrónicos (facturas, notas de crédito, retenciones) automáticamente al realizar pedidos, cumpliendo con los requisitos del SRI (Servicio de Rentas Internas) de Ecuador.

= Características principales =

* Emisión automática de facturas electrónicas al completar pedidos
* Soporte para múltiples ambientes: Sandbox (pruebas) y Producción
* Configuración sencilla desde WooCommerce > Ajustes > Aurafact
* Prueba de conexión para verificar la API Key
* Actualizaciones automáticas vía GitHub Releases
* Descarga de comprobantes (PDF/XML) desde el panel de pedidos
* Totalmente traducible (archivos .pot incluidos)

= Compatibilidad =

* **WooCommerce 7.1 en adelante** (compatible con versiones legacy y WC Blocks)
* **WordPress 6.0 en adelante**
* **PHP 7.4 en adelante**
* **Block themes** (Twenty Twenty-Five, etc.) y **classic themes** (Storefront, etc.)
* Compatible con todos los gateways de pago estándar de WooCommerce (PayPal, Stripe, transferencia, contra reembolso, etc.)

== Installation ==

1. Asegúrate de tener WooCommerce 7.1 o superior instalado y activo.
2. Sube la carpeta `aurafact-woocommerce` a `/wp-content/plugins/` o instala el archivo .zip desde Plugins > Añadir nuevo.
3. Activa el plugin desde el panel de Plugins.
4. Ve a WooCommerce > Ajustes > Aurafact.
5. Ingresa tu API Key de Aurafact y selecciona el ambiente (Sandbox o Producción).
6. Haz clic en "Probar conexión" para verificar la configuración.
7. Configura la cobertura de facturación (Solo Ecuador o Todos los países).
8. Guarda los cambios.

== Frequently Asked Questions ==

= ¿Qué es Aurafact? =

Aurafact es una plataforma de facturación electrónica ecuatoriana que te permite emitir comprobantes electrónicos (facturas, notas de crédito, retenciones, guías de remisión) cumpliendo con los requisitos del SRI.

= ¿Necesito una cuenta en Aurafact? =

Sí. Debes crear una cuenta en [aurafact.com](https://aurafact.com) y generar una API Key desde el panel de administración para poder usar este plugin.

= ¿Qué comprobantes se emiten? =

Actualmente se emiten facturas electrónicas automáticamente al completar pedidos. Próximamente se agregarán notas de crédito, retenciones y guías de remisión.

= ¿El plugin funciona con block themes (Twenty Twenty-Five)? =

Sí. El plugin detecta automáticamente si tu tema usa WC Blocks (block themes) o el shortcode clásico (classic themes) y utiliza la API adecuada en cada caso.

= ¿El plugin funciona en modo de prueba? =

Sí. Puedes seleccionar el ambiente "Sandbox (Pruebas)" en la configuración para emitir comprobantes de prueba sin afectar tu contabilidad real.

= ¿Funciona con cualquier gateway de pago? =

Sí. El plugin es agnóstico al método de pago: PayPal, Stripe, transferencia bancaria, contra reembolso, etc. Los hooks se ejecutan después del procesamiento del pago.

= ¿Cómo funciona la cobertura geográfica? =

Puedes elegir entre:
* **Solo Ecuador**: los campos fiscales solo aparecen si el cliente selecciona Ecuador como país de facturación.
* **Todos los países**: los campos aparecen siempre. Útil si vendes internacionalmente y quieres dar opción de factura.

== Screenshots ==

1. Pantalla de configuración en WooCommerce > Ajustes > Aurafact.
2. Sección de prueba de conexión y estado del sistema.
3. Campos fiscales en el checkout.

== Changelog ==

= 1.1.0 =
* **Nuevo**: Compatibilidad con WooCommerce Blocks (block themes como Twenty Twenty-Five).
* **Nuevo**: Sistema automático de detección de API (WC >= 8.9 usa API moderna, WC 7.x-8.8 usa filtro legacy).
* **Nuevo**: Configuración "Cobertura de facturación" (Solo Ecuador / Todos los países).
* **Nuevo**: Conditional fields vía JSON Schema (WC >= 9.9) — campos se ocultan automáticamente según país.
* **Mejorado**: Acceso unificado a campos de orden vía `Compat::get_field_value()`.
* **Mejorado**: Validación de país antes de emitir factura.
* **Compatible con**: WooCommerce 7.1 a 11.1.

= 1.0.0 =
* Lanzamiento inicial.
* Integración con WooCommerce.
* Configuración de API Key, ambiente y evento de emisión.
* Prueba de conexión a la API de Aurafact.
* Sistema de actualizaciones automáticas vía GitHub Releases.
* Traducciones base (archivo .pot).

== Upgrade Notice ==

= 1.1.0 =
Importante: si usas un tema block theme (Twenty Twenty-Five u otro), actualiza WooCommerce a 8.9+ para que el plugin use la API moderna de WC Blocks. Con WC 7.x-8.8 el plugin sigue funcionando con el filtro legacy.

== Additional Information ==

Para más información, visita [aurafact.com](https://aurafact.com) o la [documentación oficial](https://aurafact.com/docs/woocommerce).

== Soporte ==

* Documentación: https://aurafact.com/docs/woocommerce
* Issues: https://github.com/aurafact/aurafact-woocommerce/issues
* Email: support@aurafact.com
