# Notion: notificaciones de páginas

Adaptador `src/Core/TriggerProviders/NotionWebhooks.php`; prueba sin red: `php tests/triggers/notion.php`. No modifica el nodo de acciones `notion` ni reemplaza sus alternativas de polling.

| Clave | Evento Notion | Datos específicos entregados |
| --- | --- | --- |
| `notion_webhook_page_created` | `page.created` | Página y padre por ID. |
| `notion_webhook_page_properties_updated` | `page.properties_updated` | IDs de propiedades modificadas. |
| `notion_webhook_page_content_updated` | `page.content_updated` | IDs de bloques modificados. |

Notion agrupa cambios, puede entregarlos desordenados y no incluye el contenido completo. Recuperarlo después con un nodo de acción requiere permisos de lectura de la integración sobre la página. Los eventos se deduplican por su UUID, independientemente del número de intento. La ventana local de 25 horas admite el último reintento oficial, aproximadamente a las 24 horas. No garantiza una notificación por cada edición. [Eventos y entrega](https://developers.notion.com/reference/webhooks-events-delivery).

Preparar un receptor borrador con URL pública HTTPS, `workspace_id` e `integration_id` UUID; `subscription_id` opcional permite restringir también la suscripción. Tras autorización del usuario, crear en el panel de conexiones Notion una suscripción con el callback y el evento elegido. No se registró ninguna suscripción real durante la implementación.

Notion envía primero un POST sin firma que contiene `verification_token`. La infraestructura solo permite capturarlo en un receptor borrador mediante el indicador interno `setup_allowed`; lo almacena cifrado y permite consultarlo exclusivamente al administrador autenticado. El ACK público es vacío y el handshake jamás crea un evento de workflow. El candidato inicial **no demuestra autenticidad**: comprobarlo y verificarlo en el panel Notion antes de confiar en él. Guardarlo en una credencial `generic_bearer`, API Key JSON `{"verification_token":"secret_TOKEN_FICTICIO"}`, asociarla al receptor y activar. El token de API de Notion no sustituye este secreto. [Alta y verificación](https://developers.notion.com/reference/webhooks).

El adaptador exige `X-Notion-Signature: sha256=…`, HMAC-SHA256 del cuerpo original, y compara con `hash_equals`. Luego valida workspace, integración, suscripción configurada, UUID, tipo de entidad y fecha. Los datos llevan solo metadatos permitidos; no hay cuerpo original en historial. El core confirma recepción después del almacenamiento duradero y ejecuta después desde la cola. No se realiza ninguna llamada de red en este adaptador.

La captura usa el resultado privado `setup_secret`, que el core debe consumir y retirar de la respuesta pública. Una recepción firmada de otro tipo se confirma sin evento; una firma incorrecta, cuerpo alterado o receptor ajeno se rechaza. La prueba aislada cubre esas rutas y la ventana temporal. La persistencia, consulta administrativa del bootstrap y ejecución posterior se verifican en las pruebas compartidas. Estado de entrega: implementación lista para configuración externa; requiere despliegue, HTTPS, credenciales y alta/verificación manual. No hay renovación periódica local de suscripción. La baja externa se realiza en Notion cuando se retire el receptor.
