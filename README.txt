=== Aurafact WooCommerce ===
Contributors: aurafact
Tags: facturación electrónica, SRI, Ecuador, factura, WooCommerce, Aurafact, blocks, classic
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.1
WC tested up to: 11.1
Stable tag: 1.2.0
License: GPL v3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Facturación electrónica ecuatoriana para WooCommerce. Conecta tu tienda con Aurafact y cumple con el SRI.

== Description ==

**Aurafact WooCommerce** integra tu tienda WooCommerce con la plataforma de facturación electrónica Aurafact, permitiéndote emitir comprobantes electrónicos (facturas, notas de crédito, retenciones) automáticamente al realizar pedidos, cumpliendo con los requisitos del SRI (Servicio de Rentas Internas) de Ecuador.

= Características principales =

* Emisión automática de facturas electrónicas al completar pedidos
* Mapeo automático de tasas WC a códigos SRI (IVA 0%, 5%, 12%, 15%) con cache de 1h
* Soporte para "Precios con IVA incluido" (woocommerce_prices_include_tax)
* Auto-configuración opcional de clases de impuestos estándar en WC
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
8. (Opcional) Activa "Auto-configurar impuestos al activar" para crear las clases estándar.
9. Guarda los cambios.

== Configuración de Impuestos ==

Aurafact usa los códigos oficiales del SRI Ecuador. Para que el plugin funcione correctamente, configura las clases de impuestos en WooCommerce.

= Paso 1: Configurar clases de impuestos en WC =

Ve a `WooCommerce → Ajustes → Impuesto → Pestaña "Clases adicionales"`:

1. **Estándar**: clase para productos con IVA 15% (tarifa general Ecuador)
   - Tarifa: 15.0000
   - Nombre: "IVA"
2. **Tasa cero**: clase para productos exentos
   - Tarifa: 0.0000
   - Nombre: "IVA 0%"
3. **Tasa reducida**: clase para productos con IVA reducido
   - Tarifa: 5.0000 o 12.0000
   - Nombre: "IVA Reducido"

= Paso 2: Asignar clase a cada producto =

En la edición de producto, campo "Clase de impuesto":
* Estándar (default)
* Tasa cero
* Tasa reducida

= Paso 3: Activar "Auto-configurar" (opcional) =

En `WooCommerce → Ajustes → Aurafact`, activa "Auto-configurar impuestos al activar" para crear las clases automáticamente al activar el plugin. El plugin detecta si las clases ya existen y nunca pisa configuración existente.

= Mapeo automático de tasas WC a códigos SRI =

El plugin consulta el endpoint `GET /api/v1/sri-parameters` del backend Aurafact (con cache de 1h) para traducir porcentajes WC a códigos SRI:

| Tasa WC | Código SRI (codigoTarifa) |
|---------|---------------------------|
| 0%      | 0 (IVA 0%)               |
| 5%      | 8 (IVA 5%)               |
| 12%     | 2 (IVA 12%)              |
| 15%     | 4 (IVA 15%)              |

Si tu tasa WC no coincide exactamente, el plugin intenta un match aproximado (0.01% de tolerancia). Si tampoco hay match, notifica al admin vía nota de orden y bloquea la emisión.

= Precios con IVA incluido =

Si tu tienda usa "Precios con IVA incluido" (`woocommerce_prices_include_tax = yes`), el plugin calcula automáticamente la base imponible (sin IVA) antes de enviar al backend, evitando doble cálculo de IVA.

== Frequently Asked Questions ==

= ¿Qué es Aurafact? =

Aurafact es una plataforma de facturación electrónica ecuatoriana que te permite emitir comprobantes electrónicos (facturas, notas de crédito, retenciones, guías de remisión) cumpliendo con los requisitos del SRI.

= ¿Necesito una cuenta en Aurafact? =

Sí. Debes crear una cuenta en [aurafact.com](https://aurafact.com) y generar una API Key desde el panel de administración para poder usar este plugin.

= ¿Qué comprobantes se emiten?=

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

= ¿Qué pasa si mi tasa WC no está en los códigos SRI? =

El plugin intenta mapear primero de forma exacta (15% → "4"), luego aproximada (tolerancia 0.01%). Si no hay match, agrega una nota a la orden y NO emite la factura. La configuración correcta de las clases de impuestos en WC evita este problema. Si necesitas una tarifa no soportada por el SRI (ej: 13%), contacta a soporte para agregarla al catálogo del backend.

= ¿Cómo fuerzo la recarga de los parámetros SRI? =

Ve a `WooCommerce → Ajustes → Aurafact` y haz clic en el botón "Limpiar cache de parámetros SRI" en la sección "Estado del sistema". El cache también se limpia automáticamente cada vez que guardas la configuración.

== Screenshots ==

1. Pantalla de configuración en WooCommerce > Ajustes > Aurafact.
2. Sección de prueba de conexión y estado del sistema.
3. Campos fiscales en el checkout.
4. Configuración de clases de impuestos en WooCommerce > Ajustes > Impuesto.

== Changelog ==

= 1.2.0 =
* **Nuevo**: Endpoint backend `GET /api/v1/sri-parameters` para exponer parámetros SRI vigentes.
* **Nuevo**: Clase `SriMapper` en el plugin con cache de 1h vía transient de WP.
* **Nuevo**: Mapeo automático de porcentaje WC → código SRI (0%→"0", 5%→"8", 12%→"2", 15%→"4").
* **Nuevo**: Soporte para "Precios con IVA incluido" (extrae base imponible antes de enviar al backend).
* **Nuevo**: Setting "Auto-configurar impuestos al activar" que crea las clases Estándar, Tasa cero y Tasa reducida (idempotente).
* **Nuevo**: Botón "Limpiar cache de parámetros SRI" en admin para forzar recarga.
* **Mejorado**: Validación pre-emisión con mensajes claros en notas de orden cuando no hay mapeo.
* **Mejorado**: Cálculo de tarifa efectiva para shipping (antes hardcoded a 15%).
* **Documentación**: Sección "Configuración de Impuestos" con paso-a-paso en este README.

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

= 1.2.0 =
Recomendado: si tienes productos con clases de impuestos distintas a la estándar, revisa la nueva sección "Configuración de Impuestos" de este README para asegurar el mapeo correcto al SRI. Activa la opción "Auto-configurar impuestos al activar" si quieres que el plugin cree las clases automáticamente.

== Additional Information ==

Para más información, visita [aurafact.com](https://aurafact.com) o la [documentación oficial](https://aurafact.com/docs/woocommerce).

== Soporte ==

* Documentación: https://aurafact.com/docs/woocommerce
* Issues: https://github.com/aurafact/aurafact-woocommerce/issues
* Email: support@aurafact.com
