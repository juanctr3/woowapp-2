# WooWApp Pro - Notificaciones WhatsApp/SMS para WooCommerce

**Versión:** 2.0
**Autor:** smsenlinea
**Sitio Web:** [https://smsenlinea.com](https://smsenlinea.com)
**Documentación Completa:** [https://descargas.smsenlinea.com/documentaciones/woowapp.php](https://descargas.smsenlinea.com/documentaciones/woowapp.php)
**Licencia:** GPL-2.0+

Una solución robusta para enviar notificaciones automáticas por WhatsApp o SMS a tus clientes y administradores de WooCommerce, utilizando la potente API de SMSenlinea. Además, incluye funcionalidades avanzadas como recuperación de carritos abandonados con cupones personalizables y un sistema de solicitud y recompensa por reseñas de productos.

---

## ✨ Características Principales

* **Notificaciones de Estado de Pedido:** Envía mensajes automáticos a clientes y/o administradores cuando el estado de un pedido cambia (Pendiente, Procesando, Completado, etc.).
* **Notificaciones de Nueva Nota:** Informa a los clientes cuando añades una nota a su pedido.
* **🛒 Recuperación de Carrito Abandonado:**
    * Detecta automáticamente carritos abandonados.
    * Envía hasta 3 mensajes de seguimiento personalizables a intervalos definidos (minutos, horas, días).
    * Genera e incluye cupones de descuento únicos (porcentaje o monto fijo) con prefijo y validez configurables en los mensajes de recuperación.
    * Enlace de recuperación que restaura el carrito y aplica el cupón automáticamente.
    * Opción para adjuntar imagen del producto (WhatsApp).
* **⭐ Sistema de Reseñas Mejorado:**
    * Envía recordatorios automáticos a los clientes para solicitar reseñas después de un tiempo configurable.
    * Página de formulario de reseñas personalizada con imágenes de producto.
    * Las reseñas enviadas quedan pendientes de aprobación.
    * Notificación automática a administradores por WhatsApp/SMS cuando llega una reseña pendiente.
    * Envía un mensaje de agradecimiento (y opcionalmente un cupón de recompensa) al cliente *después* de que apruebes su reseña.
    * Configuración de estrellas mínimas para recibir cupón de recompensa.
* **📱 Integración Flexible con SMSenlinea:**
    * Soporte para Panel 2 (WhatsApp QR - Recomendado).
    * Soporte para Panel 1 (SMS vía Android/Gateway o WhatsApp API Clásica).
* **🔑 Sistema de Licencias y Actualizaciones:**
    * Requiere una clave de licencia (gratuita o de pago desde descargas.smsenlinea.com) para activar todas las funcionalidades de envío.
    * Actualizaciones automáticas directamente desde el panel de WordPress para usuarios con licencia activa.
* **⚙️ Herramientas Adicionales:**
    * Placeholders dinámicos para personalizar mensajes.
    * Panel de diagnóstico del plugin y compatibilidad del servidor.
    * Opción de Cron Externo (URL segura) si WP-Cron no es fiable.
    * Registro detallado de actividad (Logs).
* **🌐 Listo para Traducción:** Compatible con múltiples idiomas (incluye archivos de traducción).

---

## 📋 Requisitos

* WordPress 5.0 o superior
* WooCommerce 3.0.0 o superior (¡Debe estar activo!)
* PHP 7.3 o superior (Recomendado 7.4+)
* Una cuenta activa en [SMSenlinea](https://smsenlinea.com) (Panel 1 o Panel 2).
* Una clave de licencia válida obtenida desde [descargas.smsenlinea.com](https://descargas.smsenlinea.com/my-licenses.php).

---

## 💾 Instalación

1.  Descarga el archivo `.zip` del plugin WooWApp Pro desde [descargas.smsenlinea.com](https://descargas.smsenlinea.com/plugin/plugin-whatsapp-wordpress-woowapp/).
2.  Ve a tu panel de WordPress: **Plugins > Añadir nuevo > Subir plugin**.
3.  Selecciona el archivo `.zip` descargado y haz clic en **Instalar ahora**.
4.  Una vez instalado, haz clic en **Activar plugin**.

---

## 🔑 Activación de Licencia (¡Importante!)

1.  Después de activar el plugin, serás redirigido a **Ajustes > WooWApp Pro Licencia** (o puedes ir manualmente).
2.  Pega la **Clave de Licencia** que obtuviste de `descargas.smsenlinea.com` en el campo correspondiente.
3.  Haz clic en **"Guardar Cambios y Activar"**.
4.  Verifica que el estado muestre "Tu licencia está activa".

**Nota:** El envío de mensajes (incluida la prueba) no funcionará hasta que la licencia esté activa.

---

## ⚙️ Configuración

La configuración principal se encuentra en **WooCommerce > Ajustes > WooWApp**. La página está dividida en pestañas:

1.  **Administración:**
    * Conecta el plugin con tu cuenta de SMSenlinea (Panel 1 o 2).
    * Configura el código de país predeterminado (fallback).
    * Activa/desactiva logs y adjuntar imágenes.
    * Encuentra la URL segura para el Cron Externo (si la necesitas).
    * Envía un mensaje de prueba.
    * Accede a la Documentación Completa.
2.  **Mensajes Admin:** Configura qué notificaciones (cambios de estado, reseñas pendientes) quieres recibir tú o tu equipo y personaliza los mensajes. No olvides añadir los números de teléfono de los administradores.
3.  **Mensajes Cliente:** Configura qué notificaciones (cambios de estado, notas) recibirán tus clientes y personaliza los mensajes.
4.  **Notificaciones:** Aquí configuras las funciones avanzadas:
    * **Recordatorio de Reseña:** Activa, define el tiempo de espera, personaliza el mensaje y decide si la calificación es obligatoria.
    * **Recompensa por Reseña:** Activa el mensaje de agradecimiento (post-aprobación), personaliza el texto, y configura si quieres dar un cupón (y sus detalles). Edita el mensaje que ve el cliente al enviar la reseña.
    * **Recuperación de Carrito Abandonado:** Activa la función, decide si adjuntar imagen, y configura cada uno de los hasta 3 mensajes de seguimiento (activación, tiempo de espera, plantilla, y cupón asociado).

---

## ✨ Placeholders

Usa variables dinámicas (placeholders) como `{order_id}`, `{customer_name}`, `{cart_total}`, `{coupon_code}`, `{first_product_review_link}`, etc., en tus plantillas de mensaje. Encuentra la lista completa haciendo clic en "Variables y Emojis" debajo de cada campo de texto de plantilla en los ajustes.

---

## ⏰ Tareas Programadas (Cron)

El plugin necesita ejecutar tareas en segundo plano. Por defecto, usa WP-Cron, pero si notas retrasos en los envíos de carrito abandonado o recordatorios de reseña, te recomendamos usar la opción de **Cron Externo**:

1.  Copia la **URL de Disparo** desde la pestaña "Administración".
2.  Configura un servicio como `cron-job.org` para visitar esa URL **cada 5 o 10 minutos**.
3.  (Opcional) Desactiva WP-Cron añadiendo `define('DISABLE_WP_CRON', true);` a tu `wp-config.php`.

Consulta la [Documentación Completa](https://descargas.smsenlinea.com/documentaciones/woowapp.php) para más detalles.

---

## 📞 Soporte

Si encuentras algún problema o tienes dudas:

1.  **Revisa los Logs:** Ve a `WooCommerce > Estado > Registros` y selecciona el log `wse-pro-...`.
2.  **Consulta la Documentación:** [https://descargas.smsenlinea.com/documentaciones/woowapp.php](https://descargas.smsenlinea.com/documentaciones/woowapp.php)
3.  Contacta al soporte a través de los canales de **SMSenlinea**.

---

¡Gracias por usar WooWApp Pro!
