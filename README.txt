=== Aurafact WooCommerce ===
Contributors: aurafact
Donate link: https://aurafact.com
Tags: facturación electrónica, SRI, Ecuador, factura, WooCommerce, Aurafact
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Installation ==

1. Asegúrate de tener WooCommerce instalado y activo.
2. Sube la carpeta `aurafact-woocommerce` a `/wp-content/plugins/` o instala el archivo .zip desde Plugins > Añadir nuevo.
3. Activa el plugin desde el panel de Plugins.
4. Ve a WooCommerce > Ajustes > Aurafact.
5. Ingresa tu API Key de Aurafact y selecciona el ambiente (Sandbox o Producción).
6. Haz clic en "Probar conexión" para verificar la configuración.
7. Guarda los cambios.

== Frequently Asked Questions ==

= ¿Qué es Aurafact? =

Aurafact es una plataforma de facturación electrónica ecuatoriana que te permite emitir comprobantes electrónicos (facturas, notas de crédito, retenciones, guías de remisión) cumpliendo con los requisitos del SRI.

= ¿Necesito una cuenta en Aurafact? =

Sí. Debes crear una cuenta en [aurafact.com](https://aurafact.com) y generar una API Key desde el panel de administración para poder usar este plugin.

= ¿Qué comprobantes se emiten? =

Actualmente se emiten facturas electrónicas automáticamente al completar pedidos. Próximamente se agregarán notas de crédito, retenciones y guías de remisión.

= ¿El plugin funciona en modo de prueba? =

Sí. Puedes seleccionar el ambiente "Sandbox (Pruebas)" en la configuración para emitir comprobantes de prueba sin afectar tu contabilidad real.

== Changelog ==

= 1.0.0 =
* Lanzamiento inicial.
* Integración con WooCommerce.
* Configuración de API Key, ambiente y evento de emisión.
* Prueba de conexión a la API de Aurafact.
* Sistema de actualizaciones automáticas vía GitHub Releases.
* Traducciones base (archivo .pot).

== Upgrade Notice ==

= 1.0.0 =
Versión inicial del plugin.

== Screenshots ==

1. Pantalla de configuración en WooCommerce > Ajustes > Aurafact.
2. Sección de prueba de conexión y estado del sistema.

== Additional Information ==

Para más información, visita [aurafact.com](https://aurafact.com) o la [documentación oficial](https://aurafact.com/docs/woocommerce).